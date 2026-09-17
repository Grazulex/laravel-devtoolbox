<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\Console\Concerns\HandlesJsonSerialization;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class DevLogTailCommand extends Command
{
    use HandlesJsonSerialization;

    protected $signature = 'dev:log:tail 
                            {--file= : Specific log file to tail (default: laravel.log)}
                            {--lines=50 : Number of lines to show initially}
                            {--pattern= : Filter logs by pattern (regex supported)}
                            {--level= : Filter by log level (emergency, alert, critical, error, warning, notice, info, debug)}
                            {--follow : Follow log in real-time (like tail -f)}
                            {--format=table : Output format (table, json)}';

    protected $description = 'Monitor Laravel logs with real-time filtering and pattern matching';

    private array $logLevels = [
        'emergency', 'alert', 'critical', 'error',
        'warning', 'notice', 'info', 'debug',
    ];

    public function handle(): int
    {
        $file = $this->option('file') ?: 'laravel.log';
        $lines = (int) $this->option('lines');
        $pattern = $this->option('pattern');
        $level = $this->option('level');
        $follow = $this->option('follow');
        $format = $this->option('format');

        // Validate log level
        if ($level && ! in_array(mb_strtolower($level), $this->logLevels)) {
            $this->error("Invalid log level: {$level}");
            $this->line('Valid levels: '.implode(', ', $this->logLevels));

            return self::FAILURE;
        }

        $logPath = $this->getLogPath($file);

        if (! file_exists($logPath)) {
            $this->error("Log file not found: {$logPath}");

            return self::FAILURE;
        }

        if ($follow) {
            return $this->followLog($logPath, $pattern, $level, $format);
        }

        return $this->showLogTail($logPath, $lines, $pattern, $level, $format);

    }

    private function getLogPath(string $file): string
    {
        $logsPath = storage_path('logs');

        // If file contains path separator, treat as relative to logs directory
        if (str_contains($file, '/') || str_contains($file, '\\')) {
            return $logsPath.DIRECTORY_SEPARATOR.$file;
        }

        // If file doesn't have extension, add .log
        if (pathinfo($file, PATHINFO_EXTENSION) === '' || pathinfo($file, PATHINFO_EXTENSION) === '0') {
            $file .= '.log';
        }

        return $logsPath.DIRECTORY_SEPARATOR.$file;
    }

    private function followLog(string $logPath, ?string $pattern, ?string $level, string $format): int
    {
        if ($format === 'json') {
            $this->error('JSON format is not supported for real-time log following. Use --format=table instead.');

            return self::FAILURE;
        }

        $this->info("📡 Following log: {$logPath}");
        if (! in_array($pattern, [null, '', '0'], true)) {
            $this->line("🔍 Pattern: {$pattern}");
        }
        if (! in_array($level, [null, '', '0'], true)) {
            $this->line('📊 Level: '.mb_strtoupper($level));
        }
        $this->line('Press Ctrl+C to stop');
        $this->newLine();

        // Use tail -f command
        $command = ['tail', '-f', $logPath];

        $process = new Process($command);
        $process->setTimeout(null); // No timeout for following

        $process->start();

        foreach ($process as $type => $data) {
            if ($process::OUT === $type) {
                $lines = explode("\n", mb_rtrim($data));
                foreach ($lines as $line) {
                    if ($line !== '' && $line !== '0') {
                        $this->processLogLine($line, $pattern, $level, $format);
                    }
                }
            }
        }

        return self::SUCCESS;
    }

    private function showLogTail(string $logPath, int $lines, ?string $pattern, ?string $level, string $format): int
    {
        if ($format !== 'json') {
            $this->info("📋 Last {$lines} lines from: {$logPath}");
            if (! in_array($pattern, [null, '', '0'], true)) {
                $this->line("🔍 Pattern: {$pattern}");
            }
            if (! in_array($level, [null, '', '0'], true)) {
                $this->line('📊 Level: '.mb_strtoupper($level));
            }
            $this->newLine();
        }

        // Read last N lines
        $command = ['tail', '-n', (string) $lines, $logPath];

        $process = new Process($command);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = 'Failed to read log file: '.$process->getErrorOutput();
            if ($format === 'json') {
                $this->outputJson(['error' => $error]);
            } else {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $output = $process->getOutput();
        $logLines = explode("\n", mb_rtrim($output));

        $result = [
            'file' => $logPath,
            'lines_requested' => $lines,
            'pattern' => $pattern,
            'level_filter' => $level,
            'entries' => [],
            'statistics' => [
                'total_lines' => 0,
                'filtered_lines' => 0,
                'levels' => [],
            ],
        ];

        foreach ($logLines as $line) {
            if ($line !== '' && $line !== '0') {
                $result['statistics']['total_lines']++;
                $processed = $this->processLogLineForJson($line, $pattern, $level);
                if ($processed !== null && $processed !== []) {
                    $result['entries'][] = $processed;
                    $result['statistics']['filtered_lines']++;

                    // Count levels
                    $logLevel = $processed['level'] ?? 'unknown';
                    $result['statistics']['levels'][$logLevel] =
                        ($result['statistics']['levels'][$logLevel] ?? 0) + 1;
                }
            }
        }

        if ($format === 'json') {
            $this->outputJson($result);
        } else {
            $this->displayLogEntries($result['entries']);
            $this->displayLogStatistics($result['statistics']);
        }

        return self::SUCCESS;
    }

    private function processLogLine(string $line, ?string $pattern, ?string $level, string $format, bool $display = true): ?array
    {
        // Parse Laravel log format
        $parsed = $this->parseLogLine($line);

        // Apply filters
        if ($level && isset($parsed['level']) && mb_strtolower($parsed['level']) !== mb_strtolower($level)) {
            return null;
        }

        if ($pattern && ! $this->matchesPattern($line, $pattern)) {
            return null;
        }

        if ($display && $format !== 'json') {
            $this->displayFormattedLogLine($parsed);
        }

        return $parsed;
    }

    private function processLogLineForJson(string $line, ?string $pattern, ?string $level): ?array
    {
        // Parse Laravel log format
        $parsed = $this->parseLogLine($line);

        // Apply filters
        if ($level && isset($parsed['level']) && mb_strtolower($parsed['level']) !== mb_strtolower($level)) {
            return null;
        }

        if ($pattern && ! $this->matchesPattern($line, $pattern)) {
            return null;
        }

        return $parsed;
    }

    private function displayLogEntries(array $entries): void
    {
        if ($entries === []) {
            $this->warn('❌ No log entries found matching the criteria.');

            return;
        }

        foreach ($entries as $entry) {
            $this->displayFormattedLogLine($entry);
        }
    }

    private function displayLogStatistics(array $stats): void
    {
        $this->newLine();
        $this->info('📊 Log Statistics:');
        $this->line("   Total lines: {$stats['total_lines']}");
        $this->line("   Filtered lines: {$stats['filtered_lines']}");

        if (! empty($stats['levels'])) {
            $this->line('   Levels found:');
            foreach ($stats['levels'] as $level => $count) {
                $this->line('     • '.mb_strtoupper($level).": {$count}");
            }
        }
    }

    private function parseLogLine(string $line): array
    {
        // Laravel log format: [2024-07-26 10:30:45] local.ERROR: Message
        $pattern = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)$/';

        if (preg_match($pattern, $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'environment' => $matches[2],
                'level' => $matches[3],
                'message' => $matches[4],
                'raw' => $line,
            ];
        }

        // If doesn't match Laravel format, treat as continuation or raw log
        return [
            'timestamp' => null,
            'environment' => null,
            'level' => 'unknown',
            'message' => $line,
            'raw' => $line,
        ];
    }

    private function matchesPattern(string $line, string $pattern): bool
    {
        // Try as regex first (if wrapped in delimiters)
        if (preg_match('/^\/.*\/[gimxsu]*$/', $pattern)) {
            try {
                return (bool) preg_match($pattern, $line);
            } catch (Exception $e) {
                // Fall back to string search if regex is invalid
            }
        }

        // Simple string search (case-insensitive)
        return mb_stripos($line, $pattern) !== false;
    }

    private function displayFormattedLogLine(array $parsed): void
    {
        if (! $parsed['timestamp']) {
            // Continuation line
            $this->line('    '.$parsed['message']);

            return;
        }

        $level = mb_strtoupper($parsed['level']);
        $timestamp = $parsed['timestamp'];
        $message = $parsed['message'];

        $levelColor = match ($parsed['level']) {
            'emergency', 'alert', 'critical' => '<fg=red>',
            'error' => '<fg=red>',
            'warning' => '<fg=yellow>',
            'notice', 'info' => '<fg=blue>',
            'debug' => '<fg=gray>',
            default => '<fg=white>',
        };

        $formatted = "<fg=gray>[{$timestamp}]</> {$levelColor}[{$level}]</> {$message}";
        $this->line($formatted);
    }
}
