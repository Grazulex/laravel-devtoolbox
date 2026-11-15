<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
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
            'check_authorization' => ['type' => 'boolean', 'description' => 'Check can:/authorize routes and route-bound models for missing policies', 'default' => false],
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

        if ($options['check_authorization'] ?? false) {
            $result['authorization_issues'] = $this->findAuthorizationIssues($options);
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

    private function findAuthorizationIssues(array $options): array
    {
        $issues = [];
        $excludePatterns = $options['exclude_patterns'] ?? [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if ($this->shouldExcludeRoute($uri, $excludePatterns)) {
                continue;
            }

            $boundModels = $this->getRouteBoundModels($route);
            $checkedModels = [];

            foreach ($this->getAuthorizationMiddleware($route) as $entry) {
                $ability = $entry['ability'];

                foreach ($entry['arguments'] as $argument) {
                    $model = $boundModels[$argument] ?? (class_exists($argument) && is_subclass_of($argument, Model::class) ? $argument : null);

                    if ($model === null) {
                        continue;
                    }

                    $checkedModels[$model] = true;
                    $state = $this->inspectPolicy($model);

                    if ($state['status'] === 'missing') {
                        $issues[] = $this->buildAuthorizationIssue($route, 'missing_policy', $model, $ability, 'high', "No policy found for {$model}; the can:{$ability} check can never pass through a policy. Create and register a policy.");
                    } elseif ($state['status'] === 'broken') {
                        $issues[] = $this->buildAuthorizationIssue($route, 'nonexistent_policy', $model, $ability, 'high', "The policy registered for {$model} cannot be resolved ({$state['policy']}). Fix the policy class or its registration.");
                    } elseif ($state['status'] === 'found' && ! $this->policyHandlesAbility($state['policy'], $ability)) {
                        $issues[] = $this->buildAuthorizationIssue($route, 'missing_ability', $model, $ability, 'medium', "Policy {$state['policy']} has no '{$ability}' method and no gate is defined with that name.");
                    }
                }
            }

            foreach ($boundModels as $model) {
                if (isset($checkedModels[$model])) {
                    continue;
                }

                $checkedModels[$model] = true;

                if ($this->inspectPolicy($model)['status'] !== 'found') {
                    $issues[] = $this->buildAuthorizationIssue($route, 'model_without_policy', $model, null, 'medium', "Model {$model} is exposed by this route but has no usable policy.");
                }
            }
        }

        return $issues;
    }

    private function buildAuthorizationIssue(\Illuminate\Routing\Route $route, string $type, string $model, ?string $ability, string $severity, string $recommendation): array
    {
        return [
            'uri' => $route->uri(),
            'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
            'name' => $route->getName(),
            'action' => $route->getActionName(),
            'type' => $type,
            'model' => $model,
            'ability' => $ability,
            'severity' => $severity,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @return array<int, array{ability: string, arguments: array<int, string>}>
     */
    private function getAuthorizationMiddleware(\Illuminate\Routing\Route $route): array
    {
        try {
            $middleware = app('router')->gatherRouteMiddleware($route);
        } catch (Throwable) {
            $middleware = $route->middleware();
        }

        $entries = [];

        foreach ($middleware as $item) {
            if (! is_string($item)) {
                continue;
            }

            if (str_starts_with($item, 'can:')) {
                $parameters = mb_substr($item, 4);
            } elseif (str_starts_with($item, Authorize::class.':')) {
                $parameters = mb_substr($item, mb_strlen(Authorize::class) + 1);
            } else {
                continue;
            }

            $parts = array_values(array_filter(array_map('trim', explode(',', $parameters)), fn (string $part): bool => $part !== ''));

            if ($parts === []) {
                continue;
            }

            $entries[] = [
                'ability' => array_shift($parts),
                'arguments' => $parts,
            ];
        }

        return $entries;
    }

    /**
     * Map route parameter names to the Eloquent model class they are type-hinted with.
     *
     * @return array<string, class-string>
     */
    private function getRouteBoundModels(\Illuminate\Routing\Route $route): array
    {
        try {
            $parameters = $route->signatureParameters(['subClass' => Model::class]);
        } catch (Throwable) {
            return [];
        }

        $models = [];

        foreach ($parameters as $parameter) {
            $class = Reflector::getParameterClassName($parameter);

            if ($class !== null && is_subclass_of($class, Model::class)) {
                $models[$parameter->getName()] = $class;
            }
        }

        return $models;
    }

    /**
     * @return array{status: string, policy: string|null}
     */
    private function inspectPolicy(string $model): array
    {
        try {
            $policy = Gate::getPolicyFor($model);
        } catch (Throwable) {
            $registered = Gate::policies()[$model] ?? null;

            return ['status' => 'broken', 'policy' => is_string($registered) ? $registered : null];
        }

        if ($policy === null || ! is_object($policy)) {
            return ['status' => 'missing', 'policy' => null];
        }

        return ['status' => 'found', 'policy' => $policy::class];
    }

    private function policyHandlesAbility(?string $policy, string $ability): bool
    {
        if (Gate::has($ability)) {
            return true;
        }

        if ($policy === null) {
            return false;
        }

        return method_exists($policy, $ability) || method_exists($policy, Str::camel($ability));
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
