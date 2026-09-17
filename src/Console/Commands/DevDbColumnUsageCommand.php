<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Exception;
use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevDbColumnUsageCommand extends Command
{
    protected $signature = 'dev:db:column-usage 
                            {--table=* : Specific tables to analyze}
                            {--exclude=* : Tables to exclude from analysis}
                            {--unused-only : Show only unused columns}
                            {--format=table : Output format (table, json)}
                            {--output= : Output file path}';

    protected $description = 'Analyze database column usage across the Laravel application codebase';

    public function handle(DevtoolboxManager $manager): int
    {
        $tables = $this->option('table');
        $exclude = $this->option('exclude');
        $unusedOnly = $this->option('unused-only');
        $format = $this->option('format');
        $output = $this->option('output');

        // Only show progress message if not outputting JSON directly
        if ($format !== 'json') {
            $this->info('📊 Analyzing database column usage...');
        }

        try {
            $result = $manager->scan('db-column-usage', [
                'tables' => $tables,
                'exclude_tables' => $exclude,
                'unused_only' => $unusedOnly,
            ]);

            if ($output) {
                file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT));
                if ($format !== 'json') {
                    $this->info("Results saved to: {$output}");
                }
            } elseif ($format === 'json') {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
            } else {
                $this->displayResults($result, $unusedOnly);
            }

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Error analyzing column usage: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function displayResults(array $result, bool $unusedOnly): void
    {
        $data = $result['data'] ?? [];
        $columnUsage = $data['column_usage'] ?? [];
        $summary = $data['summary'] ?? [];

        // Display summary first
        $this->displaySummary($summary);
        $this->newLine();

        // Display table analysis
        foreach ($columnUsage as $tableName => $columns) {
            $this->displayTableAnalysis($tableName, $columns, $unusedOnly);
        }

        // Display recommendations
        $this->displayRecommendations($columnUsage);
    }

    private function displaySummary(array $summary): void
    {
        $totalColumns = $summary['total_columns'] ?? 0;
        $usedColumns = $summary['used_columns'] ?? 0;
        $unusedColumns = $summary['unused_columns'] ?? 0;
        $usagePercentage = $summary['usage_percentage'] ?? 0;

        $this->info('📈 Database Column Usage Summary:');
        $this->line("   • Total Columns: {$totalColumns}");
        $this->line("   • Used Columns: {$usedColumns}");
        $this->line("   • Unused Columns: {$unusedColumns}");

        $color = $usagePercentage >= 80 ? 'green' : ($usagePercentage >= 60 ? 'yellow' : 'red');
        $this->line("   • <fg={$color}>Usage Rate: {$usagePercentage}%</>");
    }

    private function displayTableAnalysis(string $tableName, array $columns, bool $unusedOnly): void
    {
        $usedCount = 0;
        $unusedCount = 0;

        foreach ($columns as $info) {
            if ($info['used']) {
                $usedCount++;
            } else {
                $unusedCount++;
            }
        }

        // Skip tables with no unused columns if unusedOnly is true
        if ($unusedOnly && $unusedCount === 0) {
            return;
        }

        $this->line("🗂️  <fg=cyan>Table: {$tableName}</>");
        $this->line("   📊 Used: {$usedCount} | Unused: {$unusedCount}");
        $this->newLine();

        foreach ($columns as $columnName => $info) {
            // Skip used columns if only showing unused
            if ($unusedOnly && $info['used']) {
                continue;
            }

            $this->displayColumnInfo($columnName, $info);
        }

        $this->newLine();
    }

    private function displayColumnInfo(string $columnName, array $info): void
    {
        $status = $info['used'] ? '✅' : '❌';
        $usageCount = $info['usage_count'];

        $this->line("   {$status} <fg=white>{$columnName}</> (used in {$usageCount} files)");

        // Model information
        $modelInfo = $info['model_info'];
        if (! empty($modelInfo)) {
            $fillableStatus = $info['is_fillable'] ? '✅' : '❌';
            $hiddenStatus = $info['is_hidden'] ? '🔒' : '🔓';
            $castedStatus = $info['is_casted'] ? '🔄' : '➖';

            $this->line("      📝 Fillable: {$fillableStatus} | Hidden: {$hiddenStatus} | Casted: {$castedStatus}");
        }

        // File usage
        if (! empty($info['files'])) {
            $this->line('      📁 Used in:');
            foreach (array_slice($info['files'], 0, 3) as $file) { // Show first 3 files
                $relativePath = $file['relative_path'];
                $fileType = $file['type'];
                $this->line("         • {$relativePath} ({$fileType})");
            }

            if (count($info['files']) > 3) {
                $remaining = count($info['files']) - 3;
                $this->line("         ... and {$remaining} more files");
            }
        }

        // Recommendations
        if (! empty($info['recommendations'])) {
            $this->line('      💡 Recommendations:');
            foreach ($info['recommendations'] as $recommendation) {
                $this->line("         • {$recommendation}");
            }
        }

        $this->newLine();
    }

    private function displayRecommendations(array $columnUsage): void
    {
        $allRecommendations = [];
        $unusedColumns = [];

        foreach ($columnUsage as $tableName => $columns) {
            foreach ($columns as $columnName => $info) {
                if (! $info['used']) {
                    $unusedColumns[] = "{$tableName}.{$columnName}";
                }

                if (! empty($info['recommendations'])) {
                    $allRecommendations = array_merge($allRecommendations, $info['recommendations']);
                }
            }
        }

        if ($unusedColumns !== [] || $allRecommendations !== []) {
            $this->info('🔧 Action Items:');

            if ($unusedColumns !== []) {
                $this->line('');
                $this->warn('⚠️  Unused Columns (consider removing):');
                foreach (array_slice($unusedColumns, 0, 10) as $column) {
                    $this->line("   • {$column}");
                }

                if (count($unusedColumns) > 10) {
                    $remaining = count($unusedColumns) - 10;
                    $this->line("   ... and {$remaining} more");
                }
            }

            if ($allRecommendations !== []) {
                $uniqueRecommendations = array_unique($allRecommendations);
                $this->line('');
                $this->info('💡 General Recommendations:');
                foreach (array_slice($uniqueRecommendations, 0, 5) as $recommendation) {
                    $this->line("   • {$recommendation}");
                }
            }
        }
    }
}
