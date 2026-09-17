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
