<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\Mcp\McpRegistration;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;

final class DevAboutPlusCommand extends Command
{
    protected $signature = 'dev:about+ 
                            {--extended : Show extended information including detailed environment}
                            {--performance : Include performance metrics and optimization tips}
                            {--security : Include security-related information}
                            {--format=table : Output format (table, json)}
                            {--output= : Output file path}';

    protected $description = 'Enhanced version of "about" command with additional Laravel environment details';

    public function handle(): int
    {
        $extended = $this->option('extended');
        $performance = $this->option('performance');
        $security = $this->option('security');
        $format = $this->option('format');
        $output = $this->option('output');

        try {
            $data = $this->gatherInformation($extended, $performance, $security);

            if ($output) {
                file_put_contents($output, json_encode($data, JSON_PRETTY_PRINT));
                if ($format !== 'json') {
                    $this->info("Results saved to: {$output}");
                }
            } elseif ($format === 'json') {
                $this->line(json_encode($data, JSON_PRETTY_PRINT));
            } else {
                $this->displayInformation($data);
            }

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Error gathering application information: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function gatherInformation(bool $extended, bool $performance, bool $security): array
    {
        $data = [
            'application' => $this->getApplicationInfo(),
            'environment' => $this->getEnvironmentInfo($extended),
            'dependencies' => $this->getDependenciesInfo(),
            'mcp' => $this->getMcpInfo(),
        ];

        if ($performance) {
            $data['performance'] = $this->getPerformanceInfo();
        }

        if ($security) {
            $data['security'] = $this->getSecurityInfo();
        }

        if ($extended) {
            $data['storage'] = $this->getStorageInfo();
            $data['database'] = $this->getDatabaseInfo();
            $data['cache'] = $this->getCacheInfo();
            $data['queue'] = $this->getQueueInfo();
        }

        return $data;
    }

    private function getApplicationInfo(): array
    {
        $app = app();

        return [
            'name' => config('app.name'),
            'version' => $app->version(),
            'laravel_version' => Application::VERSION,
            'php_version' => PHP_VERSION,
            'environment' => app()->environment(),
            'debug_mode' => config('app.debug'),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'url' => config('app.url'),
            'maintenance_mode' => app()->isDownForMaintenance(),
        ];
    }

    private function getEnvironmentInfo(bool $extended): array
    {
        $info = [
            'php_sapi' => php_sapi_name(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'operating_system' => PHP_OS_FAMILY,
            'architecture' => php_uname('m'),
        ];

        if ($extended) {
            return array_merge($info, [
                'php_extensions' => array_slice(get_loaded_extensions(), 0, 20), // First 20
                'total_extensions' => count(get_loaded_extensions()),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'opcache_enabled' => extension_loaded('opcache') && ini_get('opcache.enable'),
                'xdebug_enabled' => extension_loaded('xdebug'),
            ]);
        }

        return $info;
    }

    private function getDependenciesInfo(): array
    {
        $composerLock = base_path('composer.lock');

        if (! file_exists($composerLock)) {
            return ['status' => 'composer.lock not found'];
        }

        $lockData = json_decode(file_get_contents($composerLock), true);
        $packages = $lockData['packages'] ?? [];

        $stats = [
            'total_packages' => count($packages),
            'laravel_packages' => 0,
            'symphony_packages' => 0,
            'dev_packages' => count($lockData['packages-dev'] ?? []),
        ];

        $laravelPackages = [];
        $symphonyPackages = [];

        foreach ($packages as $package) {
            $name = $package['name'];

            if (str_starts_with($name, 'laravel/')) {
                $stats['laravel_packages']++;
                $laravelPackages[] = $name.' ('.$package['version'].')';
            }

            if (str_starts_with($name, 'symfony/')) {
                $stats['symphony_packages']++;
                $symphonyPackages[] = $name.' ('.$package['version'].')';
            }
        }

        return [
            'statistics' => $stats,
            'laravel_packages' => array_slice($laravelPackages, 0, 10),
            'symfony_packages' => array_slice($symphonyPackages, 0, 10),
            'composer_version' => $lockData['plugin-api-version'] ?? 'Unknown',
        ];
    }

    private function getMcpInfo(): array
    {
        return [
            'installed' => class_exists(\Laravel\Mcp\Facades\Mcp::class),
            'enabled' => (bool) config('devtoolbox.mcp.enabled', true),
            'environment_allowed' => McpRegistration::isEnvironmentAllowed($this->laravel),
            'server' => 'devtoolbox',
            'start_command' => 'php artisan mcp:start devtoolbox',
        ];
    }

    private function getPerformanceInfo(): array
    {
        $info = [
            'memory_usage' => [
                'current' => $this->formatBytes(memory_get_usage()),
                'peak' => $this->formatBytes(memory_get_peak_usage()),
                'limit' => ini_get('memory_limit'),
            ],
            'opcache' => [
                'enabled' => extension_loaded('opcache') && ini_get('opcache.enable'),
                'hit_rate' => null,
                'memory_usage' => null,
            ],
            'recommendations' => [],
        ];

        // OPcache statistics
        if (function_exists('opcache_get_status')) {
            $opcacheStatus = opcache_get_status();
            if ($opcacheStatus) {
                $info['opcache']['hit_rate'] = round($opcacheStatus['opcache_statistics']['opcache_hit_rate'] ?? 0, 2);
                $info['opcache']['memory_usage'] = $this->formatBytes($opcacheStatus['memory_usage']['used_memory'] ?? 0);
            }
        }

        // Performance recommendations
        if (! $info['opcache']['enabled']) {
            $info['recommendations'][] = 'Enable OPcache for better performance';
        }

        if (config('app.debug')) {
            $info['recommendations'][] = 'Disable debug mode in production';
        }

        if (config('app.env') !== 'production') {
            $info['recommendations'][] = 'Set APP_ENV=production for optimal performance';
        }

        return $info;
    }

    private function getSecurityInfo(): array
    {
        $info = [
            'app_key_set' => ! empty(config('app.key')),
            'https_enabled' => request()->isSecure(),
            'session_driver' => config('session.driver'),
            'session_secure' => config('session.secure'),
            'csrf_protection' => class_exists('Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken'),
            'vulnerabilities' => [],
            'recommendations' => [],
        ];

        // Security recommendations
        if (! $info['app_key_set']) {
            $info['vulnerabilities'][] = 'Application key is not set';
            $info['recommendations'][] = 'Run "php artisan key:generate"';
        }

        if (config('app.debug') && app()->environment('production')) {
            $info['vulnerabilities'][] = 'Debug mode enabled in production';
            $info['recommendations'][] = 'Set APP_DEBUG=false in production';
        }

        if (! $info['https_enabled'] && app()->environment('production')) {
            $info['recommendations'][] = 'Enable HTTPS in production';
        }

        if (config('session.driver') === 'file' && app()->environment('production')) {
            $info['recommendations'][] = 'Consider using Redis or database for session storage in production';
        }

        return $info;
    }

    private function getStorageInfo(): array
    {
        $info = [
            'default_disk' => config('filesystems.default'),
            'disks' => [],
        ];

        foreach (config('filesystems.disks', []) as $name => $config) {
            $disk = Storage::disk($name);

            try {
                $info['disks'][$name] = [
                    'driver' => $config['driver'],
                    'accessible' => true,
                ];

                if ($config['driver'] === 'local') {
                    $path = $config['root'] ?? storage_path('app');
                    $info['disks'][$name]['path'] = $path;
                    $info['disks'][$name]['writable'] = is_writable($path);
                }
            } catch (Exception $e) {
                $info['disks'][$name] = [
                    'driver' => $config['driver'],
                    'accessible' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $info;
    }

    private function getDatabaseInfo(): array
    {
        try {
            $defaultConnection = config('database.default');
            $connections = config('database.connections', []);

            $info = [
                'default_connection' => $defaultConnection,
                'connections' => [],
            ];

            foreach ($connections as $name => $config) {
                try {
                    $pdo = DB::connection($name)->getPdo();

                    $info['connections'][$name] = [
                        'driver' => $config['driver'],
                        'status' => 'connected',
                        'version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                    ];
                } catch (Exception $e) {
                    $info['connections'][$name] = [
                        'driver' => $config['driver'],
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $info;
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getCacheInfo(): array
    {
        try {
            $info = [
                'default_store' => config('cache.default'),
                'stores' => [],
            ];

            foreach (config('cache.stores', []) as $name => $config) {
                try {
                    $cache = Cache::store($name);

                    // Test cache functionality
                    $testKey = 'devtoolbox_test_'.time();
                    $cache->put($testKey, 'test', 1);
                    $canRead = $cache->get($testKey) === 'test';
                    $cache->forget($testKey);

                    $info['stores'][$name] = [
                        'driver' => $config['driver'],
                        'status' => $canRead ? 'working' : 'read_failed',
                    ];
                } catch (Exception $e) {
                    $info['stores'][$name] = [
                        'driver' => $config['driver'],
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $info;
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getQueueInfo(): array
    {
        return [
            'default_connection' => config('queue.default'),
            'connections' => array_keys(config('queue.connections', [])),
            'failed_table' => config('queue.failed.table'),
        ];
    }

    private function displayInformation(array $data): void
    {
        $this->info('🚀 Laravel Application Information (Enhanced)');
        $this->newLine();

        // Application Info
        $this->displaySection('Application', $data['application']);

        // Environment Info
        $this->displaySection('Environment', $data['environment']);

        // Dependencies Info
        $this->displaySection('Dependencies', $data['dependencies']);

        // MCP Info
        $this->displaySection('MCP', $data['mcp']);

        // Performance Info (if available)
        if (isset($data['performance'])) {
            $this->displayPerformanceSection($data['performance']);
        }

        // Security Info (if available)
        if (isset($data['security'])) {
            $this->displaySecuritySection($data['security']);
        }

        // Extended sections
        if (isset($data['storage'])) {
            $this->displaySection('Storage', $data['storage']);
        }

        if (isset($data['database'])) {
            $this->displaySection('Database', $data['database']);
        }

        if (isset($data['cache'])) {
            $this->displaySection('Cache', $data['cache']);
        }

        if (isset($data['queue'])) {
            $this->displaySection('Queue', $data['queue']);
        }
    }

    private function displaySection(string $title, array $data): void
    {
        $this->comment("▶ {$title}");

        $tableData = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_PRETTY_PRINT);
            } elseif (is_bool($value)) {
                $value = $value ? '✅ Yes' : '❌ No';
            }

            $tableData[] = [ucfirst(str_replace('_', ' ', $key)), $value];
        }

        $this->table(['Property', 'Value'], $tableData);
        $this->newLine();
    }

    private function displayPerformanceSection(array $performance): void
    {
        $this->comment('▶ Performance');

        $this->line("💾 Memory Usage: {$performance['memory_usage']['current']} (Peak: {$performance['memory_usage']['peak']})");
        $this->line('⚡ OPcache: '.($performance['opcache']['enabled'] ? '✅ Enabled' : '❌ Disabled'));

        if ($performance['opcache']['hit_rate']) {
            $this->line("📊 OPcache Hit Rate: {$performance['opcache']['hit_rate']}%");
        }

        if (! empty($performance['recommendations'])) {
            $this->newLine();
            $this->warn('⚠️  Performance Recommendations:');
            foreach ($performance['recommendations'] as $recommendation) {
                $this->line("   • {$recommendation}");
            }
        }

        $this->newLine();
    }

    private function displaySecuritySection(array $security): void
    {
        $this->comment('▶ Security');

        $this->line('🔑 App Key: '.($security['app_key_set'] ? '✅ Set' : '❌ Missing'));
        $this->line('🔒 HTTPS: '.($security['https_enabled'] ? '✅ Enabled' : '❌ Disabled'));
        $this->line('🛡️  CSRF Protection: '.($security['csrf_protection'] ? '✅ Available' : '❌ Missing'));

        if (! empty($security['vulnerabilities'])) {
            $this->newLine();
            $this->error('🚨 Security Vulnerabilities:');
            foreach ($security['vulnerabilities'] as $vulnerability) {
                $this->line("   • {$vulnerability}");
            }
        }

        if (! empty($security['recommendations'])) {
            $this->newLine();
            $this->warn('🔐 Security Recommendations:');
            foreach ($security['recommendations'] as $recommendation) {
                $this->line("   • {$recommendation}");
            }
        }

        $this->newLine();
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }
}
