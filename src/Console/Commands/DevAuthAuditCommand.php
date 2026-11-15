<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevAuthAuditCommand extends Command
{
    protected $signature = 'dev:auth:audit
                            {--exclude=* : Route patterns to exclude from check}
                            {--format=table : Output format (table, json)}
                            {--output= : Output file path}';

    protected $description = 'Audit authorization: can/authorize routes pointing to missing policies and route models without a policy';

    public function handle(DevtoolboxManager $manager): int
    {
        $exclude = $this->option('exclude');
        $format = $this->option('format');
        $output = $this->option('output');

        if ($format !== 'json') {
            $this->info('🔐 Auditing route authorization...');
        }

        try {
            $result = $manager->scan('security', [
                'check_unprotected_routes' => false,
                'check_csrf_protection' => false,
                'check_authorization' => true,
                'exclude_patterns' => $exclude,
            ]);

            if ($output) {
                file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT));
                if ($format !== 'json') {
                    $this->info("Results saved to: {$output}");
                }
            } elseif ($format === 'json') {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
            } else {
                $this->displayResults($result);
            }

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Error auditing authorization: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function displayResults(array $result): void
    {
        $issues = $result['data']['authorization_issues'] ?? [];

        if ($issues === []) {
            $this->info('✅ No authorization issues found!');

            return;
        }

        $this->error('🚨 Authorization Issues:');

        foreach ($issues as $issue) {
            $methods = implode('|', $issue['methods']);
            $this->line("<fg=red>[{$issue['severity']}] {$issue['type']}: {$methods} {$issue['uri']}</>");

            if (! empty($issue['name'])) {
                $this->line("   📛 Name: {$issue['name']}");
            }

            $this->line("   📦 Model: {$issue['model']}");

            if (! empty($issue['ability'])) {
                $this->line("   🔑 Ability: {$issue['ability']}");
            }

            $this->line("   💡 Recommendation: {$issue['recommendation']}");
            $this->newLine();
        }

        $this->info('📊 Total: '.count($issues).' authorization issue(s)');
    }
}
