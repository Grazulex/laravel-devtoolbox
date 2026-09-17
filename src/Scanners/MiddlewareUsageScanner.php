<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Exception;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionClass;
use ReflectionException;

final class MiddlewareUsageScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'middleware-usage';
    }

    public function getDescription(): string
    {
        return 'Analyzes middleware usage across routes, controllers, and groups';
    }

    public function getOptionSchema(): array
    {
        return [
            'middleware' => ['type' => 'string', 'description' => 'Specific middleware (alias or class) to analyze; all middleware when omitted'],
            'show_routes' => ['type' => 'boolean', 'description' => 'Include detailed route information', 'default' => false],
            'show_groups' => ['type' => 'boolean', 'description' => 'Include route group information', 'default' => false],
            'show_global' => ['type' => 'boolean', 'description' => 'Include global middleware', 'default' => false],
            'unused_only' => ['type' => 'boolean', 'description' => 'Show only unused middleware', 'default' => false],
        ];
    }

    public function scan(array $options = []): array
    {
        $targetMiddleware = $options['middleware'] ?? null;
        $showRoutes = $options['show_routes'] ?? false;
        $showGroups = $options['show_groups'] ?? false;
        $showGlobal = $options['show_global'] ?? false;
        $unusedOnly = $options['unused_only'] ?? false;

        try {
            $allMiddleware = $this->getAllRegisteredMiddleware();
            $usageData = $this->analyzeMiddlewareUsage($allMiddleware, $showRoutes, $showGroups);

            if ($showGlobal) {
                $usageData['global_middleware'] = $this->getGlobalMiddleware();
            }

            // Filter for specific middleware if requested
            if ($targetMiddleware) {
                $usageData = $this->filterForMiddleware($usageData, $targetMiddleware);
            }

            // Filter for unused only if requested
            if ($unusedOnly) {
                $usageData = $this->filterUnusedMiddleware($usageData);
            }

            return [
                'middleware_analysis' => $usageData,
                'statistics' => $this->generateStatistics($usageData, $allMiddleware),
                'options' => $options,
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to analyze middleware usage: '.$e->getMessage(),
                'middleware_analysis' => [],
                'statistics' => [],
            ];
        }
    }

    private function getAllRegisteredMiddleware(): array
    {
        $middleware = [];

        // Get middleware from HTTP Kernel
        $kernel = app('Illuminate\Contracts\Http\Kernel');

        // Global middleware
        $reflection = new ReflectionClass($kernel);
        try {
            $middlewareProperty = $reflection->getProperty('middleware');
            $middlewareProperty->setAccessible(true);
            $globalMiddleware = $middlewareProperty->getValue($kernel);

            foreach ($globalMiddleware as $class) {
                $middleware['global'][] = [
                    'class' => $class,
                    'name' => $this->getMiddlewareName($class),
                    'type' => 'global',
                ];
            }
        } catch (ReflectionException $e) {
            // Fallback if reflection fails
        }

        // Route middleware (aliases)
        try {
            $middlewareGroupsProperty = $reflection->getProperty('middlewareGroups');
            $middlewareGroupsProperty->setAccessible(true);
            $middlewareGroups = $middlewareGroupsProperty->getValue($kernel);

            foreach ($middlewareGroups as $group => $groupMiddleware) {
                foreach ($groupMiddleware as $class) {
                    $middleware['groups'][$group][] = [
                        'class' => $class,
                        'name' => $this->getMiddlewareName($class),
                        'type' => 'group',
                        'group' => $group,
                    ];
                }
            }
        } catch (ReflectionException $e) {
            // Fallback
        }

        // Route middleware aliases
        try {
            $routeMiddlewareProperty = $reflection->getProperty('routeMiddleware');
            $routeMiddlewareProperty->setAccessible(true);
            $routeMiddleware = $routeMiddlewareProperty->getValue($kernel);

            foreach ($routeMiddleware as $alias => $class) {
                $middleware['aliases'][$alias] = [
                    'class' => $class,
                    'name' => $this->getMiddlewareName($class),
                    'alias' => $alias,
                    'type' => 'route',
                ];
            }
        } catch (ReflectionException $e) {
            // Fallback
        }

        return $middleware;
    }

    private function analyzeMiddlewareUsage(array $allMiddleware, bool $showRoutes, bool $showGroups): array
    {
        $usage = [];
        $routes = RouteFacade::getRoutes()->getRoutes();

        // Initialize usage tracking
        $this->initializeUsageTracking($usage, $allMiddleware);

        foreach ($routes as $route) {
            $this->analyzeRouteMiddleware($route, $usage, $showRoutes, $showGroups);
        }

        return $usage;
    }

    private function initializeUsageTracking(array &$usage, array $allMiddleware): void
    {
        // Initialize global middleware
        if (isset($allMiddleware['global'])) {
            foreach ($allMiddleware['global'] as $middleware) {
                $key = $middleware['class'];
                $usage[$key] = [
                    'middleware' => $middleware,
                    'usage_count' => 0,
                    'routes' => [],
                    'groups_used' => [],
                    'used_as_global' => true,
                ];
            }
        }

        // Initialize group middleware
        if (isset($allMiddleware['groups'])) {
            foreach ($allMiddleware['groups'] as $middlewares) {
                foreach ($middlewares as $middleware) {
                    $key = $middleware['class'];
                    if (! isset($usage[$key])) {
                        $usage[$key] = [
                            'middleware' => $middleware,
                            'usage_count' => 0,
                            'routes' => [],
                            'groups_used' => [],
                            'used_as_global' => false,
                        ];
                    }
                }
            }
        }

        // Initialize route middleware aliases
        if (isset($allMiddleware['aliases'])) {
            foreach ($allMiddleware['aliases'] as $middleware) {
                $key = $middleware['class'];
                if (! isset($usage[$key])) {
                    $usage[$key] = [
                        'middleware' => $middleware,
                        'usage_count' => 0,
                        'routes' => [],
                        'groups_used' => [],
                        'used_as_global' => false,
                    ];
                }
            }
        }
    }

    private function analyzeRouteMiddleware(Route $route, array &$usage, bool $showRoutes, bool $showGroups): void
    {
        $routeMiddleware = $route->middleware();
        $routeInfo = [
            'uri' => $route->uri(),
            'methods' => $route->methods(),
            'name' => $route->getName(),
            'action' => $route->getActionName(),
        ];

        foreach ($routeMiddleware as $middleware) {
            // Handle middleware with parameters (e.g., "auth:api")
            $middlewareName = explode(':', $middleware)[0];

            // Find the actual middleware class
            $middlewareClass = $this->resolveMiddlewareClass($middlewareName);

            if ($middlewareClass && isset($usage[$middlewareClass])) {
                $usage[$middlewareClass]['usage_count']++;

                if ($showRoutes) {
                    $usage[$middlewareClass]['routes'][] = $routeInfo;
                }

                // Track which groups this middleware is used in
                if ($showGroups) {
                    $this->trackGroupUsage($usage[$middlewareClass], $middleware, $route);
                }
            }
        }
    }

    private function resolveMiddlewareClass(string $middlewareName): ?string
    {
        $kernel = app('Illuminate\Contracts\Http\Kernel');
        $reflection = new ReflectionClass($kernel);

        try {
            // Check route middleware aliases
            $routeMiddlewareProperty = $reflection->getProperty('routeMiddleware');
            $routeMiddlewareProperty->setAccessible(true);
            $routeMiddleware = $routeMiddlewareProperty->getValue($kernel);

            if (isset($routeMiddleware[$middlewareName])) {
                return $routeMiddleware[$middlewareName];
            }

            // Check if it's a direct class name
            if (class_exists($middlewareName)) {
                return $middlewareName;
            }

        } catch (ReflectionException $e) {
            // Continue to other resolution methods
        }

        return null;
    }

    private function trackGroupUsage(array &$middlewareUsage, string $middleware, Route $route): void
    {
        // This is a simplified version - in a real implementation,
        // you might want to track more detailed group information
        $groupInfo = [
            'middleware_applied' => $middleware,
            'route_pattern' => $route->uri(),
        ];

        if (! in_array($groupInfo, $middlewareUsage['groups_used'])) {
            $middlewareUsage['groups_used'][] = $groupInfo;
        }
    }

    private function getGlobalMiddleware(): array
    {
        $kernel = app('Illuminate\Contracts\Http\Kernel');
        $reflection = new ReflectionClass($kernel);

        try {
            $middlewareProperty = $reflection->getProperty('middleware');
            $middlewareProperty->setAccessible(true);
            $globalMiddleware = $middlewareProperty->getValue($kernel);

            return array_map(function (string $class): array {
                return [
                    'class' => $class,
                    'name' => $this->getMiddlewareName($class),
                    'applies_to' => 'all_requests',
                ];
            }, $globalMiddleware);

        } catch (ReflectionException $e) {
            return [];
        }
    }

    private function filterForMiddleware(array $usageData, string $targetMiddleware): array
    {
        $filtered = [];

        foreach ($usageData as $class => $data) {
            $middleware = $data['middleware'];

            // Match by class name, alias, or partial name
            if (
                str_contains(mb_strtolower($class), mb_strtolower($targetMiddleware)) ||
                (isset($middleware['alias']) && str_contains(mb_strtolower($middleware['alias']), mb_strtolower($targetMiddleware))) ||
                str_contains(mb_strtolower($middleware['name']), mb_strtolower($targetMiddleware))
            ) {
                $filtered[$class] = $data;
            }
        }

        return $filtered;
    }

    private function filterUnusedMiddleware(array $usageData): array
    {
        return array_filter($usageData, function (array $data): bool {
            return $data['usage_count'] === 0 && ! $data['used_as_global'];
        });
    }

    private function generateStatistics(array $usageData, array $allMiddleware): array
    {
        $stats = [
            'total_middleware_classes' => 0,
            'global_middleware' => 0,
            'route_middleware' => 0,
            'group_middleware' => 0,
            'used_middleware' => 0,
            'unused_middleware' => 0,
            'most_used' => [],
            'never_used' => [],
        ];

        // Count middleware types
        if (isset($allMiddleware['global'])) {
            $stats['global_middleware'] = count($allMiddleware['global']);
        }
        if (isset($allMiddleware['aliases'])) {
            $stats['route_middleware'] = count($allMiddleware['aliases']);
        }
        if (isset($allMiddleware['groups'])) {
            $groupCount = 0;
            foreach ($allMiddleware['groups'] as $middlewares) {
                $groupCount += count($middlewares);
            }
            $stats['group_middleware'] = $groupCount;
        }

        $stats['total_middleware_classes'] = count($usageData);

        // Analyze usage
        $usageStats = [];
        foreach ($usageData as $class => $data) {
            if ($data['usage_count'] > 0 || $data['used_as_global']) {
                $stats['used_middleware']++;
            } else {
                $stats['unused_middleware']++;
                $stats['never_used'][] = [
                    'class' => $class,
                    'name' => $data['middleware']['name'],
                ];
            }

            if ($data['usage_count'] > 0) {
                $usageStats[$class] = [
                    'class' => $class,
                    'name' => $data['middleware']['name'],
                    'usage_count' => $data['usage_count'],
                ];
            }
        }

        // Sort by usage and get top 5
        uasort($usageStats, function (array $a, array $b): int {
            return $b['usage_count'] <=> $a['usage_count'];
        });
        $stats['most_used'] = array_slice($usageStats, 0, 5, true);

        return $stats;
    }

    private function getMiddlewareName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts);
    }
}
