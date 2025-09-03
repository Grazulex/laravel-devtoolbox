<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox;

use Grazulex\LaravelDevtoolbox\Registry\ScannerRegistry;
use Grazulex\LaravelDevtoolbox\Scanners\CommandScanner;
use Grazulex\LaravelDevtoolbox\Scanners\ContainerBindingsScanner;
use Grazulex\LaravelDevtoolbox\Scanners\DatabaseColumnUsageScanner;
use Grazulex\LaravelDevtoolbox\Scanners\MiddlewareScanner;
use Grazulex\LaravelDevtoolbox\Scanners\MiddlewareUsageScanner;
use Grazulex\LaravelDevtoolbox\Scanners\ModelScanner;
use Grazulex\LaravelDevtoolbox\Scanners\ModelUsageScanner;
use Grazulex\LaravelDevtoolbox\Scanners\PerformanceScanner;
use Grazulex\LaravelDevtoolbox\Scanners\ProviderTimelineScanner;
use Grazulex\LaravelDevtoolbox\Scanners\RouteScanner;
use Grazulex\LaravelDevtoolbox\Scanners\RouteWhereLookupScanner;
use Grazulex\LaravelDevtoolbox\Scanners\SecurityScanner;
use Grazulex\LaravelDevtoolbox\Scanners\ServiceScanner;
use Grazulex\LaravelDevtoolbox\Scanners\SqlAnalysisScanner;
use Grazulex\LaravelDevtoolbox\Scanners\SqlTraceScanner;
use Grazulex\LaravelDevtoolbox\Scanners\ViewScanner;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

final class DevtoolboxManager
{
    private ScannerRegistry $registry;

    private Application $app;

    public function __construct(?Application $app = null)
    {
        $this->app = $app ?? app();
        $this->registry = new ScannerRegistry();
        $this->registerDefaultScanners();
    }

    /**
     * Scan using the specified scanner type
     */
    public function scan(string $type, array $options = []): array
    {
        if (! $this->registry->has($type)) {
            throw new InvalidArgumentException("No scanner registered for type [{$type}].");
        }

        $scanner = $this->registry->get($type);

        return [
            'type' => $type,
            'timestamp' => now()->toISOString(),
            'options' => $options,
            ...$scanner->scan($options),
        ];
    }

    /**
     * Get the scanner registry
     */
    public function registry(): ScannerRegistry
    {
        return $this->registry;
    }

    /**
     * Get all available scanner types
     */
    public function availableScanners(): array
    {
        return $this->registry->all();
    }

    /**
     * Scan multiple types at once
     */
    public function scanMultiple(array $types, array $options = []): array
    {
        $results = [];

        foreach ($types as $type) {
            $results[$type] = $this->scan($type, $options);
        }

        return [
            'timestamp' => now()->toISOString(),
            'scanned_types' => $types,
            'results' => $results,
        ];
    }

    /**
     * Scan all available types
     */
    public function scanAll(array $options = []): array
    {
        return $this->scanMultiple($this->availableScanners(), $options);
    }

    /**
     * Register all default scanners
     */
    private function registerDefaultScanners(): void
    {
        $this->registry->register('models', new ModelScanner($this->app));
        $this->registry->register('routes', new RouteScanner($this->app));
        $this->registry->register('route-where-lookup', new RouteWhereLookupScanner($this->app));
        $this->registry->register('container-bindings', new ContainerBindingsScanner($this->app));
        $this->registry->register('middleware-usage', new MiddlewareUsageScanner($this->app));
        $this->registry->register('sql-analysis', new SqlAnalysisScanner($this->app));
        $this->registry->register('provider-timeline', new ProviderTimelineScanner($this->app));
        $this->registry->register('commands', new CommandScanner($this->app));
        $this->registry->register('services', new ServiceScanner($this->app));
        $this->registry->register('middleware', new MiddlewareScanner($this->app));
        $this->registry->register('views', new ViewScanner($this->app));
        $this->registry->register('model-usage', new ModelUsageScanner($this->app));
        $this->registry->register('sql-trace', new SqlTraceScanner($this->app));
        $this->registry->register('security', new SecurityScanner($this->app));
        $this->registry->register('db-column-usage', new DatabaseColumnUsageScanner($this->app));
        $this->registry->register('performance', new PerformanceScanner($this->app));
    }
}
