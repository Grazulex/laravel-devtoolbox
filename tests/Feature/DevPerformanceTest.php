<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Grazulex\LaravelDevtoolbox\Scanners\PerformanceScanner;

describe('Performance Scanner', function (): void {
    test('performance scanner is registered', function (): void {
        $manager = $this->app->make(DevtoolboxManager::class);
        $availableScanners = $manager->availableScanners();

        expect($availableScanners)->toContain('performance');
    });

    test('performance scanner can be resolved', function (): void {
        $manager = $this->app->make(DevtoolboxManager::class);
        $scanner = $manager->registry()->get('performance');

        expect($scanner)->toBeInstanceOf(PerformanceScanner::class);
    });

    test('performance scanner has correct name and description', function (): void {
        $scanner = new PerformanceScanner($this->app);

        expect($scanner->getName())->toBe('performance');
        expect($scanner->getDescription())->toContain('performance');
    });

    test('performance scanner returns valid structure', function (): void {
        $manager = $this->app->make(DevtoolboxManager::class);

        $result = $manager->scan('performance', [
            'include_memory' => true,
            'include_queries' => false,
            'include_cache' => false,
        ]);

        expect($result)->toHaveKeys(['type', 'timestamp', 'options', 'metadata', 'data']);
        expect($result['type'])->toBe('performance');
        expect($result['data'])->toHaveKey('memory');
    });

    test('memory analysis contains expected data', function (): void {
        $manager = $this->app->make(DevtoolboxManager::class);

        $result = $manager->scan('performance', [
            'include_memory' => true,
            'include_queries' => false,
            'include_cache' => false,
        ]);

        $memory = $result['data']['memory'];

        expect($memory)->toHaveKeys([
            'current_usage',
            'peak_usage',
            'limit',
            'usage_percentage',
            'recommendations',
        ]);

        expect($memory['usage_percentage'])->toBeFloat();
        expect($memory['recommendations'])->toBeArray();
    });

    test('cache analysis contains expected data', function (): void {
        $manager = $this->app->make(DevtoolboxManager::class);

        $result = $manager->scan('performance', [
            'include_memory' => false,
            'include_queries' => false,
            'include_cache' => true,
        ]);

        $cache = $result['data']['cache'];

        expect($cache)->toHaveKeys([
            'default_driver',
            'available_stores',
            'recommendations',
        ]);

        expect($cache['default_driver'])->toBeString();
        expect($cache['available_stores'])->toBeArray();
        expect($cache['recommendations'])->toBeArray();
    });

    test('query analysis contains expected data', function (): void {
        $manager = $this->app->make(DevtoolboxManager::class);

        $result = $manager->scan('performance', [
            'include_memory' => false,
            'include_queries' => true,
            'include_cache' => false,
        ]);

        $queries = $result['data']['queries'];

        expect($queries)->toHaveKeys([
            'slow_query_threshold',
            'n_plus_one_detection',
            'index_recommendations',
            'connection_pool',
        ]);

        expect($queries['index_recommendations'])->toBeArray();
    });

    test('performance recommendations are generated', function (): void {
        $manager = $this->app->make(DevtoolboxManager::class);

        $result = $manager->scan('performance');

        expect($result['data'])->toHaveKey('recommendations');
        expect($result['data']['recommendations'])->toBeArray();
    });
});
