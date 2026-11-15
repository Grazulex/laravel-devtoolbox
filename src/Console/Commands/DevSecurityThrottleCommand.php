<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevSecurityThrottleCommand extends Command
{
    protected $signature = 'dev:security:throttle
                            {--exclude=* : Route patterns to exclude from check}
                            {--format=table : Output format (table, json)}
                            {--output= : Output file path}';

    protected $description = 'Scan for sensitive routes (login, register, password reset, API) without throttle middleware';

    public function handle(DevtoolboxManager $manager): int
    {
        $exclude = $this->option('exclude');
        $format = $this->option('format');
        $output = $this->option('output');

        if ($format !== 'json') {
            $this->info('🚦 Scanning for routes without rate limiting...');
        }

        try {
            $result = $manager->scan('security', [
                'check_unprotected_routes' => false,
                'check_csrf_protection' => false,
                'check_throttle' => true,
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
            $this->error('Error scanning throttling: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function displayResults(array $result): void
    {
        $routes = $result['data']['unthrottled_routes'] ?? [];

        if ($routes === []) {
            $this->info('✅ No sensitive routes without throttle middleware found!');

            return;
        }

        $this->error('🚨 Routes Without Rate Limiting:');

        foreach ($routes as $route) {
            $methods = implode('|', $route['methods']);
            $this->line("<fg=red>[{$route['severity']}] {$methods} {$route['uri']}</>");

            if (! empty($route['name'])) {
                $this->line("   📛 Name: {$route['name']}");
            }

            $this->line("   🏷️ Category: {$route['category']}");
            $this->line("   💡 Recommendation: {$route['recommendation']}");
            $this->newLine();
        }

        $this->info('📊 Total: '.count($routes).' route(s) without throttle middleware');
    }
}
