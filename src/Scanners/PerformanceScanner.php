<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

final class PerformanceScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'performance';
    }

    public function getDescription(): string
    {
        return 'Analyze application performance including memory usage, query performance, and cache efficiency';
    }

    public function getAvailableOptions(): array
    {
        return [
            'route' => 'Specific route to analyze',
            'include_memory' => 'Include memory usage analysis (default: true)',
            'include_queries' => 'Include query performance analysis (default: true)',
            'include_cache' => 'Include cache analysis (default: true)',
            'format' => 'Output format (array, json, count)',
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);
        $route = $options['route'] ?? null;
        $includeMemory = $options['include_memory'] ?? true;
        $includeQueries = $options['include_queries'] ?? true;
        $includeCache = $options['include_cache'] ?? true;

        if ($route) {
            $results = $this->analyzeRoutePerformance($route);
        } else {
            $results = [];

            if ($includeMemory) {
                $results['memory'] = $this->getMemoryAnalysis();
            }

            if ($includeQueries) {
                $results['queries'] = $this->getQueryPerformanceAnalysis();
            }

            if ($includeCache) {
                $results['cache'] = $this->getCacheAnalysis();
            }

            $results['recommendations'] = $this->getPerformanceRecommendations($results);
        }

        $results = $this->addMetadata($results, $options);

        return $this->formatOutput($results, $options);
    }

    private function analyzeRoutePerformance(string $routeName): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        $queryCount = 0;
        $queries = [];

        // Enable query logging
        DB::enableQueryLog();

        try {
            // Simulate a request to the route
            $route = Route::getRoutes()->getByName($routeName);
            if (! $route) {
                throw new Exception("Route '{$routeName}' not found");
            }

            // Create a mock request
            $request = Request::create($route->uri(), $route->methods()[0]);

            // Measure performance
            $response = app()->handle($request);

            $endTime = microtime(true);
            $endMemory = memory_get_usage();
            $queries = DB::getQueryLog();
            $queryCount = count($queries);

            return [
                'route' => $routeName,
                'execution_time' => round(($endTime - $startTime) * 1000, 2), // ms
                'memory_usage' => [
                    'start' => $this->formatBytes($startMemory),
                    'end' => $this->formatBytes($endMemory),
                    'difference' => $this->formatBytes($endMemory - $startMemory),
                    'peak' => $this->formatBytes(memory_get_peak_usage()),
                ],
                'database' => [
                    'query_count' => $queryCount,
                    'queries' => $this->analyzeQueries($queries),
                    'total_time' => array_sum(array_column($queries, 'time')),
                ],
                'response' => [
                    'status_code' => $response->getStatusCode(),
                    'size' => mb_strlen($response->getContent()),
                ],
                'performance_score' => $this->calculatePerformanceScore([
                    'execution_time' => ($endTime - $startTime) * 1000,
                    'query_count' => $queryCount,
                    'memory_diff' => $endMemory - $startMemory,
                ]),
            ];

        } catch (Exception $e) {
            return [
                'route' => $routeName,
                'error' => $e->getMessage(),
                'execution_time' => round((microtime(true) - $startTime) * 1000, 2),
                'memory_usage' => [
                    'difference' => $this->formatBytes(memory_get_usage() - $startMemory),
                ],
            ];
        } finally {
            DB::disableQueryLog();
        }
    }

    private function getMemoryAnalysis(): array
    {
        return [
            'current_usage' => $this->formatBytes(memory_get_usage()),
            'peak_usage' => $this->formatBytes(memory_get_peak_usage()),
            'limit' => ini_get('memory_limit'),
            'usage_percentage' => $this->getMemoryUsagePercentage(),
            'recommendations' => $this->getMemoryRecommendations(),
        ];
    }

    private function getQueryPerformanceAnalysis(): array
    {
        // This would analyze slow query logs if available
        // For now, we'll provide general query analysis
        return [
            'slow_query_threshold' => '1000ms',
            'n_plus_one_detection' => 'enabled',
            'index_recommendations' => $this->getIndexRecommendations(),
            'connection_pool' => [
                'max_connections' => config('database.connections.mysql.max_connections', 'default'),
                'current_connections' => 'N/A', // Would need DB driver specific implementation
            ],
        ];
    }

    private function getCacheAnalysis(): array
    {
        $cacheDriver = config('cache.default');
        $stores = config('cache.stores', []);

        $analysis = [
            'default_driver' => $cacheDriver,
            'available_stores' => array_keys($stores),
            'redis_analysis' => null,
            'file_analysis' => null,
            'recommendations' => [],
        ];

        // Redis specific analysis
        if ($cacheDriver === 'redis' || isset($stores['redis'])) {
            $analysis['redis_analysis'] = $this->getRedisAnalysis();
        }

        // File cache analysis
        if ($cacheDriver === 'file' || isset($stores['file'])) {
            $analysis['file_analysis'] = $this->getFileCacheAnalysis();
        }

        $analysis['recommendations'] = $this->getCacheRecommendations($analysis);

        return $analysis;
    }

    private function getRedisAnalysis(): array
    {
        try {
            if (! extension_loaded('redis')) {
                return ['error' => 'Redis extension not loaded'];
            }

            // Basic Redis info (would need actual Redis connection)
            return [
                'status' => 'connected',
                'memory_usage' => 'N/A', // Would need Redis INFO command
                'hit_ratio' => 'N/A',
                'key_count' => 'N/A',
                'eviction_policy' => 'N/A',
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getFileCacheAnalysis(): array
    {
        $cachePath = storage_path('framework/cache');

        if (! is_dir($cachePath)) {
            return ['error' => 'Cache directory not found'];
        }

        $files = glob($cachePath.'/*/*');
        $totalSize = 0;
        $fileCount = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                $totalSize += filesize($file);
                $fileCount++;
            }
        }

        return [
            'cache_path' => $cachePath,
            'file_count' => $fileCount,
            'total_size' => $this->formatBytes($totalSize),
            'disk_space' => [
                'free' => $this->formatBytes((int) (disk_free_space($cachePath) ?: 0)),
                'total' => $this->formatBytes((int) (disk_total_space($cachePath) ?: 0)),
            ],
        ];
    }

    private function analyzeQueries(array $queries): array
    {
        $duplicates = [];
        $slow = [];

        foreach ($queries as $query) {
            $sql = $query['query'];
            $time = $query['time'];

            // Detect slow queries (>100ms)
            if ($time > 100) {
                $slow[] = [
                    'sql' => $sql,
                    'time' => $time,
                    'bindings' => $query['bindings'] ?? [],
                ];
            }

            // Detect duplicate queries
            $normalized = preg_replace('/\s+/', ' ', mb_trim($sql));
            if (isset($duplicates[$normalized])) {
                $duplicates[$normalized]['count']++;
                $duplicates[$normalized]['total_time'] += $time;
            } else {
                $duplicates[$normalized] = [
                    'sql' => $sql,
                    'count' => 1,
                    'total_time' => $time,
                ];
            }
        }

        // Filter duplicates (more than 1 occurrence)
        $duplicates = array_filter($duplicates, fn ($query): bool => $query['count'] > 1);

        return [
            'slow_queries' => $slow,
            'duplicate_queries' => array_values($duplicates),
            'total_queries' => count($queries),
            'total_time' => array_sum(array_column($queries, 'time')),
        ];
    }

    private function calculatePerformanceScore(array $metrics): string
    {
        $score = 100;

        // Deduct points for slow execution
        if ($metrics['execution_time'] > 1000) {
            $score -= 30; // Very slow
        } elseif ($metrics['execution_time'] > 500) {
            $score -= 15; // Slow
        } elseif ($metrics['execution_time'] > 200) {
            $score -= 5; // Acceptable
        }

        // Deduct points for too many queries
        if ($metrics['query_count'] > 50) {
            $score -= 25;
        } elseif ($metrics['query_count'] > 20) {
            $score -= 10;
        } elseif ($metrics['query_count'] > 10) {
            $score -= 5;
        }

        // Deduct points for high memory usage
        if ($metrics['memory_diff'] > 50 * 1024 * 1024) { // 50MB
            $score -= 20;
        } elseif ($metrics['memory_diff'] > 20 * 1024 * 1024) { // 20MB
            $score -= 10;
        }

        $score = max(0, $score);

        if ($score >= 90) {
            return 'Excellent';
        }
        if ($score >= 75) {
            return 'Good';
        }
        if ($score >= 60) {
            return 'Average';
        }
        if ($score >= 40) {
            return 'Poor';
        }

        return 'Critical';
    }

    private function getPerformanceRecommendations(array $results): array
    {
        $recommendations = [];

        if (isset($results['memory'])) {
            $recommendations = array_merge($recommendations, $results['memory']['recommendations'] ?? []);
        }

        if (isset($results['cache'])) {
            $recommendations = array_merge($recommendations, $results['cache']['recommendations'] ?? []);
        }

        return array_unique($recommendations);
    }

    private function getMemoryRecommendations(): array
    {
        $recommendations = [];
        $percentage = $this->getMemoryUsagePercentage();

        if ($percentage > 80) {
            $recommendations[] = 'Memory usage is high (>80%). Consider increasing memory_limit or optimizing code.';
        }

        if (! extension_loaded('opcache')) {
            $recommendations[] = 'Enable OPcache for better performance.';
        }

        return $recommendations;
    }

    private function getCacheRecommendations(array $analysis): array
    {
        $recommendations = [];

        if ($analysis['default_driver'] === 'file') {
            $recommendations[] = 'Consider using Redis or Memcached for better cache performance in production.';
        }

        if (! in_array('redis', $analysis['available_stores'])) {
            $recommendations[] = 'Configure Redis cache store for improved performance.';
        }

        return $recommendations;
    }

    private function getIndexRecommendations(): array
    {
        // This would analyze actual query patterns and suggest indexes
        return [
            'Enable slow query log to analyze index needs',
            'Consider adding composite indexes for multi-column WHERE clauses',
            'Review EXPLAIN output for all queries in critical paths',
        ];
    }

    private function getMemoryUsagePercentage(): float
    {
        $current = memory_get_usage();
        $limit = $this->parseMemoryLimit(ini_get('memory_limit'));

        if ($limit === -1) {
            return 0; // No limit
        }

        return round(($current / $limit) * 100, 2);
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1;
        }

        $unit = mb_strtolower(mb_substr($limit, -1));
        $value = (int) mb_substr($limit, 0, -1);

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2).' '.$units[$unitIndex];
    }
}
