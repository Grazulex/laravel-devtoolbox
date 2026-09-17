<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Mcp\McpRegistration;
use Laravel\Mcp\Facades\Mcp;

it('registers by default in the testing environment', function (): void {
    expect(McpRegistration::shouldRegister($this->app))->toBeTrue()
        ->and(Mcp::getLocalServer('devtoolbox'))->not->toBeNull();
});

it('does not register when disabled', function (): void {
    config(['devtoolbox.mcp.enabled' => false]);

    expect(McpRegistration::shouldRegister($this->app))->toBeFalse();
});

it('does not register outside allowed environments', function (): void {
    config(['devtoolbox.mcp.environments' => ['local']]);

    expect(McpRegistration::isEnvironmentAllowed($this->app))->toBeFalse()
        ->and(McpRegistration::shouldRegister($this->app))->toBeFalse();
});

it('does not register without laravel/mcp', function (): void {
    expect(McpRegistration::shouldRegister($this->app, mcpInstalled: false))->toBeFalse();
});
