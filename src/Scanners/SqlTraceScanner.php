<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class SqlTraceScanner extends AbstractScanner
{
    private array $queries = [];

    private float $totalTime = 0;

    public function getName(): string
    {
        return 'sql-trace';
    }

    public function getDescription(): string
    {
        return 'Trace SQL queries executed during route or URL execution';
    }

    public function getAvailableOptions(): array
    {
        return [
            'route' => 'Named route to trace',
            'url' => 'URL path to trace',
            'method' => 'HTTP method (default: GET)',
            'parameters' => 'Route parameters as JSON object',
            'headers' => 'Request headers as JSON object',
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);

        $route = $options['route'] ?? null;
        $url = $options['url'] ?? null;
        $method = mb_strtoupper($options['method'] ?? 'GET');
        $parameters = $options['parameters'] ?? [];
        $headers = $options['headers'] ?? [];

        if (! $route && ! $url) {
            // Si aucune route ou URL n'est spécifiée, retourner une structure vide
            $emptyResult = [
                'traced_target' => null,
                'method' => $method,
                'queries' => [],
                'total_queries' => 0,
                'total_time' => 0,
                'slow_queries' => [],
                'duplicate_queries' => [],
                'statistics' => [
                    'average_time' => 0,
                    'min_time' => 0,
                    'max_time' => 0,
                    'connections_used' => [],
                    'query_types' => [],
                ],
            ];

            return $this->addMetadata($emptyResult, $options);
        }

        // Reset query collection
        $this->queries = [];
        $this->totalTime = 0;

        // Enable query logging
        DB::enableQueryLog();

        // Listen for query events
        DB::listen(function ($query): void {
            $this->queries[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
                'connection' => $query->connectionName,
            ];
            $this->totalTime += $query->time;
        });

        try {
            if ($route) {
                $response = $this->traceRoute($route, $method, $parameters, $headers);
            } else {
                $response = $this->traceUrl($url, $method, $parameters, $headers);
            }

            $result = [
                'traced_target' => $route ?? $url,
                'method' => $method,
                'response_status' => $response->getStatusCode(),
                'queries' => $this->queries,
                'total_queries' => count($this->queries),
                'total_time' => $this->totalTime,
                'slow_queries' => $this->getSlowQueries(),
                'duplicate_queries' => $this->getDuplicateQueries(),
                'statistics' => $this->generateStatistics(),
            ];

        } catch (Exception $e) {
            $result = [
                'traced_target' => $route ?? $url,
                'method' => $method,
                'error' => $e->getMessage(),
                'queries' => $this->queries,
                'total_queries' => count($this->queries),
                'total_time' => $this->totalTime,
            ];
        } finally {
            // Disable query logging
            DB::disableQueryLog();
        }

        return $this->addMetadata($result, $options);
    }

    private function traceRoute(string $routeName, string $method, array $parameters, array $headers): Response
    {
        $route = Route::getRoutes()->getByName($routeName);

        if (! $route) {
            throw new InvalidArgumentException("Route '{$routeName}' not found");
        }

        $uri = $route->uri();

        // Replace route parameters
        foreach ($parameters as $key => $value) {
            $uri = str_replace('{'.$key.'}', $value, $uri);
            $uri = str_replace('{'.$key.'?}', $value, $uri);
        }

        return $this->makeRequest($method, $uri, $headers);
    }

    private function traceUrl(string $url, string $method, array $parameters, array $headers): Response
    {
        // Add query parameters for GET requests
        if ($method === 'GET' && $parameters !== []) {
            $url .= '?'.http_build_query($parameters);
        }

        return $this->makeRequest($method, $url, $headers, $parameters);
    }

    private function makeRequest(string $method, string $uri, array $headers, array $data = []): Response
    {
        $request = Request::create($uri, $method, $data, [], [], []);

        // Add custom headers
        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        // Simulate the request through the application
        return app()->handle($request);
    }

    private function getSlowQueries(float $threshold = 100.0): array
    {
        return array_filter($this->queries, function (array $query) use ($threshold): bool {
            return $query['time'] > $threshold;
        });
    }

    private function getDuplicateQueries(): array
    {
        $sqlCounts = [];
        $duplicates = [];

        foreach ($this->queries as $query) {
            $normalizedSql = $this->normalizeSql($query['sql']);

            if (! isset($sqlCounts[$normalizedSql])) {
                $sqlCounts[$normalizedSql] = [];
            }

            $sqlCounts[$normalizedSql][] = $query;
        }

        foreach ($sqlCounts as $sql => $queries) {
            if (count($queries) > 1) {
                $duplicates[] = [
                    'sql' => $sql,
                    'count' => count($queries),
                    'total_time' => array_sum(array_column($queries, 'time')),
                    'queries' => $queries,
                ];
            }
        }

        return $duplicates;
    }

    private function normalizeSql(string $sql): string
    {
        // Remove extra whitespace and normalize for comparison
        return preg_replace('/\s+/', ' ', mb_trim($sql));
    }

    private function generateStatistics(): array
    {
        if ($this->queries === []) {
            return [
                'average_time' => 0,
                'min_time' => 0,
                'max_time' => 0,
                'connections_used' => [],
                'query_types' => [],
            ];
        }

        $times = array_column($this->queries, 'time');
        $connections = array_unique(array_column($this->queries, 'connection'));

        $queryTypes = [];
        foreach ($this->queries as $query) {
            $type = $this->getQueryType($query['sql']);
            $queryTypes[$type] = ($queryTypes[$type] ?? 0) + 1;
        }

        return [
            'average_time' => array_sum($times) / count($times),
            'min_time' => min($times),
            'max_time' => max($times),
            'connections_used' => $connections,
            'query_types' => $queryTypes,
        ];
    }

    private function getQueryType(string $sql): string
    {
        $sql = mb_trim(mb_strtoupper($sql));

        if (str_starts_with($sql, 'SELECT')) {
            return 'SELECT';
        }
        if (str_starts_with($sql, 'INSERT')) {
            return 'INSERT';
        }
        if (str_starts_with($sql, 'UPDATE')) {
            return 'UPDATE';
        }
        if (str_starts_with($sql, 'DELETE')) {
            return 'DELETE';
        }
        if (str_starts_with($sql, 'CREATE')) {
            return 'CREATE';
        }
        if (str_starts_with($sql, 'ALTER')) {
            return 'ALTER';
        }
        if (str_starts_with($sql, 'DROP')) {
            return 'DROP';
        }

        return 'OTHER';
    }
}
