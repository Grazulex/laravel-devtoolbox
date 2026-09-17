<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

final class MiddlewareScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'middleware';
    }

    public function getDescription(): string
    {
        return 'Scan Laravel middleware and their usage';
    }

    public function getOptionSchema(): array
    {
        return [
            'include_usage' => ['type' => 'boolean', 'description' => 'Include middleware usage in routes', 'default' => false],
            'group_by_type' => ['type' => 'boolean', 'description' => 'Group by global, route, and group middleware', 'default' => false],
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);

        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        $globalMiddleware = $this->getGlobalMiddleware($kernel);
        $routeMiddleware = $this->getRouteMiddleware($kernel);
        $middlewareGroups = $this->getMiddlewareGroups($kernel);

        $middlewareList = [];

        // Add global middleware
        foreach ($globalMiddleware as $class) {
            $middlewareList[] = [
                'class' => $class,
                'type' => 'global',
                'alias' => null,
            ];
        }

        // Add route middleware
        foreach ($routeMiddleware as $alias => $class) {
            $middlewareList[] = [
                'class' => $class,
                'type' => 'route',
                'alias' => $alias,
            ];
        }

        // Add middleware groups
        foreach ($middlewareGroups as $group => $classes) {
            foreach ($classes as $class) {
                $middlewareList[] = [
                    'class' => $class,
                    'type' => 'group',
                    'alias' => $group,
                ];
            }
        }

        if ($options['include_usage'] ?? false) {
            // Add usage information if requested
            $usage = $this->getMiddlewareUsage();

            return $this->addMetadata([
                'middleware' => $middlewareList,
                'usage' => $usage,
            ], $options);
        }

        return $this->addMetadata($middlewareList, $options);
    }

    private function getGlobalMiddleware($kernel): array
    {
        return method_exists($kernel, 'getGlobalMiddleware') ?
            $kernel->getGlobalMiddleware() : [];
    }

    private function getRouteMiddleware($kernel): array
    {
        return method_exists($kernel, 'getRouteMiddleware') ?
            $kernel->getRouteMiddleware() : [];
    }

    private function getMiddlewareGroups($kernel): array
    {
        return method_exists($kernel, 'getMiddlewareGroups') ?
            $kernel->getMiddlewareGroups() : [];
    }

    private function getMiddlewareUsage(): array
    {
        // This would scan routes and count middleware usage
        return [
            'total_routes_with_middleware' => 0,
            'most_used_middleware' => [],
        ];
    }
}
