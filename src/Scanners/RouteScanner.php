<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Illuminate\Support\Facades\Route;

final class RouteScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'routes';
    }

    public function getDescription(): string
    {
        return 'Scan Laravel routes and analyze their usage';
    }

    public function getAvailableOptions(): array
    {
        return [
            'group_by_middleware' => 'Group routes by their middleware',
            'include_parameters' => 'Include route parameters information',
            'detect_unused' => 'Attempt to detect unused routes',
            'filter_methods' => 'Filter by HTTP methods (array)',
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);

        $routes = collect(Route::getRoutes())->map(function ($route) use ($options): array {
            return $this->analyzeRoute($route, $options);
        })->toArray();

        $result = [
            'routes' => $routes,
            'count' => count($routes),
        ];

        if ($options['group_by_middleware'] ?? false) {
            $result['grouped_by_middleware'] = $this->groupByMiddleware($routes);
        }

        if ($options['detect_unused'] ?? false) {
            $unusedRoutes = $this->detectUnusedRoutes($routes);
            $result['unused_routes'] = $unusedRoutes;

            // Mark individual routes as unused
            foreach ($routes as &$route) {
                $route['unused'] = $this->isRouteUnused($route);
            }
            $result['routes'] = $routes;
        }

        return $this->addMetadata($result, $options);
    }

    private function analyzeRoute($route, array $options): array
    {
        $routeData = [
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'methods' => $route->methods(),
            'action' => $route->getActionName(),
            'middleware' => $route->middleware(),
        ];

        if ($options['include_parameters'] ?? false) {
            $routeData['parameters'] = $route->parameterNames();
            $routeData['where_conditions'] = $route->wheres;
        }

        return $routeData;
    }

    private function groupByMiddleware(array $routes): array
    {
        $grouped = [];

        foreach ($routes as $route) {
            $middleware = $route['middleware'] ?? [];

            if (empty($middleware)) {
                $grouped['no_middleware'][] = $route;
            } else {
                foreach ($middleware as $mid) {
                    $grouped[$mid][] = $route;
                }
            }
        }

        return $grouped;
    }

    private function detectUnusedRoutes(array $routes): array
    {
        $unused = [];

        foreach ($routes as $route) {
            if ($this->isRouteUnused($route)) {
                $unused[] = $route;
            }
        }

        return $unused;
    }

    private function isRouteUnused(array $route): bool
    {
        // Skip built-in Laravel routes
        if ($this->isBuiltInRoute($route)) {
            return false;
        }

        // Skip API routes (they might be used by external clients)
        if (str_contains($route['uri'], 'api/')) {
            return false;
        }

        // Heuristics for potentially unused routes:

        // 1. Routes that return static responses without names are suspicious
        if (empty($route['name']) && $this->hasClosureAction($route)) {
            // Check if it's a simple closure returning static content
            return $this->isStaticClosureRoute($route);
        }

        // 2. Routes with specific patterns that suggest they're for testing/legacy
        if ($this->hasUnusedPatterns($route)) {
            return true;
        }
        // 3. Routes without middleware that don't follow RESTful patterns
        return empty($route['middleware']) && $this->isNonStandardRoute($route);
    }

    private function isBuiltInRoute(array $route): bool
    {
        $builtInPatterns = [
            '_ignition',
            'livewire',
            'telescope',
            'horizon',
            'debugbar',
            'sanctum',
        ];

        foreach ($builtInPatterns as $pattern) {
            if (str_contains($route['uri'], $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function hasClosureAction(array $route): bool
    {
        return str_contains($route['action'], 'Closure');
    }

    private function isStaticClosureRoute(array $route): bool
    {
        // Routes that return static views or simple strings are often test routes
        $suspiciousNames = ['test', 'demo', 'sample', 'legacy', 'old', 'unused', 'maintenance'];

        foreach ($suspiciousNames as $name) {
            if (str_contains(mb_strtolower($route['uri']), $name) ||
                str_contains(mb_strtolower($route['name'] ?? ''), $name)) {
                return true;
            }
        }

        return false;
    }

    private function hasUnusedPatterns(array $route): bool
    {
        $unusedPatterns = [
            '/legacy/',
            '/old-',
            '/test',
            '/demo',
            '/sample',
            '/unused',
            '/temp',
            '/debug',
            'old-feature',
            'maintenance',
            'dangerous-action',
        ];

        foreach ($unusedPatterns as $pattern) {
            if (str_contains($route['uri'], $pattern) ||
                str_contains($route['name'] ?? '', $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isNonStandardRoute(array $route): bool
    {
        // Routes without auth middleware that perform dangerous actions
        $dangerousMethods = ['DELETE', 'PUT', 'PATCH'];
        $routeMethods = $route['methods'] ?? [];

        foreach ($dangerousMethods as $method) {
            if (in_array($method, $routeMethods, true)) {
                return true;
            }
        }

        return false;
    }
}
