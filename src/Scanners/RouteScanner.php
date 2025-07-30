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
            'strict_unused_detection' => 'Use strict detection (flags unprotected API routes too)',
            'exclude_api_routes' => 'Exclude API routes from unused detection',
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
            $unusedRoutes = $this->detectUnusedRoutes($routes, $options);
            $result['unused_routes'] = $unusedRoutes;

            // Mark individual routes as unused
            foreach ($routes as &$route) {
                $route['unused'] = $this->isRouteUnused($route, $options);
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

    private function detectUnusedRoutes(array $routes, array $options): array
    {
        $unused = [];

        foreach ($routes as $route) {
            if ($this->isRouteUnused($route, $options)) {
                $unused[] = $route;
            }
        }

        return $unused;
    }

    private function isRouteUnused(array $route, array $options = []): bool
    {
        // Skip built-in Laravel routes
        if ($this->isBuiltInRoute($route)) {
            return false;
        }

        // Handle API routes first
        if (str_contains($route['uri'], 'api/')) {
            if ($options['exclude_api_routes'] ?? false) {
                return false; // Completely exclude API routes
            }

            // In security-focused mode, check if API route has security issues
            if ($options['security_focused'] ?? false) {
                if ($this->isDangerousDebugRoute($route)) {
                    return true;
                }

                return $this->isUnprotectedDangerousRoute($route);
            }

            // In strict mode, flag unprotected API routes with dangerous methods
            if ($options['strict_unused_detection'] ?? false) {
                return $this->isUnprotectedDangerousRoute($route);
            }
            // By default, flag API routes with obvious unused patterns OR unprotected dangerous routes
            if ($this->hasUnusedPatterns($route)) {
                return true;
            }

            return $this->isUnprotectedDangerousRoute($route);
        }

        // In security-focused mode, prioritize security issues
        if ($options['security_focused'] ?? false) {
            if ($this->isDangerousDebugRoute($route)) {
                return true;
            }
            if ($this->isUnprotectedDangerousRoute($route)) {
                return true;
            }

            return $this->isUnprotectedAdminRoute($route);
        }

        // Check for obvious unused patterns first
        if ($this->hasUnusedPatterns($route)) {
            return true;
        }

        // Check for debug/dangerous routes (these should be flagged as potentially unused/dangerous)
        if ($this->isDangerousDebugRoute($route)) {
            return true;
        }

        // Routes that return static responses without names are suspicious
        if (empty($route['name']) && $this->hasClosureAction($route)) {
            // Check if it's a simple closure returning static content
            return $this->isStaticClosureRoute($route);
        }

        // Check for routes without proper protection (especially dangerous methods)
        if ($this->isUnprotectedDangerousRoute($route)) {
            return true;
        }

        // Check for administrative routes without authentication
        return $this->isUnprotectedAdminRoute($route);
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
            'old-feature',
            'maintenance',
            'dangerous-action',
            'legacy.',
            'unused.',
            'legacy-endpoint',
        ];

        foreach ($unusedPatterns as $pattern) {
            if (str_contains($route['uri'], $pattern) ||
                str_contains($route['name'] ?? '', $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isDangerousDebugRoute(array $route): bool
    {
        $debugPatterns = [
            '/debug',
            '/debugbar',
            '/telescope',
            '/horizon',
            'debug.', // for route names like debug.info
        ];

        // Check if it's a debug route
        $isDebugRoute = false;
        foreach ($debugPatterns as $pattern) {
            if (str_contains($route['uri'], $pattern) ||
                str_contains($route['name'] ?? '', $pattern)) {
                $isDebugRoute = true;
                break;
            }
        }

        if (! $isDebugRoute) {
            return false;
        }

        // If it's a debug route but has no authentication middleware, it's dangerous
        $middleware = $route['middleware'] ?? [];
        $authMiddleware = ['auth', 'auth:api', 'auth:sanctum', 'auth:web'];

        foreach ($authMiddleware as $auth) {
            if (in_array($auth, $middleware)) {
                return false; // Protected debug route is OK
            }
        }

        return true; // Unprotected debug route is dangerous
    }

    private function isUnprotectedDangerousRoute(array $route): bool
    {
        $dangerousMethods = ['DELETE', 'PUT', 'PATCH', 'POST'];
        $routeMethods = $route['methods'] ?? [];
        $middleware = $route['middleware'] ?? [];

        // Check if route uses dangerous HTTP methods
        $hasDangerousMethod = false;
        foreach ($dangerousMethods as $method) {
            if (in_array($method, $routeMethods, true)) {
                $hasDangerousMethod = true;
                break;
            }
        }

        if (! $hasDangerousMethod) {
            return false;
        }

        // Check if route has any protection middleware
        $protectionMiddleware = ['auth', 'auth:api', 'auth:sanctum', 'auth:web', 'csrf', 'web'];

        foreach ($protectionMiddleware as $protection) {
            if (in_array($protection, $middleware)) {
                return false; // Route is protected
            }
        }

        return true; // Dangerous method without protection
    }

    private function isUnprotectedAdminRoute(array $route): bool
    {
        $adminPatterns = [
            '/admin',
            '/dashboard',
            '/settings',
            'admin.',
            'dashboard.',
            'settings.',
        ];

        // Check if it's an admin route
        $isAdminRoute = false;
        foreach ($adminPatterns as $pattern) {
            if (str_contains($route['uri'], $pattern) ||
                str_contains($route['name'] ?? '', $pattern)) {
                $isAdminRoute = true;
                break;
            }
        }

        if (! $isAdminRoute) {
            return false;
        }

        // If it's an admin route but has no authentication middleware, it's a problem
        $middleware = $route['middleware'] ?? [];
        $authMiddleware = ['auth', 'auth:api', 'auth:sanctum', 'auth:web'];

        foreach ($authMiddleware as $auth) {
            if (in_array($auth, $middleware)) {
                return false; // Protected admin route is OK
            }
        }

        return true; // Unprotected admin route is a security issue
    }
}
