<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\Console\Concerns\HandlesJsonSerialization;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevPerformanceSlowQueriesCommand extends Command
{
    use HandlesJsonSerialization;

    protected $signature = 'dev:performance:slow-queries 
                            {--threshold=1000 : Threshold in milliseconds to consider a query slow}
                            {--route= : Analyze queries for a specific route}
                            {--limit=20 : Maximum number of slow queries to display}
                            {--duplicates : Also show duplicate queries}
                            {--format=table : Output format (table, json)}
                            {--output= : Save output to file}';

    protected $description = 'Detect and analyze slow database queries';

    public function handle(DevtoolboxManager $devtoolbox): int
    {
        $threshold = (float) $this->option('threshold');
        $route = $this->option('route');
        $limit = (int) $this->option('limit');
        $showDuplicates = $this->option('duplicates');
        $format = $this->option('format');
        $output = $this->option('output');

        // Only show progress message if not outputting JSON directly
        if ($format !== 'json') {
            if ($route) {
                $this->info("Analyzing slow queries for route: {$route} (threshold: {$threshold}ms)");
            } else {
                $this->info("Analyzing slow query patterns (threshold: {$threshold}ms)");
            }
        }

        try {
            $options = [
                'format' => $format,
                'threshold' => $threshold,
                'limit' => $limit,
                'show_duplicates' => $showDuplicates,
                'include_memory' => false,
                'include_cache' => false,
                'include_queries' => true,
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

            $this->displayQueryResults($result, $threshold, $showDuplicates);

            return 0;
        } catch (Exception $e) {
            if ($format === 'json') {
                $this->outputJson(['error' => $e->getMessage()]);

                return 1;
            }

            $this->error('Failed to analyze slow queries: '.$e->getMessage());

            return 1;
        }
    }

    private function displayQueryResults(array $result, float $threshold, bool $showDuplicates): void
    {
        if (isset($result['data']['database'])) {
            $this->displayDatabaseAnalysis($result['data']['database'], $threshold, $showDuplicates);
        } elseif (isset($result['data']['queries'])) {
            $this->displayGeneralQueryAnalysis($threshold);
        }

        if (isset($result['data']['recommendations'])) {
            $this->displayRecommendations($result['data']['recommendations']);
        }

        if (isset($result['metadata'])) {
            $this->displayMetadata($result['metadata']);
        }
    }

    private function displayDatabaseAnalysis(array $database, float $threshold, bool $showDuplicates): void
    {
        $this->newLine();
        $this->info('📊 Database Query Analysis');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Summary
        $queries = $database['queries'] ?? [];
        $slowQueries = $queries['slow_queries'] ?? [];
        $duplicateQueries = $queries['duplicate_queries'] ?? [];
        $totalQueries = $queries['total_queries'] ?? 0;
        $totalTime = $queries['total_time'] ?? 0;

        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Total Queries', $totalQueries, $this->getQueryCountStatus($totalQueries)],
                ['Slow Queries (>'.$threshold.'ms)', count($slowQueries), $this->getSlowQueryStatus(count($slowQueries))],
                ['Duplicate Queries', count($duplicateQueries), $this->getDuplicateStatus(count($duplicateQueries))],
                ['Total Query Time', round($totalTime, 2).' ms', $this->getTotalTimeStatus($totalTime)],
            ]
        );

        // Show slow queries
        if (! empty($slowQueries)) {
            $this->displaySlowQueries($slowQueries);
        }

        // Show duplicate queries if requested
        if ($showDuplicates && ! empty($duplicateQueries)) {
            $this->displayDuplicateQueries($duplicateQueries);
        }
    }

    private function displayGeneralQueryAnalysis(float $threshold): void
    {
        $this->newLine();
        $this->info('📊 General Query Performance Analysis');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $this->table(
            ['Analysis', 'Recommendation'],
            [
                ['Slow Query Threshold', "Set to {$threshold}ms - consider enabling slow query log"],
                ['N+1 Detection', 'Monitor for duplicate queries with different parameters'],
                ['Index Analysis', 'Review EXPLAIN output for queries in critical paths'],
                ['Connection Pool', 'Optimize max_connections based on your workload'],
            ]
        );
    }

    private function displaySlowQueries(array $slowQueries): void
    {
        $this->newLine();
        $this->error('🐌 Slow Queries Detected');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        foreach ($slowQueries as $index => $query) {
            $this->line(sprintf(
                '<fg=red>%d.</> <fg=yellow>%s ms</> %s',
                $index + 1,
                $query['time'],
                $this->truncateQuery($query['sql'])
            ));

            if (! empty($query['bindings'])) {
                $this->line('   Bindings: '.json_encode($query['bindings']));
            }
            $this->newLine();
        }
    }

    private function displayDuplicateQueries(array $duplicateQueries): void
    {
        $this->newLine();
        $this->warn('🔄 Duplicate Queries (Possible N+1 Problem)');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        foreach ($duplicateQueries as $index => $query) {
            $this->line(sprintf(
                '<fg=yellow>%d.</> <fg=red>%dx</> executed (%s ms total) %s',
                $index + 1,
                $query['count'],
                round($query['total_time'], 2),
                $this->truncateQuery($query['sql'])
            ));
            $this->newLine();
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

    private function getQueryCountStatus(int $count): string
    {
        if ($count > 50) {
            return '<fg=red>High</>';
        }
        if ($count > 20) {
            return '<fg=yellow>Moderate</>';
        }
        if ($count > 10) {
            return '<fg=blue>Low</>';
        }

        return '<fg=green>Minimal</>';
    }

    private function getSlowQueryStatus(int $count): string
    {
        if ($count > 5) {
            return '<fg=red>Critical</>';
        }
        if ($count > 2) {
            return '<fg=yellow>Warning</>';
        }
        if ($count > 0) {
            return '<fg=blue>Attention</>';
        }

        return '<fg=green>Good</>';
    }

    private function getDuplicateStatus(int $count): string
    {
        if ($count > 10) {
            return '<fg=red>N+1 Problem</>';
        }
        if ($count > 5) {
            return '<fg=yellow>Possible N+1</>';
        }
        if ($count > 0) {
            return '<fg=blue>Some Duplicates</>';
        }

        return '<fg=green>None</>';
    }

    private function getTotalTimeStatus(float $time): string
    {
        if ($time > 5000) {
            return '<fg=red>Very Slow</>';
        }
        if ($time > 2000) {
            return '<fg=yellow>Slow</>';
        }
        if ($time > 1000) {
            return '<fg=blue>Moderate</>';
        }

        return '<fg=green>Fast</>';
    }

    private function truncateQuery(string $sql, int $length = 100): string
    {
        $sql = preg_replace('/\s+/', ' ', mb_trim($sql));

        if (mb_strlen($sql) <= $length) {
            return $sql;
        }

        return mb_substr($sql, 0, $length).'...';
    }
}
