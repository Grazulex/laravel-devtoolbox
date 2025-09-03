<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox;

use Grazulex\LaravelDevtoolbox\Console\Commands\DevAboutPlusCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevCacheAnalysisCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevCommandsCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevContainerBindingsCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevDbColumnUsageCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevEnvDiffCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevLogTailCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevMiddlewareCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevMiddlewaresWhereUsedCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevModelGraphCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevModelsCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevModelWhereUsedCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevPerformanceMemoryCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevPerformanceSlowQueriesCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevProvidersTimelineCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevQueueAnalysisCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevRoutesCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevRoutesUnusedCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevRoutesWhereCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevScanCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevSecurityUnprotectedRoutesCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevServicesCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevSqlDuplicatesCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevSqlTraceCommand;
use Grazulex\LaravelDevtoolbox\Console\Commands\DevViewsCommand;
use Illuminate\Support\ServiceProvider;

final class LaravelDevtoolboxServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../config/devtoolbox.php',
            'devtoolbox'
        );

        // Register the main manager
        $this->app->singleton(DevtoolboxManager::class, function ($app): DevtoolboxManager {
            return new DevtoolboxManager($app);
        });

        // Register alias
        $this->app->alias(DevtoolboxManager::class, 'devtoolbox');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/devtoolbox.php' => config_path('devtoolbox.php'),
            ], 'devtoolbox-config');

            // Register commands
            $this->commands([
                DevScanCommand::class,
                DevModelWhereUsedCommand::class,
                DevRoutesUnusedCommand::class,
                DevEnvDiffCommand::class,
                DevModelGraphCommand::class,
                DevSqlTraceCommand::class,
                DevModelsCommand::class,
                DevRoutesCommand::class,
                DevCommandsCommand::class,
                DevServicesCommand::class,
                DevMiddlewareCommand::class,
                DevViewsCommand::class,
                DevSecurityUnprotectedRoutesCommand::class,
                DevDbColumnUsageCommand::class,
                DevRoutesWhereCommand::class,
                DevLogTailCommand::class,
                DevContainerBindingsCommand::class,
                DevMiddlewaresWhereUsedCommand::class,
                DevSqlDuplicatesCommand::class,
                DevProvidersTimelineCommand::class,
                DevAboutPlusCommand::class,
                DevPerformanceMemoryCommand::class,
                DevPerformanceSlowQueriesCommand::class,
                DevCacheAnalysisCommand::class,
                DevQueueAnalysisCommand::class,
            ]);
        }

        // Register views if needed
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'devtoolbox');

        // Publish views
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/devtoolbox'),
            ], 'devtoolbox-views');
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            DevtoolboxManager::class,
            'devtoolbox',
        ];
    }
}
