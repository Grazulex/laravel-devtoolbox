<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Grazulex\LaravelDevtoolbox\Mcp\DevToolboxServer;
use Grazulex\LaravelDevtoolbox\Mcp\ToolFactory;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Testing\TestListResponse;

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
