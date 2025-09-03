<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\Console\Concerns\HandlesJsonSerialization;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevCacheAnalysisCommand extends Command
{
    use HandlesJsonSerialization;

    protected $signature = 'dev:cache:analysis 
                            {--drivers= : Comma-separated list of cache drivers to analyze (default: all)}
                            {--detailed : Show detailed cache statistics}
                            {--recommendations : Show cache optimization recommendations}
                            {--format=table : Output format (table, json)}
                            {--output= : Save output to file}';

    protected $description = 'Analyze cache performance and configuration';

    public function handle(DevtoolboxManager $devtoolbox): int
    {
        $drivers = $this->option('drivers');
        $detailed = $this->option('detailed');
        $recommendations = $this->option('recommendations');
        $format = $this->option('format');
        $output = $this->option('output');

        // Only show progress message if not outputting JSON directly
        if ($format !== 'json') {
            if ($drivers) {
                $this->info("Analyzing cache drivers: {$drivers}");
            } else {
                $this->info('Analyzing cache configuration and performance...');
            }
        }

        try {
            $options = [
                'format' => $format,
                'drivers' => $drivers ? explode(',', $drivers) : null,
                'detailed' => $detailed,
                'include_memory' => false,
                'include_queries' => false,
                'include_cache' => true,
            ];

            $result = $devtoolbox->scan('performance', $options);

            if ($output) {
                $this->outputJson($result, $output);

                return 0;
            }

            if ($format === 'json') {
                $this->outputJson($result);

                return 0;
            }

            $this->displayCacheResults($result, $detailed, $recommendations);

            return 0;
        } catch (Exception $e) {
            if ($format === 'json') {
                $this->outputJson(['error' => $e->getMessage()]);

                return 1;
            }

            $this->error('Failed to analyze cache: '.$e->getMessage());

            return 1;
        }
    }

    private function displayCacheResults(array $result, bool $detailed, bool $showRecommendations): void
    {
        if (isset($result['data']['cache'])) {
            $this->displayCacheAnalysis($result['data']['cache'], $detailed);
        }

        if ($showRecommendations && isset($result['data']['recommendations'])) {
            $this->displayRecommendations($result['data']['recommendations']);
        }

        if (isset($result['metadata'])) {
            $this->displayMetadata($result['metadata']);
        }
    }

    private function displayCacheAnalysis(array $cache, bool $detailed): void
    {
        $this->newLine();
        $this->info('💾 Cache Configuration Analysis');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Basic configuration
        $this->table(
            ['Configuration', 'Value', 'Status'],
            [
                ['Default Driver', $cache['default_driver'], $this->getDriverStatus($cache['default_driver'])],
                ['Available Stores', implode(', ', $cache['available_stores']), ''],
            ]
        );

        // Redis analysis
        if (isset($cache['redis_analysis'])) {
            $this->displayRedisAnalysis($cache['redis_analysis'], $detailed);
        }

        // File cache analysis
        if (isset($cache['file_analysis'])) {
            $this->displayFileCacheAnalysis($cache['file_analysis'], $detailed);
        }

        // Cache recommendations
        if (! empty($cache['recommendations'])) {
            $this->newLine();
            $this->warn('⚠️  Cache Configuration Issues');
            foreach ($cache['recommendations'] as $recommendation) {
                $this->line("  • {$recommendation}");
            }
        }
    }

    private function displayRedisAnalysis(array $redis, bool $detailed): void
    {
        $this->newLine();
        $this->info('🔴 Redis Cache Analysis');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (isset($redis['error'])) {
            $this->error("Redis Error: {$redis['error']}");

            return;
        }

        $statusColor = $redis['status'] === 'connected' ? 'fg=green' : 'fg=red';
        $this->line("Status: <{$statusColor}>{$redis['status']}</{$statusColor}>");

        if ($detailed && $redis['status'] === 'connected') {
            $rows = [];

            if (isset($redis['memory_usage'])) {
                $rows[] = ['Memory Usage', $redis['memory_usage']];
            }

            if (isset($redis['hit_ratio'])) {
                $rows[] = ['Hit Ratio', $redis['hit_ratio']];
            }

            if (isset($redis['key_count'])) {
                $rows[] = ['Key Count', $redis['key_count']];
            }

            if (isset($redis['eviction_policy'])) {
                $rows[] = ['Eviction Policy', $redis['eviction_policy']];
            }

            if ($rows !== []) {
                $this->table(['Metric', 'Value'], $rows);
            }
        }
    }

    private function displayFileCacheAnalysis(array $fileCache, bool $detailed): void
    {
        $this->newLine();
        $this->info('📁 File Cache Analysis');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (isset($fileCache['error'])) {
            $this->error("File Cache Error: {$fileCache['error']}");

            return;
        }

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Cache Path', $fileCache['cache_path'], ''],
                ['File Count', $fileCache['file_count'], $this->getFileCacheStatus($fileCache['file_count'])],
                ['Total Size', $fileCache['total_size'], ''],
            ]
        );

        if ($detailed && isset($fileCache['disk_space'])) {
            $this->newLine();
            $this->line('💿 Disk Space:');
            $this->table(
                ['Type', 'Size'],
                [
                    ['Free Space', $fileCache['disk_space']['free']],
                    ['Total Space', $fileCache['disk_space']['total']],
                ]
            );
        }
    }

    private function displayRecommendations(array $recommendations): void
    {
        if ($recommendations === []) {
            return;
        }

        $this->newLine();
        $this->info('💡 Performance Recommendations');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        foreach ($recommendations as $recommendation) {
            $this->line("  • {$recommendation}");
        }
    }

    private function displayMetadata(array $metadata): void
    {
        $this->newLine();
        $this->line("Scanned at: {$metadata['scanned_at']}");
        $this->line("Scanner: {$metadata['scanner']} - {$metadata['description']}");
    }

    private function getDriverStatus(string $driver): string
    {
        return match ($driver) {
            'redis' => '<fg=green>Excellent</>',
            'memcached' => '<fg=green>Very Good</>',
            'database' => '<fg=blue>Good</>',
            'file' => '<fg=yellow>Basic</>',
            'array' => '<fg=red>Development Only</>',
            default => '<fg=gray>Unknown</>'
        };
    }

    private function getFileCacheStatus(int $fileCount): string
    {
        if ($fileCount > 10000) {
            return '<fg=red>Very High - Consider cleanup</>';
        }
        if ($fileCount > 5000) {
            return '<fg=yellow>High</>';
        }
        if ($fileCount > 1000) {
            return '<fg=blue>Moderate</>';
        }

        return '<fg=green>Low</>';
    }
}
