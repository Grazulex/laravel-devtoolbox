<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route as RouteFacade;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class SqlAnalysisScanner extends AbstractScanner
{
    private array $queries = [];

    private bool $isListening = false;

    private ?float $startTime = null;

    public function getName(): string
    {
        return 'sql-analysis';
    }

    public function getDescription(): string
    {
        return 'Analyzes SQL queries for N+1 problems, duplicates, and performance issues';
    }

    public function getOptionSchema(): array
    {
        return [
            'route' => ['type' => 'string', 'description' => 'Named route to analyze (route or url is required)'],
            'url' => ['type' => 'string', 'description' => 'URL path to analyze (route or url is required)'],
            'method' => ['type' => 'string', 'description' => 'HTTP method for the request', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'GET'],
            'threshold' => ['type' => 'integer', 'description' => 'Duplicate query threshold', 'default' => 2],
            'auto_explain' => ['type' => 'boolean', 'description' => 'Run EXPLAIN on detected problematic queries', 'default' => false],
        ];
    }

    public function scan(array $options = []): array
    {
        $route = $options['route'] ?? null;
        $url = $options['url'] ?? null;
        $threshold = (int) ($options['threshold'] ?? 2);
        $autoExplain = $options['auto_explain'] ?? false;
        $method = $options['method'] ?? 'GET';

        if (! $route && ! $url) {
            return [
                'error' => 'Either route or url parameter is required',
                'usage' => 'Use --route=route.name or --url=/path/to/endpoint',
            ];
        }

        try {
            $this->startQueryLogging();

            // Execute the request
            $response = $this->executeRequest($route, $url, $method, $options);

            $this->stopQueryLogging();

            // Analyze the collected queries
            $analysis = $this->analyzeQueries($threshold, $autoExplain);

            return [
                'request_info' => [
                    'route' => $route,
                    'url' => $url,
                    'method' => $method,
                    'response_status' => $response->getStatusCode(),
                    'execution_time' => $this->startTime !== null ? round((microtime(true) - $this->startTime) * 1000, 2) : null,
                ],
                'query_analysis' => $analysis,
                'options' => $options,
            ];

        } catch (Exception $e) {
            $this->stopQueryLogging();

            return [
                'error' => 'SQL analysis failed: '.$e->getMessage(),
                'query_analysis' => [],
            ];
        }
    }

    public function logQuery(QueryExecuted $query): void
    {
        $this->queries[] = [
            'sql' => $query->sql,
            'bindings' => $query->bindings,
            'time' => $query->time,
            'connection' => $query->connectionName,
            'executed_at' => microtime(true),
            'normalized_sql' => $this->normalizeSql($query->sql),
        ];
    }

    private function startQueryLogging(): void
    {
        $this->queries = [];
        $this->startTime = microtime(true);

        if (! $this->isListening) {
            Event::listen(QueryExecuted::class, [$this, 'logQuery']);
            $this->isListening = true;
        }
    }

    private function stopQueryLogging(): void
    {
        if ($this->isListening) {
            Event::forget(QueryExecuted::class);
            $this->isListening = false;
        }
    }

    private function executeRequest(?string $route, ?string $url, string $method, array $options): Response
    {
        if (! in_array($route, [null, '', '0'], true)) {
            return $this->executeRouteRequest($route, $method, $options);
        }

        if (! in_array($url, [null, '', '0'], true)) {
            return $this->executeUrlRequest($url, $method, $options);
        }

        throw new InvalidArgumentException('Either route or url parameter is required');
    }

    private function executeRouteRequest(string $routeName, string $method, array $options): Response
    {
        $route = RouteFacade::getRoutes()->getByName($routeName);

        if (! $route) {
            throw new InvalidArgumentException("Route '{$routeName}' not found");
        }

        $uri = $route->uri();

        // Handle route parameters if provided
        if (isset($options['parameters']) && is_array($options['parameters'])) {
            foreach ($options['parameters'] as $key => $value) {
                $uri = str_replace('{'.$key.'}', $value, $uri);
            }
        }

        return $this->executeUrlRequest('/'.mb_ltrim($uri, '/'), $method, $options);
    }

    private function executeUrlRequest(string $url, string $method, array $options): Response
    {
        $request = Request::create($url, $method);

        // Add headers if provided
        if (isset($options['headers']) && is_array($options['headers'])) {
            foreach ($options['headers'] as $key => $value) {
                $request->headers->set($key, $value);
            }
        }

        // Add data if provided
        if (isset($options['data']) && is_array($options['data'])) {
            $request->merge($options['data']);
        }

        // Execute the request through Laravel's kernel
        $kernel = app('Illuminate\Contracts\Http\Kernel');

        return $kernel->handle($request);
    }

    private function analyzeQueries(int $threshold, bool $autoExplain): array
    {
        $analysis = [
            'total_queries' => count($this->queries),
            'total_time' => array_sum(array_column($this->queries, 'time')),
            'duplicate_queries' => [],
            'n_plus_one_suspects' => [],
            'slow_queries' => [],
            'query_breakdown' => [],
            'performance_issues' => [],
        ];

        if ($this->queries === []) {
            return $analysis;
        }

        // Detect duplicate queries
        $analysis['duplicate_queries'] = $this->detectDuplicateQueries($threshold);

        // Detect N+1 queries
        $analysis['n_plus_one_suspects'] = $this->detectNPlusOneQueries();

        // Detect slow queries
        $analysis['slow_queries'] = $this->detectSlowQueries();

        // Generate query breakdown
        $analysis['query_breakdown'] = $this->generateQueryBreakdown();

        // Generate performance recommendations
        $analysis['performance_issues'] = $this->generatePerformanceIssues($analysis);

        // Run EXPLAIN if requested
        if ($autoExplain && $analysis['performance_issues'] !== []) {
            $analysis['explain_results'] = $this->runExplainQueries($analysis);
        }

        return $analysis;
    }

    private function detectDuplicateQueries(int $threshold): array
    {
        $groupedQueries = [];

        foreach ($this->queries as $index => $query) {
            $key = $query['normalized_sql'];
            if (! isset($groupedQueries[$key])) {
                $groupedQueries[$key] = [];
            }
            $groupedQueries[$key][] = array_merge($query, ['index' => $index]);
        }

        $duplicates = [];
        foreach ($groupedQueries as $sql => $queries) {
            if (count($queries) >= $threshold) {
                $duplicates[] = [
                    'sql' => $sql,
                    'count' => count($queries),
                    'total_time' => array_sum(array_column($queries, 'time')),
                    'avg_time' => array_sum(array_column($queries, 'time')) / count($queries),
                    'instances' => array_slice($queries, 0, 5), // Show first 5 instances
                ];
            }
        }

        // Sort by count descending
        usort($duplicates, function (array $a, array $b): int {
            return $b['count'] <=> $a['count'];
        });

        return $duplicates;
    }

    private function detectNPlusOneQueries(): array
    {
        $suspects = [];
        $patterns = [];

        foreach ($this->queries as $index => $query) {
            // Look for SELECT queries with similar patterns
            if (mb_stripos($query['sql'], 'select') === 0) {
                $pattern = preg_replace('/\b\d+\b/', '?', $query['normalized_sql']);
                $pattern = preg_replace('/\bin\s*\([^)]+\)/i', 'in (?)', $pattern);

                if (! isset($patterns[$pattern])) {
                    $patterns[$pattern] = [];
                }
                $patterns[$pattern][] = array_merge($query, ['index' => $index]);
            }
        }

        foreach ($patterns as $pattern => $queries) {
            if (count($queries) > 3) { // Potential N+1 if same pattern executed 4+ times
                $suspects[] = [
                    'pattern' => $pattern,
                    'count' => count($queries),
                    'total_time' => array_sum(array_column($queries, 'time')),
                    'avg_time' => array_sum(array_column($queries, 'time')) / count($queries),
                    'first_query' => $queries[0]['sql'],
                    'suggestion' => 'Consider using eager loading or joins to reduce query count',
                ];
            }
        }

        return $suspects;
    }

    private function detectSlowQueries(): array
    {
        $slowQueries = [];
        $threshold = 100; // 100ms threshold

        foreach ($this->queries as $index => $query) {
            if ($query['time'] > $threshold) {
                $slowQueries[] = array_merge($query, [
                    'index' => $index,
                    'suggestion' => $this->getSlowQuerySuggestion($query),
                ]);
            }
        }

        // Sort by execution time descending
        usort($slowQueries, function (array $a, array $b): int {
            return $b['time'] <=> $a['time'];
        });

        return $slowQueries;
    }

    private function generateQueryBreakdown(): array
    {
        $breakdown = [
            'by_type' => [],
            'by_table' => [],
            'by_connection' => [],
        ];

        foreach ($this->queries as $query) {
            // By query type
            $type = mb_strtoupper(explode(' ', mb_trim($query['sql']))[0]);
            $breakdown['by_type'][$type] = ($breakdown['by_type'][$type] ?? 0) + 1;

            // By table (simplified extraction)
            $tables = $this->extractTables($query['sql']);
            foreach ($tables as $table) {
                $breakdown['by_table'][$table] = ($breakdown['by_table'][$table] ?? 0) + 1;
            }

            // By connection
            $connection = $query['connection'] ?? 'default';
            $breakdown['by_connection'][$connection] = ($breakdown['by_connection'][$connection] ?? 0) + 1;
        }

        return $breakdown;
    }

    private function generatePerformanceIssues(array $analysis): array
    {
        $issues = [];

        if ($analysis['total_queries'] > 50) {
            $issues[] = [
                'type' => 'high_query_count',
                'severity' => 'warning',
                'message' => "High number of queries ({$analysis['total_queries']}) detected",
                'suggestion' => 'Consider using eager loading, caching, or query optimization',
            ];
        }

        if ($analysis['total_time'] > 1000) { // 1 second
            $issues[] = [
                'type' => 'slow_total_time',
                'severity' => 'error',
                'message' => "Total query time ({$analysis['total_time']}ms) is very high",
                'suggestion' => 'Review and optimize slow queries, add database indexes',
            ];
        }

        if (! empty($analysis['duplicate_queries'])) {
            $duplicateCount = array_sum(array_column($analysis['duplicate_queries'], 'count'));
            $issues[] = [
                'type' => 'duplicate_queries',
                'severity' => 'warning',
                'message' => "Found {$duplicateCount} duplicate queries",
                'suggestion' => 'Implement query result caching or optimize query logic',
            ];
        }

        if (! empty($analysis['n_plus_one_suspects'])) {
            $issues[] = [
                'type' => 'n_plus_one',
                'severity' => 'error',
                'message' => 'Potential N+1 query problems detected',
                'suggestion' => 'Use Eloquent eager loading (with()) or database joins',
            ];
        }

        return $issues;
    }

    private function runExplainQueries(array $analysis): array
    {
        $explains = [];

        // Explain slow queries
        foreach (array_slice($analysis['slow_queries'], 0, 3) as $query) {
            try {
                $explainResult = DB::select('EXPLAIN '.$query['sql'], $query['bindings']);
                $explains[] = [
                    'query' => $query['sql'],
                    'explain' => $explainResult,
                    'type' => 'slow_query',
                ];
            } catch (Exception $e) {
                // Skip if EXPLAIN fails
            }
        }

        return $explains;
    }

    private function normalizeSql(string $sql): string
    {
        // Remove extra whitespace
        $sql = preg_replace('/\s+/', ' ', mb_trim($sql));

        // Normalize parameter placeholders
        $sql = preg_replace('/\?/', '?', $sql);

        return $sql;
    }

    private function extractTables(string $sql): array
    {
        $tables = [];

        // Simple regex to extract table names (this is basic and might not catch all cases)
        if (preg_match_all('/(?:from|join|into|update)\s+`?(\w+)`?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        return array_unique($tables);
    }

    private function getSlowQuerySuggestion(array $query): string
    {
        $sql = mb_strtolower($query['sql']);

        if (mb_strpos($sql, 'select') === 0) {
            if (mb_strpos($sql, 'order by') !== false && mb_strpos($sql, 'limit') === false) {
                return 'Consider adding LIMIT to ORDER BY queries';
            }
            if (mb_strpos($sql, 'where') === false) {
                return 'Consider adding WHERE conditions to limit result set';
            }

            return 'Consider adding database indexes for WHERE/JOIN conditions';
        }

        if (mb_strpos($sql, 'insert') === 0) {
            return 'Consider using bulk inserts for better performance';
        }

        return 'Review query structure and add appropriate indexes';
    }
}
