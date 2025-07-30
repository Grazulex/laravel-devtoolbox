<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevRoutesUnusedCommand extends Command
{
    protected $signature = 'dev:routes:unused 
                            {--format=table : Output format (table, json)}
                            {--output= : Save output to file}
                            {--strict : Use strict detection (flags unprotected API routes too)}
                            {--exclude-api : Exclude API routes from detection}
                            {--security-focused : Focus on security issues (dangerous unprotected routes)}';

    protected $description = 'Detect potentially unused routes in your application';

    public function handle(DevtoolboxManager $manager): int
    {
        $format = $this->option('format');
        $output = $this->option('output');
        $strict = $this->option('strict');
        $excludeApi = $this->option('exclude-api');
        $securityFocused = $this->option('security-focused');

        // Only show progress message if not outputting JSON directly
        if ($format !== 'json') {
            $this->info('Analyzing routes for unused ones...');
        }

        $result = $manager->scan('routes', [
            'detect_unused' => true,
            'format' => $format,
            'strict_unused_detection' => $strict,
            'exclude_api_routes' => $excludeApi,
            'security_focused' => $securityFocused,
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
    }

    private function displayResults(array $result): void
    {
        $data = $result['data'] ?? [];
        $routes = $data['routes'] ?? [];
        $unusedRoutes = array_filter($routes, fn ($route) => ($route['unused'] ?? false));

        $this->line('Found '.count($unusedRoutes).' potentially unused/problematic routes:');
        $this->newLine();

        if ($unusedRoutes === []) {
            $this->info('✅ No potentially unused routes detected!');

            return;
        }

        foreach ($unusedRoutes as $route) {
            $methods = implode('|', $route['methods'] ?? ['GET']);
            $middleware = $route['middleware'] ?? [];

            // Determine why this route was flagged
            $reason = $this->getRouteIssueReason($route);
            $severity = $this->getRouteSeverity($route);

            $severityIcon = match ($severity) {
                'critical' => '🔴',
                'high' => '🟠',
                'medium' => '🟡',
                default => '🔵'
            };

            $this->line("{$severityIcon} {$methods} {$route['uri']} ({$reason})");
            if (isset($route['name'])) {
                $this->line("   📛 Name: {$route['name']}");
            }
            if (isset($route['action'])) {
                $this->line("   🎯 Action: {$route['action']}");
            }
            if (! empty($middleware)) {
                $this->line('   🛡️ Middleware: '.implode(', ', $middleware));
            } else {
                $this->line('   ⚠️ No middleware protection');
            }
            $this->newLine();
        }
    }

    private function getRouteIssueReason(array $route): string
    {
        $uri = $route['uri'];
        $name = $route['name'] ?? '';
        $middleware = $route['middleware'] ?? [];
        $methods = $route['methods'] ?? [];

        // Check for debug routes
        $debugPatterns = ['/debug', 'debug.'];
        foreach ($debugPatterns as $pattern) {
            if (str_contains($uri, $pattern) || str_contains($name, $pattern)) {
                return empty($middleware) ? 'Unprotected debug route' : 'Debug route';
            }
        }

        // Check for admin routes
        $adminPatterns = ['/admin', '/dashboard', '/settings', 'admin.', 'dashboard.', 'settings.'];
        foreach ($adminPatterns as $pattern) {
            if (str_contains($uri, $pattern) || str_contains($name, $pattern)) {
                return empty($middleware) ? 'Unprotected admin route' : 'Admin route';
            }
        }

        // Check for unused patterns
        $unusedPatterns = ['unused', 'legacy', 'old', 'test', 'demo', 'sample'];
        foreach ($unusedPatterns as $pattern) {
            if (str_contains($uri, $pattern) || str_contains($name, $pattern)) {
                return 'Unused/legacy route';
            }
        }

        // Check for dangerous methods
        $dangerousMethods = ['DELETE', 'PUT', 'PATCH', 'POST'];
        $hasDangerous = array_intersect($methods, $dangerousMethods);
        if ($hasDangerous && empty($middleware)) {
            return 'Unprotected '.implode('/', $hasDangerous).' route';
        }

        return 'Potentially unused';
    }

    private function getRouteSeverity(array $route): string
    {
        $uri = $route['uri'];
        $name = $route['name'] ?? '';
        $middleware = $route['middleware'] ?? [];
        $methods = $route['methods'] ?? [];

        // Critical: unprotected admin/debug routes
        $criticalPatterns = ['/admin', '/debug', 'admin.', 'debug.'];
        foreach ($criticalPatterns as $pattern) {
            if ((str_contains($uri, $pattern) || str_contains($name, $pattern)) && empty($middleware)) {
                return 'critical';
            }
        }

        // High: unprotected dangerous methods
        $dangerousMethods = ['DELETE', 'PUT', 'PATCH', 'POST'];
        $hasDangerous = array_intersect($methods, $dangerousMethods);
        if ($hasDangerous && empty($middleware)) {
            return 'high';
        }

        // Medium: settings routes, protected admin routes
        $mediumPatterns = ['/settings', 'settings.'];
        foreach ($mediumPatterns as $pattern) {
            if (str_contains($uri, $pattern) || str_contains($name, $pattern)) {
                return 'medium';
            }
        }

        return 'low';
    }
}
