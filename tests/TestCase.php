<?php

declare(strict_types=1);

namespace Tests;

use Grazulex\LaravelDevtoolbox\LaravelDevtoolboxServiceProvider;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

abstract class TestCase extends Orchestra
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Execute migration if needed
        // $this->artisan('migrate', ['--database' => 'testing']);
    }

    final public function debugToFile(string $content, string $context = ''): void
    {
        $file = base_path('devtoolbox_test.log');
        $tag = $context !== '' && $context !== '0' ? "=== $context ===\n" : '';
        File::append($file, $tag.$content."\n");
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup Devtoolbox specific testing environment
        $app['config']->set('devtoolbox.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelDevtoolboxServiceProvider::class,
        ];
    }
}
