<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class DatabaseColumnUsageScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'db-column-usage';
    }

    public function getDescription(): string
    {
        return 'Analyze database column usage across the Laravel application codebase';
    }

    public function getOptionSchema(): array
    {
        return [
            'tables' => ['type' => 'array', 'description' => 'Specific tables to analyze'],
            'exclude_tables' => ['type' => 'array', 'description' => 'Tables to exclude from analysis', 'default' => ['migrations', 'password_resets', 'password_reset_tokens', 'personal_access_tokens', 'failed_jobs']],
            'scan_paths' => ['type' => 'array', 'description' => 'Paths to scan for column usage'],
            'unused_only' => ['type' => 'boolean', 'description' => 'Return only unused columns (tables without any are omitted); the summary still covers every column', 'default' => false],
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);

        $tables = $this->getTablesInfo($options);
        $columnUsage = [];

        foreach ($tables as $tableName => $columns) {
            $columnUsage[$tableName] = $this->analyzeTableColumnUsage($tableName, $columns, $options);
        }

        $summary = $this->generateSummary($columnUsage);

        if ($options['unused_only'] ?? false) {
            $columnUsage = $this->onlyUnused($columnUsage);
        }

        $result = [
            'column_usage' => $columnUsage,
            'summary' => $summary,
        ];

        return $this->addMetadata($result, $options);
    }

    /**
     * Keeps only the unused columns of each table, dropping tables without any.
     */
    private function onlyUnused(array $columnUsage): array
    {
        $unused = [];

        foreach ($columnUsage as $tableName => $columns) {
            $unusedColumns = array_filter($columns, fn (array $info): bool => ! $info['used']);

            if ($unusedColumns !== []) {
                $unused[$tableName] = $unusedColumns;
            }
        }

        return $unused;
    }

    private function getTablesInfo(array $options): array
    {
        $tables = [];
        $specificTables = $options['tables'] ?? [];
        $excludeTables = $options['exclude_tables'] ?? [
            'migrations',
            'password_resets',
            'password_reset_tokens',
            'personal_access_tokens',
            'failed_jobs',
        ];

        // Get all table names using database-specific query
        $allTables = $this->getAllTableNames();

        foreach ($allTables as $tableName) {
            // Skip excluded tables
            if (in_array($tableName, $excludeTables)) {
                continue;
            }

            // If specific tables are specified, only analyze those
            if (! empty($specificTables) && ! in_array($tableName, $specificTables)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($tableName);
                $tables[$tableName] = $columns;
            } catch (Exception $e) {
                // Skip tables that can't be read
                continue;
            }
        }

        return $tables;
    }

    private function getAllTableNames(): array
    {
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}");

        try {
            switch ($connection['driver'] ?? 'mysql') {
                case 'sqlite':
                    $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

                    return array_map(fn ($table) => $table->name, $tables);

                case 'pgsql':
                    $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");

                    return array_map(fn ($table) => $table->tablename, $tables);

                case 'mysql':
                default:
                    $databaseName = $connection['database'] ?? '';
                    $tables = DB::select('SHOW TABLES');
                    $tableColumnName = 'Tables_in_'.$databaseName;

                    return array_map(fn ($table) => $table->$tableColumnName ?? $table->{'Tables_in_'.mb_strtolower($databaseName)}, $tables);
            }
        } catch (Exception $e) {
            // Fallback: return empty array if database queries fail
            return [];
        }
    }

    private function analyzeTableColumnUsage(string $tableName, array $columns, array $options): array
    {
        $columnUsage = [];
        $defaultPaths = [
            app_path(),
            resource_path('views'),
            database_path('migrations'),
        ];

        // Filter paths to only include those that exist
        $scanPaths = $options['scan_paths'] ?? array_filter($defaultPaths, fn (string $path) => File::exists($path));

        foreach ($columns as $columnName) {
            $usage = $this->findColumnUsage($tableName, $columnName, $scanPaths);

            $columnUsage[$columnName] = [
                'used' => ! empty($usage['files']),
                'usage_count' => count($usage['files']),
                'files' => $usage['files'],
                'model_info' => $usage['model_info'],
                'is_fillable' => $usage['is_fillable'],
                'is_hidden' => $usage['is_hidden'],
                'is_casted' => $usage['is_casted'],
                'recommendations' => $this->getColumnRecommendations($columnName, $usage),
            ];
        }

        return $columnUsage;
    }

    private function findColumnUsage(string $tableName, string $columnName, array $scanPaths): array
    {
        $files = [];
        $modelInfo = [];

        foreach ($scanPaths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            $foundFiles = $this->scanDirectoryForColumn($path, $columnName);
            $files = array_merge($files, $foundFiles);
        }

        // Get model-specific information
        $modelClass = $this->findModelForTable($tableName);
        if (! in_array($modelClass, [null, '', '0'], true)) {
            $modelInfo = $this->analyzeModelColumnUsage($modelClass, $columnName);
        }

        return [
            'files' => $this->removeDuplicateFiles($files),
            'model_info' => $modelInfo,
            'is_fillable' => $modelInfo['is_fillable'] ?? false,
            'is_hidden' => $modelInfo['is_hidden'] ?? false,
            'is_casted' => $modelInfo['is_casted'] ?? false,
        ];
    }

    private function removeDuplicateFiles(array $files): array
    {
        $uniqueFiles = [];
        $seenPaths = [];

        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            if (! in_array($path, $seenPaths)) {
                $uniqueFiles[] = $file;
                $seenPaths[] = $path;
            }
        }

        return $uniqueFiles;
    }

    private function scanDirectoryForColumn(string $path, string $columnName): array
    {
        $files = [];
        $allFiles = File::allFiles($path);

        foreach ($allFiles as $file) {
            $extension = $file->getExtension();

            // Skip non-relevant files
            if (! in_array($extension, ['php', 'blade.php'])) {
                continue;
            }

            $content = File::get($file->getPathname());

            if ($this->contentContainsColumn($content, $columnName)) {
                $files[] = [
                    'path' => $file->getPathname(),
                    'relative_path' => str_replace(base_path().'/', '', $file->getPathname()),
                    'type' => $this->determineFileType($file->getPathname()),
                    'matches' => $this->findColumnMatches($content, $columnName),
                ];
            }
        }

        return $files;
    }

    private function contentContainsColumn(string $content, string $columnName): bool
    {
        // Various patterns to look for column usage
        $patterns = [
            // Direct column reference in queries
            "/['\"]".preg_quote($columnName, '/')."['\"]/",
            // Eloquent attribute access
            '/->'.preg_quote($columnName, '/').'[^a-zA-Z0-9_]/',
            "/\\['".preg_quote($columnName, '/')."'\\]/",
            '/\\["'.preg_quote($columnName, '/').'"\\]/',
            // Form fields
            "/name=['\"]".preg_quote($columnName, '/')."['\"]/",
            // Database query methods
            "/where\\s*\\(\\s*['\"]".preg_quote($columnName, '/')."['\"]/",
            "/select\\s*\\(\\s*['\"]".preg_quote($columnName, '/')."['\"]/",
            "/orderBy\\s*\\(\\s*['\"]".preg_quote($columnName, '/')."['\"]/",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    private function findColumnMatches(string $content, string $columnName): array
    {
        $matches = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            if (str_contains($line, $columnName)) {
                $matches[] = [
                    'line' => $lineNumber + 1,
                    'code' => mb_trim($line),
                    'context' => $this->determineUsageContext($line),
                ];
            }
        }

        return $matches;
    }

    private function findModelForTable(string $tableName): ?string
    {
        // Try to find corresponding model
        $modelName = str_replace('_', '', ucwords($tableName, '_'));
        $modelName = mb_rtrim($modelName, 's'); // Remove trailing 's' for plural tables

        $possiblePaths = [];

        // Only add paths that might exist
        try {
            if (function_exists('app_path')) {
                $possiblePaths[] = app_path("Models/{$modelName}.php");
                $possiblePaths[] = app_path("{$modelName}.php");
            }
        } catch (Exception $e) {
            // app_path() function not available or app path doesn't exist
        }

        foreach ($possiblePaths as $path) {
            if (File::exists($path)) {
                $namespace = $this->extractNamespace(File::get($path));

                return ! in_array($namespace, [null, '', '0'], true) ? "{$namespace}\\{$modelName}" : "App\\{$modelName}";
            }
        }

        return null;
    }

    private function analyzeModelColumnUsage(string $modelClass, string $columnName): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        try {
            $model = new $modelClass;

            return [
                'is_fillable' => in_array($columnName, $model->getFillable()),
                'is_hidden' => in_array($columnName, $model->getHidden()),
                'is_casted' => array_key_exists($columnName, $model->getCasts()),
                'cast_type' => $model->getCasts()[$columnName] ?? null,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    private function determineFileType(string $filePath): string
    {
        if (str_contains($filePath, '/Models/')) {
            return 'model';
        }
        if (str_contains($filePath, '/Controllers/')) {
            return 'controller';
        }
        if (str_contains($filePath, '/migrations/')) {
            return 'migration';
        }
        if (str_contains($filePath, '/views/')) {
            return 'view';
        }
        if (str_contains($filePath, '/tests/')) {
            return 'test';
        }

        return 'other';
    }

    private function determineUsageContext(string $line): string
    {
        if (preg_match('/where\s*\(/', $line)) {
            return 'query_where';
        }
        if (preg_match('/select\s*\(/', $line)) {
            return 'query_select';
        }
        if (preg_match('/->/', $line)) {
            return 'attribute_access';
        }
        if (preg_match('/\$fillable/', $line)) {
            return 'model_fillable';
        }
        if (preg_match('/name\s*=/', $line)) {
            return 'form_field';
        }

        return 'general';
    }

    private function extractNamespace(string $content): ?string
    {
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getColumnRecommendations(string $columnName, array $usage): array
    {
        $recommendations = [];

        if (empty($usage['files'])) {
            $recommendations[] = "Column '{$columnName}' appears to be unused - consider removing it";
        }

        if (! $usage['is_fillable'] && ! in_array($columnName, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
            $recommendations[] = "Consider adding '{$columnName}' to model's \$fillable array if it should be mass assignable";
        }

        if (! $usage['is_casted'] && $this->shouldBeCasted($columnName)) {
            $recommendations[] = "Consider adding a cast for '{$columnName}' in the model";
        }

        return $recommendations;
    }

    private function shouldBeCasted(string $columnName): bool
    {
        $castSuggestions = [
            'json' => ['_json', 'meta', 'settings', 'config', 'data'],
            'boolean' => ['is_', 'has_', 'can_', 'should_'],
            'datetime' => ['_at', '_date', '_time'],
            'decimal' => ['price', 'amount', 'cost', 'total'],
        ];

        foreach ($castSuggestions as $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($columnName, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function generateSummary(array $columnUsage): array
    {
        $totalColumns = 0;
        $usedColumns = 0;
        $unusedColumns = 0;
        $tablesSummary = [];

        foreach ($columnUsage as $tableName => $columns) {
            $tableUsed = 0;
            $tableUnused = 0;

            foreach ($columns as $info) {
                $totalColumns++;
                if ($info['used']) {
                    $usedColumns++;
                    $tableUsed++;
                } else {
                    $unusedColumns++;
                    $tableUnused++;
                }
            }

            $tablesSummary[$tableName] = [
                'total' => count($columns),
                'used' => $tableUsed,
                'unused' => $tableUnused,
                'usage_percentage' => $tableUsed > 0 ? round(($tableUsed / count($columns)) * 100, 2) : 0,
            ];
        }

        return [
            'total_columns' => $totalColumns,
            'used_columns' => $usedColumns,
            'unused_columns' => $unusedColumns,
            'usage_percentage' => $totalColumns > 0 ? round(($usedColumns / $totalColumns) * 100, 2) : 0,
            'tables_summary' => $tablesSummary,
        ];
    }
}
