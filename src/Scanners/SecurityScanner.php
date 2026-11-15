<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Illuminate\Support\Facades\Route;
use Throwable;

final class SecurityScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'security';
    }

    public function getDescription(): string
    {
        return 'Scan Laravel application for security vulnerabilities and unprotected routes';
    }

    public function getOptionSchema(): array
    {
        return [
            'check_unprotected_routes' => ['type' => 'boolean', 'description' => 'Check for routes without authentication middleware', 'default' => true],
            'check_csrf_protection' => ['type' => 'boolean', 'description' => 'Check for routes without CSRF protection', 'default' => true],
            'check_throttle' => ['type' => 'boolean', 'description' => 'Check for sensitive routes without throttle middleware', 'default' => false],
            'exclude_patterns' => ['type' => 'array', 'description' => 'Route patterns to exclude from checks'],
            'critical_only' => ['type' => 'boolean', 'description' => 'Show only critical security issues', 'default' => false],
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);

        $result = [
            'unprotected_routes' => [],
            'csrf_vulnerable_routes' => [],
            'security_score' => 0,
            'total_routes' => 0,
        ];

        if ($options['check_unprotected_routes'] ?? true) {
            $result['unprotected_routes'] = $this->findUnprotectedRoutes($options);
        }

        if ($options['check_csrf_protection'] ?? true) {
            $result['csrf_vulnerable_routes'] = $this->findCsrfVulnerableRoutes($options);
        }

        if ($options['check_throttle'] ?? false) {
            $result['unthrottled_routes'] = $this->findUnthrottledRoutes($options);
        }

        $result['total_routes'] = collect(Route::getRoutes()->getRoutes())->count();
        $result['security_score'] = $this->calculateSecurityScore($result);

        return $this->addMetadata($result, $options);
    }

    private function findUnprotectedRoutes(array $options): array
    {
        $unprotectedRoutes = [];
        $excludePatterns = $options['exclude_patterns'] ?? [
            'api/health',
            'api/status',
            '_debugbar',
            'telescope',
            'horizon',
        ];

        $authMiddleware = ['auth', 'auth:api', 'auth:sanctum', 'auth:web'];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->middleware();
            $methods = $route->methods();

            // Skip excluded patterns
            if ($this->shouldExcludeRoute($uri, $excludePatterns)) {
                continue;
            }

            // Skip GET routes to public resources (can be configured)
            if (count($methods) === 1 && in_array('GET', $methods) && $this->isPublicResource($uri, $methods)) {
                continue;
            }

            // Check if route has authentication middleware
            $hasAuth = false;
            foreach ($authMiddleware as $authMid) {
                if (in_array($authMid, $middleware)) {
                    $hasAuth = true;
                    break;
                }
            }

            if (! $hasAuth) {
                $severity = $this->determineRouteSeverity($uri, $methods);

                if (($options['critical_only'] ?? false) && $severity !== 'critical') {
                    continue;
                }

                $unprotectedRoutes[] = [
                    'uri' => $uri,
                    'methods' => $methods,
                    'name' => $route->getName(),
                    'action' => $route->getActionName(),
                    'middleware' => $middleware,
                    'severity' => $severity,
                    'recommendation' => $this->getSecurityRecommendation($uri, $methods),
                ];
            }
        }

        return $unprotectedRoutes;
    }

    private function findCsrfVulnerableRoutes(array $options): array
    {
        $vulnerableRoutes = [];
        $excludePatterns = $options['exclude_patterns'] ?? ['api/*'];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->middleware();
            $methods = $route->methods();

            // Skip excluded patterns
            if ($this->shouldExcludeRoute($uri, $excludePatterns)) {
                continue;
            }

            // Check only POST, PUT, PATCH, DELETE methods
            $dangerousMethods = array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
            if ($dangerousMethods === []) {
                continue;
            }

            // Check if route has CSRF protection
            if (! in_array('web', $middleware) && ! in_array('csrf', $middleware)) {
                $vulnerableRoutes[] = [
                    'uri' => $uri,
                    'methods' => $dangerousMethods,
                    'name' => $route->getName(),
                    'action' => $route->getActionName(),
                    'middleware' => $middleware,
                    'severity' => 'high',
                    'recommendation' => 'Add CSRF protection by using web middleware group or csrf middleware',
                ];
            }
        }

        return $vulnerableRoutes;
    }

    private function findUnthrottledRoutes(array $options): array
    {
        $unthrottledRoutes = [];
        $excludePatterns = $options['exclude_patterns'] ?? [];
        $sensitiveSegments = [
            'login', 'logout', 'register', 'password', 'forgot-password', 'reset-password',
            'verify', 'two-factor', 'otp', 'token', 'oauth',
        ];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $methods = $route->methods();

            if ($this->shouldExcludeRoute($uri, $excludePatterns)) {
                continue;
            }

            $isApi = $uri === 'api' || str_starts_with($uri, 'api/');
            $isSensitive = false;
            foreach ($sensitiveSegments as $segment) {
                if (str_contains($uri, $segment)) {
                    $isSensitive = true;
                    break;
                }
            }

            $dangerousMethods = array_values(array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']));

            // Auth-related routes are flagged for state-changing methods; API routes for any method
            if (! $isApi && ($dangerousMethods === [] || ! $isSensitive)) {
                continue;
            }

            if ($this->hasThrottleMiddleware($route)) {
                continue;
            }

            $category = $isSensitive ? 'authentication' : 'api';

            $unthrottledRoutes[] = [
                'uri' => $uri,
                'methods' => array_values(array_diff($methods, ['HEAD'])),
                'name' => $route->getName(),
                'action' => $route->getActionName(),
                'middleware' => $route->middleware(),
                'category' => $category,
                'severity' => $isSensitive && $dangerousMethods !== [] ? 'high' : 'medium',
                'recommendation' => 'Add throttle middleware (e.g. throttle:6,1) to limit repeated requests',
            ];
        }

        return $unthrottledRoutes;
    }

    private function hasThrottleMiddleware(\Illuminate\Routing\Route $route): bool
    {
        try {
            $middleware = app('router')->gatherRouteMiddleware($route);
        } catch (Throwable) {
            $middleware = $route->middleware();
        }

        foreach ($middleware as $item) {
            if (! is_string($item)) {
                continue;
            }

            if (str_starts_with($item, 'throttle') || str_contains($item, 'ThrottleRequests')) {
                return true;
            }
        }

        return false;
    }

    private function shouldExcludeRoute(string $uri, array $excludePatterns): bool
    {
        foreach ($excludePatterns as $pattern) {
            if (str_contains($uri, $pattern) || fnmatch($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function isPublicResource(string $uri, array $methods): bool
    {
        // Consider GET-only routes to certain paths as potentially public
        $publicPaths = ['/', 'home', 'about', 'contact', 'login', 'register'];

        if (count($methods) === 1 && in_array('GET', $methods)) {
            foreach ($publicPaths as $path) {
                if ($uri === $path || str_starts_with($uri, $path.'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function determineRouteSeverity(string $uri, array $methods): string
    {
        // Critical: admin routes, user data modification
        if (str_contains($uri, 'admin') || str_contains($uri, 'dashboard')) {
            return 'critical';
        }

        // High: POST/PUT/DELETE without auth
        $dangerousMethods = array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
        if ($dangerousMethods !== []) {
            return 'high';
        }

        // Medium: user-specific GET routes
        if (str_contains($uri, 'profile') || str_contains($uri, 'account')) {
            return 'medium';
        }

        return 'low';
    }

    private function getSecurityRecommendation(string $uri, array $methods): string
    {
        if (str_contains($uri, 'admin')) {
            return 'Add auth middleware and consider role-based permissions';
        }

        $dangerousMethods = array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
        if ($dangerousMethods !== []) {
            return 'Add auth middleware to protect data modification endpoints';
        }

        return 'Consider adding auth middleware if this route handles user-specific data';
    }

    private function calculateSecurityScore(array $result): int
    {
        $totalRoutes = $result['total_routes'];
        if ($totalRoutes === 0) {
            return 100;
        }
        $csrfVulnerableCount = count($result['csrf_vulnerable_routes']);

        // Calculate weighted score
        $criticalIssues = 0;
        $highIssues = 0;
        $mediumIssues = 0;

        foreach ($result['unprotected_routes'] as $route) {
            switch ($route['severity']) {
                case 'critical':
                    $criticalIssues++;
                    break;
                case 'high':
                    $highIssues++;
                    break;
                case 'medium':
                    $mediumIssues++;
                    break;
            }
        }

        // Weight: critical = 10, high = 5, medium = 2, csrf = 3
        $totalIssueWeight = ($criticalIssues * 10) + ($highIssues * 5) + ($mediumIssues * 2) + ($csrfVulnerableCount * 3);
        $maxPossibleWeight = $totalRoutes * 10; // Assuming worst case

        $score = max(0, 100 - (($totalIssueWeight / max(1, $maxPossibleWeight)) * 100));

        return (int) round($score);
    }
}
