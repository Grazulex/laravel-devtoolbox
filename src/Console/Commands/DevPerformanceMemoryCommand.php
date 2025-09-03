<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\Console\Concerns\HandlesJsonSerialization;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevPerformanceMemoryCommand extends Command
{
    use HandlesJsonSerialization;

    protected $signature = 'dev:performance:memory 
                            {--route= : Analyze memory usage for a specific route}
                            {--baseline : Show baseline memory usage without executing any route}
                            {--detailed : Show detailed memory breakdown}
                            {--format=table : Output format (table, json)}
                            {--output= : Save output to file}';

    protected $description = 'Analyze memory usage patterns and performance';

    public function handle(DevtoolboxManager $devtoolbox): int
    {
        $route = $this->option('route');
        $baseline = $this->option('baseline');
        $detailed = $this->option('detailed');
        $format = $this->option('format');
        $output = $this->option('output');

        // Only show progress message if not outputting JSON directly
        if ($format !== 'json') {
            if ($route) {
                $this->info("Analyzing memory usage for route: {$route}");
            } elseif ($baseline) {
                $this->info('Analyzing baseline memory usage...');
            } else {
                $this->info('Analyzing application memory performance...');
            }
        }

        try {
            $options = [
                'format' => $format,
                'include_queries' => false,
                'include_cache' => false,
                'include_memory' => true,
                'detailed' => $detailed,
            ];

            if ($route) {
                $options['route'] = $route;
            }

            $result = $devtoolbox->scan('performance', $options);

            if ($output) {
                $this->outputJson($result, $output);

                return 0;
            }

            if ($format === 'json') {
                $this->outputJson($result);

                return 0;
            }

            $this->displayMemoryResults($result);

            return 0;
        } catch (Exception $e) {
            if ($format === 'json') {
                $this->outputJson(['error' => $e->getMessage()]);

                return 1;
            }

            $this->error('Failed to analyze memory performance: '.$e->getMessage());

            return 1;
        }
    }

    private function displayMemoryResults(array $result): void
    {
        if (isset($result['data']['memory'])) {
            $this->displayMemoryAnalysis($result['data']['memory']);
        } elseif (isset($result['data']['memory_usage'])) {
            // Route-specific analysis
            $this->displayRouteMemoryAnalysis($result['data']);
        }

        if (isset($result['data']['recommendations'])) {
            $this->displayRecommendations($result['data']['recommendations']);
        }

        if (isset($result['metadata'])) {
            $this->displayMetadata($result['metadata']);
        }
    }

    private function displayMemoryAnalysis(array $memory): void
    {
        $this->newLine();
        $this->info('🧠 Memory Analysis');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Current Usage', $memory['current_usage'], $this->getMemoryStatus($memory['usage_percentage'])],
                ['Peak Usage', $memory['peak_usage'], ''],
                ['Memory Limit', $memory['limit'], ''],
                ['Usage Percentage', $memory['usage_percentage'].'%', $this->getUsageColor($memory['usage_percentage'])],
            ]
        );
    }

    private function displayRouteMemoryAnalysis(array $data): void
    {
        $this->newLine();
        $this->info("🚀 Route Performance: {$data['route']}");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (isset($data['error'])) {
            $this->error("Error: {$data['error']}");

            return;
        }

        // Execution time
        $executionTime = $data['execution_time'] ?? 0;
        $timeStatus = $this->getExecutionTimeStatus($executionTime);

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Execution Time', $executionTime.' ms', $timeStatus],
                ['Memory Start', $data['memory_usage']['start'] ?? 'N/A', ''],
                ['Memory End', $data['memory_usage']['end'] ?? 'N/A', ''],
                ['Memory Difference', $data['memory_usage']['difference'] ?? 'N/A', $this->getMemoryDiffStatus($data['memory_usage']['difference'] ?? '')],
                ['Peak Memory', $data['memory_usage']['peak'] ?? 'N/A', ''],
            ]
        );

        // Performance score
        if (isset($data['performance_score'])) {
            $this->newLine();
            $score = $data['performance_score'];
            $color = $this->getScoreColor($score);
            $this->line("Performance Score: <{$color}>{$score}</{$color}>");
        }

        // Database info if available
        if (isset($data['database'])) {
            $db = $data['database'];
            $this->newLine();
            $this->line('📊 Database Performance:');
            $this->line("  • Query Count: {$db['query_count']}");
            $this->line("  • Total Query Time: {$db['total_time']} ms");
        }
    }

    private function displayRecommendations(array $recommendations): void
    {
        if ($recommendations === []) {
            return;
        }

        $this->newLine();
        $this->info('💡 Recommendations');
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

    private function getMemoryStatus(float $percentage): string
    {
        if ($percentage > 90) {
            return '<fg=red>Critical</>';
        }
        if ($percentage > 75) {
            return '<fg=yellow>High</>';
        }
        if ($percentage > 50) {
            return '<fg=blue>Moderate</>';
        }

        return '<fg=green>Good</>';
    }

    private function getUsageColor(float $percentage): string
    {
        if ($percentage > 90) {
            return 'fg=red';
        }
        if ($percentage > 75) {
            return 'fg=yellow';
        }
        if ($percentage > 50) {
            return 'fg=blue';
        }

        return 'fg=green';
    }

    private function getExecutionTimeStatus(float $time): string
    {
        if ($time > 1000) {
            return '<fg=red>Very Slow</>';
        }
        if ($time > 500) {
            return '<fg=yellow>Slow</>';
        }
        if ($time > 200) {
            return '<fg=blue>Acceptable</>';
        }

        return '<fg=green>Fast</>';
    }

    private function getMemoryDiffStatus(string $diff): string
    {
        if (mb_strpos($diff, 'MB') !== false) {
            $value = (float) str_replace([' MB', ' KB', ' B'], '', $diff);
            if ($value > 50) {
                return '<fg=red>High</>';
            }
            if ($value > 20) {
                return '<fg=yellow>Moderate</>';
            }
        }

        return '<fg=green>Good</>';
    }

    private function getScoreColor(string $score): string
    {
        return match ($score) {
            'Excellent' => 'fg=green',
            'Good' => 'fg=blue',
            'Average' => 'fg=yellow',
            'Poor' => 'fg=red',
            'Critical' => 'bg=red;fg=white',
            default => 'fg=gray',
        };
    }
}
