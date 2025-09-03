<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\Console\Concerns\HandlesJsonSerialization;
use Illuminate\Console\Command;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class DevQueueAnalysisCommand extends Command
{
    use HandlesJsonSerialization;

    protected $signature = 'dev:queue:analysis 
                            {--failed-jobs : Show failed jobs analysis}
                            {--slow-jobs : Show potentially slow jobs}
                            {--queue= : Analyze specific queue (default: all)}
                            {--limit=50 : Maximum number of jobs to analyze}
                            {--format=table : Output format (table, json)}
                            {--output= : Save output to file}';

    protected $description = 'Analyze queue performance, failed jobs, and job patterns';

    public function handle(): int
    {
        $showFailedJobs = $this->option('failed-jobs');
        $showSlowJobs = $this->option('slow-jobs');
        $queueName = $this->option('queue');
        $limit = (int) $this->option('limit');
        $format = $this->option('format');
        $output = $this->option('output');

        // Only show progress message if not outputting JSON directly
        if ($format !== 'json') {
            $this->info('Analyzing queue performance and job patterns...');
        }

        try {
            $result = $this->analyzeQueues($showFailedJobs, $showSlowJobs, $queueName, $limit);

            if ($output) {
                $this->outputJson($result, $output);

                return 0;
            }

            if ($format === 'json') {
                $this->outputJson($result);

                return 0;
            }

            $this->displayQueueResults($result);

            return 0;
        } catch (Exception $e) {
            if ($format === 'json') {
                $this->outputJson(['error' => $e->getMessage()]);

                return 1;
            }

            $this->error('Failed to analyze queues: '.$e->getMessage());

            return 1;
        }
    }

    private function analyzeQueues(bool $showFailedJobs, bool $showSlowJobs, ?string $queueName, int $limit): array
    {
        $result = [
            'configuration' => $this->getQueueConfiguration(),
            'statistics' => $this->getQueueStatistics($queueName),
            'recommendations' => [],
        ];

        if ($showFailedJobs) {
            $result['failed_jobs'] = $this->getFailedJobsAnalysis($limit);
        }

        if ($showSlowJobs) {
            $result['slow_jobs'] = $this->getSlowJobsAnalysis();
        }

        $result['recommendations'] = $this->generateRecommendations($result);

        return [
            'metadata' => [
                'scanner' => 'queue-analysis',
                'description' => 'Queue performance and job pattern analysis',
                'scanned_at' => now()->toISOString(),
                'count' => array_sum(array_map('count', array_filter($result, 'is_array'))),
            ],
            'data' => $result,
        ];
    }

    private function getQueueConfiguration(): array
    {
        return [
            'default_connection' => config('queue.default'),
            'connections' => array_keys(config('queue.connections', [])),
            'failed_driver' => config('queue.failed.driver'),
            'failed_table' => config('queue.failed.table'),
            'retry_after' => config('queue.connections.'.config('queue.default').'.retry_after'),
            'max_tries' => config('queue.connections.'.config('queue.default').'.max_tries'),
        ];
    }

    private function getQueueStatistics(?string $queueName): array
    {
        $connection = config('queue.default');
        $stats = [
            'connection' => $connection,
            'pending_jobs' => 'N/A',
            'processed_jobs' => 'N/A',
            'worker_status' => 'N/A',
        ];

        // Get statistics based on queue driver
        switch ($connection) {
            case 'database':
                $stats = array_merge($stats, $this->getDatabaseQueueStats($queueName));
                break;
            case 'redis':
                $stats = array_merge($stats, $this->getRedisQueueStats());
                break;
            case 'sync':
                $stats['note'] = 'Sync driver executes jobs immediately';
                break;
        }

        return $stats;
    }

    private function getDatabaseQueueStats(?string $queueName): array
    {
        try {
            $table = config('queue.connections.database.table', 'jobs');

            $query = DB::table($table);
            if ($queueName !== null && $queueName !== '' && $queueName !== '0') {
                $query->where('queue', $queueName);
            }

            $pendingJobs = $query->count();

            // Get some basic statistics
            $oldestJob = $query->orderBy('created_at')->first();
            $newestJob = $query->orderByDesc('created_at')->first();

            return [
                'pending_jobs' => $pendingJobs,
                'oldest_job_age' => $oldestJob ? now()->diffInMinutes($oldestJob->created_at).' minutes' : 'N/A',
                'newest_job_age' => $newestJob ? now()->diffInMinutes($newestJob->created_at).' minutes' : 'N/A',
                'table' => $table,
            ];
        } catch (Exception $e) {
            return ['error' => 'Could not access jobs table: '.$e->getMessage()];
        }
    }

    private function getRedisQueueStats(): array
    {
        // Redis queue statistics would require Redis connection
        return [
            'note' => 'Redis queue statistics require Redis connection analysis',
            'pending_jobs' => 'Use Redis CLI: LLEN queue_name',
            'failed_jobs' => 'Use Redis CLI: LLEN queue_name:failed',
        ];
    }

    private function getFailedJobsAnalysis(int $limit): array
    {
        $failedJobProvider = app('queue.failer');

        if ($failedJobProvider instanceof NullFailedJobProvider) {
            return [
                'error' => 'Failed job tracking is not configured',
                'recommendation' => 'Configure failed job storage in config/queue.php',
            ];
        }

        if ($failedJobProvider instanceof DatabaseFailedJobProvider) {
            return $this->getDatabaseFailedJobsAnalysis($limit);
        }

        return ['error' => 'Unsupported failed job provider'];
    }

    private function getDatabaseFailedJobsAnalysis(int $limit): array
    {
        try {
            $table = config('queue.failed.table', 'failed_jobs');

            $failedJobs = DB::table($table)
                ->orderByDesc('failed_at')
                ->limit($limit)
                ->get();

            $analysis = [
                'total_failed' => DB::table($table)->count(),
                'recent_failures' => $failedJobs->count(),
                'failure_patterns' => [],
                'jobs' => [],
            ];

            // Analyze failure patterns
            $exceptionCounts = [];
            $queueCounts = [];

            foreach ($failedJobs as $job) {
                $payload = json_decode($job->payload, true);
                $exception = $job->exception ?? 'Unknown';

                // Extract exception type
                $exceptionType = $this->extractExceptionType($exception);
                $exceptionCounts[$exceptionType] = ($exceptionCounts[$exceptionType] ?? 0) + 1;

                // Count by queue
                $queue = $payload['displayName'] ?? 'unknown';
                $queueCounts[$queue] = ($queueCounts[$queue] ?? 0) + 1;

                $analysis['jobs'][] = [
                    'id' => $job->id,
                    'queue' => $queue,
                    'failed_at' => $job->failed_at,
                    'exception_type' => $exceptionType,
                    'connection' => $job->connection ?? 'unknown',
                ];
            }

            $analysis['failure_patterns'] = [
                'by_exception' => $exceptionCounts,
                'by_queue' => $queueCounts,
            ];

            return $analysis;
        } catch (Exception $e) {
            return ['error' => 'Could not analyze failed jobs: '.$e->getMessage()];
        }
    }

    private function getSlowJobsAnalysis(): array
    {
        // This would require job timing data, which isn't stored by default
        return [
            'note' => 'Slow job analysis requires custom job timing implementation',
            'recommendations' => [
                'Add timing middleware to jobs',
                'Log job execution times',
                'Monitor job duration in production',
                'Consider job chunking for long-running tasks',
            ],
        ];
    }

    private function generateRecommendations(array $result): array
    {
        $recommendations = [];

        $config = $result['configuration'];

        // Configuration recommendations
        if ($config['default_connection'] === 'sync') {
            $recommendations[] = 'Using sync queue driver - consider Redis or database for production';
        }

        if ($config['failed_driver'] === 'null') {
            $recommendations[] = 'Failed job tracking disabled - enable for better debugging';
        }

        // Failed job recommendations
        if (isset($result['failed_jobs']['total_failed'])) {
            $totalFailed = $result['failed_jobs']['total_failed'];
            if ($totalFailed > 100) {
                $recommendations[] = "High number of failed jobs ({$totalFailed}) - investigate failure patterns";
            }
        }

        // General recommendations
        $recommendations[] = 'Monitor queue worker memory usage and restart workers regularly';
        $recommendations[] = 'Implement proper job retry logic and exponential backoff';
        $recommendations[] = 'Consider job batching for related tasks';

        return $recommendations;
    }

    private function extractExceptionType(string $exception): string
    {
        // Extract the exception class name from the stack trace
        if (preg_match('/^([^:]+):/', $exception, $matches)) {
            $fullClass = $matches[1];

            return class_basename($fullClass);
        }

        return 'Unknown';
    }

    private function displayQueueResults(array $result): void
    {
        $data = $result['data'];

        $this->displayQueueConfiguration($data['configuration']);
        $this->displayQueueStatistics($data['statistics']);

        if (isset($data['failed_jobs'])) {
            $this->displayFailedJobsAnalysis($data['failed_jobs']);
        }

        if (isset($data['slow_jobs'])) {
            $this->displaySlowJobsAnalysis($data['slow_jobs']);
        }

        $this->displayRecommendations($data['recommendations']);
        $this->displayMetadata($result['metadata']);
    }

    private function displayQueueConfiguration(array $config): void
    {
        $this->newLine();
        $this->info('⚙️  Queue Configuration');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $this->table(
            ['Setting', 'Value', 'Status'],
            [
                ['Default Connection', $config['default_connection'], $this->getConnectionStatus($config['default_connection'])],
                ['Available Connections', implode(', ', $config['connections']), ''],
                ['Failed Job Driver', $config['failed_driver'], $this->getFailedDriverStatus($config['failed_driver'])],
                ['Failed Job Table', $config['failed_table'] ?? 'N/A', ''],
                ['Retry After', $config['retry_after'] ?? 'N/A', ''],
                ['Max Tries', $config['max_tries'] ?? 'N/A', ''],
            ]
        );
    }

    private function displayQueueStatistics(array $stats): void
    {
        $this->newLine();
        $this->info('📊 Queue Statistics');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (isset($stats['error'])) {
            $this->error("Error: {$stats['error']}");

            return;
        }

        $rows = [
            ['Connection', $stats['connection']],
            ['Pending Jobs', $stats['pending_jobs']],
        ];

        if (isset($stats['oldest_job_age'])) {
            $rows[] = ['Oldest Job Age', $stats['oldest_job_age']];
        }

        if (isset($stats['note'])) {
            $this->warn($stats['note']);
        }

        $this->table(['Metric', 'Value'], $rows);
    }

    private function displayFailedJobsAnalysis(array $failedJobs): void
    {
        $this->newLine();
        $this->error('❌ Failed Jobs Analysis');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (isset($failedJobs['error'])) {
            $this->error("Error: {$failedJobs['error']}");
            if (isset($failedJobs['recommendation'])) {
                $this->line("Recommendation: {$failedJobs['recommendation']}");
            }

            return;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Failed Jobs', $failedJobs['total_failed']],
                ['Recent Failures', $failedJobs['recent_failures']],
            ]
        );

        if (! empty($failedJobs['failure_patterns']['by_exception'])) {
            $this->newLine();
            $this->line('Exception Patterns:');
            foreach ($failedJobs['failure_patterns']['by_exception'] as $exception => $count) {
                $this->line("  • {$exception}: {$count} times");
            }
        }
    }

    private function displaySlowJobsAnalysis(array $slowJobs): void
    {
        $this->newLine();
        $this->warn('🐌 Slow Jobs Analysis');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (isset($slowJobs['note'])) {
            $this->line($slowJobs['note']);
        }

        if (isset($slowJobs['recommendations'])) {
            $this->newLine();
            $this->line('Recommendations:');
            foreach ($slowJobs['recommendations'] as $recommendation) {
                $this->line("  • {$recommendation}");
            }
        }
    }

    private function displayRecommendations(array $recommendations): void
    {
        if ($recommendations === []) {
            return;
        }

        $this->newLine();
        $this->info('💡 Queue Optimization Recommendations');
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

    private function getConnectionStatus(string $connection): string
    {
        return match ($connection) {
            'redis' => '<fg=green>Excellent</>',
            'database' => '<fg=blue>Good</>',
            'beanstalkd' => '<fg=blue>Good</>',
            'sqs' => '<fg=green>Good</>',
            'sync' => '<fg=yellow>Development Only</>',
            default => '<fg=gray>Unknown</>'
        };
    }

    private function getFailedDriverStatus(string $driver): string
    {
        return match ($driver) {
            'database' => '<fg=green>Good</>',
            'dynamodb' => '<fg=green>Good</>',
            'null' => '<fg=red>Disabled</>',
            default => '<fg=gray>Unknown</>'
        };
    }
}
