<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\LaravelDevtoolboxServiceProvider;
use Orchestra\Testbench\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Configure the package for testing
uses()->beforeEach(function (): void {
    $this->app->register(LaravelDevtoolboxServiceProvider::class);
})->in('Feature', 'Unit');

uses()->group('mcp')->in('Unit/Mcp', 'Feature/Mcp');

// laravel/mcp's own service provider is normally registered via Composer package
// auto-discovery in a real application; Testbench does not discover dev
// dependencies for a plain Orchestra\Testbench\TestCase, so register it
// explicitly for the tests that exercise the real MCP request lifecycle.
uses()->beforeEach(function (): void {
    if (class_exists(Laravel\Mcp\Server\McpServiceProvider::class)) {
        $this->app->register(Laravel\Mcp\Server\McpServiceProvider::class);
    }
})->in('Feature/Mcp');
