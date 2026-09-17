<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Grazulex\LaravelDevtoolbox\Mcp\DevToolboxServer;
use Grazulex\LaravelDevtoolbox\Mcp\ToolFactory;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Testing\TestListResponse;
use Laravel\Mcp\Server\Testing\TestResponse;

function tool(string $name)
{
    $tools = app(ToolFactory::class)->make(app(DevtoolboxManager::class)->registry());

    foreach ($tools as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("Tool $name not built");
}

/**
 * @return list<array<string, mixed>>
 */
function listedTools(TestListResponse $response): array
{
    $reader = function (): array {
        /** @var array<int, array<string, mixed>> $items */
        $items = $this->items;

        return $items;
    };

    return $reader->bindTo($response, TestListResponse::class)();
}

/**
 * @return array<string, mixed>
 */
function structured(TestResponse $response): array
{
    $reader = function (): array {
        /** @var array<string, mixed> $content */
        $content = $this->structuredContent();

        return $content;
    };

    return $reader->bindTo($response, TestResponse::class)();
}

function encodedBytes(array $value): int
{
    return mb_strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), '8bit');
}

beforeEach(function (): void {
    // The testbench Kernel ships with no middleware aliases (they are normally
    // added by the application skeleton), but MiddlewareUsageScanner resolves
    // the "auth" alias against the Kernel, so register it for this test.
    $kernel = app(Kernel::class);
    $routeMiddleware = new ReflectionProperty($kernel, 'routeMiddleware');
    $routeMiddleware->setAccessible(true);
    $routeMiddleware->setValue($kernel, ['auth' => Authenticate::class]);

    Route::get('/mcp-test/users', fn (): array => ['ok' => true])->name('mcp-test.users')->middleware('auth');
    Route::get('/mcp-test/public', fn (): array => ['ok' => true])->name('mcp-test.public');
});

it('lists the sixteen devtoolbox tools', function (): void {
    $items = listedTools(DevToolboxServer::tools());

    expect($items)->toHaveCount(16)
        ->and(array_column($items, 'name'))->toContain('devtoolbox-routes');
});

it('runs the routes tool', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-routes'), ['detect_unused' => false])
        ->assertOk()
        ->assertSee('mcp-test.users');
});

it('runs the models and middleware-usage tools', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-models'), [])->assertOk();
    DevToolboxServer::tool(tool('devtoolbox-middleware-usage'), ['middleware' => 'auth', 'show_routes' => true])
        ->assertOk()
        ->assertSee('mcp-test.users');
});

it('runs the active sql-trace tool against a named route', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-sql-trace'), ['route' => 'mcp-test.public'])->assertOk();
});

it('rejects arguments of the wrong type', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-routes'), ['detect_unused' => 'yes please'])->assertHasErrors();
});

it('rejects a missing required argument', function (): void {
    DevToolboxServer::tool(tool('devtoolbox-model-usage'), [])->assertHasErrors();
});

it('truncates the routes envelope within the configured byte budget', function (): void {
    config(['devtoolbox.mcp.max_response_bytes' => 4000]);

    foreach (range(1, 60) as $i) {
        Route::get("/mcp-test/truncate/$i", fn (): array => ['ok' => true])->name('mcp-test.truncate.'.str_repeat('segment-', 8).$i);
    }
    $routeCount = count(Route::getRoutes());

    $response = DevToolboxServer::tool(tool('devtoolbox-routes'), [])->assertOk();
    $content = structured($response);

    expect($content)->toHaveKeys(['metadata', 'data', '_truncated'])
        ->and($content['metadata']['scanner'])->toBe('routes')
        ->and($content['data']['count'])->toBe($routeCount)
        ->and($content['data']['routes'])->toBeArray()->not->toBeEmpty()
        ->and(count($content['data']['routes']))->toBeLessThan($routeCount)
        ->and(array_column($content['data']['routes'], 'uri'))->toBe(array_slice(array_map(fn ($route) => $route->uri(), Route::getRoutes()->getRoutes()), 0, count($content['data']['routes'])))
        ->and($content['_truncated']['kept_items'])->toBe(count($content['data']['routes']))->toBeGreaterThan(0)
        ->and($content['_truncated']['original_items'])->toBe($routeCount)
        ->and($content['_truncated']['path'])->toBe('data.routes')
        ->and(encodedBytes($content))->toBeLessThanOrEqual(4000);
});

it('refuses every tool when the current environment is not allowed', function (): void {
    config(['devtoolbox.mcp.environments' => ['production']]);

    DevToolboxServer::tool(tool('devtoolbox-routes'), [])
        ->assertHasErrors(['disabled in this environment']);
});
