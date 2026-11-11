<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevConfigEnvCallsCommand extends Command
{
    protected $signature = 'dev:config:env-calls
                            {--path=* : Files or directories to scan (defaults to app, bootstrap, database, resources and routes)}
                            {--exclude=* : Files or directories to skip}
                            {--fail-on-found : Exit with a failure code when env() calls are found (useful in CI)}
                            {--format=table : Output format (table, json)}
                            {--output= : Output file path}';

    protected $description = 'Find env() calls outside the config directory, which return null once the configuration is cached';

    public function handle(DevtoolboxManager $manager): int
    {
        $format = $this->option('format');
        $output = $this->option('output');

        if ($format !== 'json') {
            $this->info('🔍 Searching for env() calls outside config/...');
        }

        try {
            $result = $manager->scan('env-calls', [
                'scan_paths' => $this->option('path'),
                'exclude_paths' => $this->option('exclude'),
            ]);
        } catch (Exception $e) {
            $this->error('Error searching for env() calls: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($output) {
            file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT));
            if ($format !== 'json') {
                $this->info("Results saved to: {$output}");
            }
        } elseif ($format === 'json') {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->displayResults($result['data'] ?? []);
        }

        $found = count($result['data']['calls'] ?? []);

        return $this->option('fail-on-found') && $found > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function displayResults(array $data): void
    {
        $calls = $data['calls'] ?? [];
        $summary = $data['summary'] ?? [];

        if ($calls === []) {
            $this->info('✅ No env() call found outside config/ ('.($summary['scanned_files'] ?? 0).' files scanned).');

            return;
        }

        $this->table(
            ['File', 'Line', 'Key', 'Code'],
            array_map(fn (array $call): array => [
                $call['file'],
                $call['line'],
                $call['key'] ?? '-',
                mb_strimwidth($call['code'], 0, 80, '...'),
            ], $calls),
        );

        $this->newLine();
        $this->warn(sprintf(
            '⚠️  %d env() call(s) in %d file(s) outside config/. They return null once `php artisan config:cache` has run.',
            $summary['total_calls'] ?? count($calls),
            $summary['files_with_calls'] ?? 0,
        ));
        $this->line('💡 Read the value in a config file (e.g. \'key\' => env(\'KEY\')) and use config(\'file.key\') in the code instead.');
    }
}
