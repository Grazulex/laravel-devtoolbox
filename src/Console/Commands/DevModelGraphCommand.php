<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Commands;

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Illuminate\Console\Command;

final class DevModelGraphCommand extends Command
{
    protected $signature = 'dev:model:graph 
                            {--format=table : Output format (table, json, mermaid)}
                            {--output= : Save output to file}
                            {--direction=TB : Graph direction (TB, BT, LR, RL)}';

    protected $description = 'Generate a graph of model relationships';

    public function handle(DevtoolboxManager $manager): int
    {
        $format = $this->option('format');
        $output = $this->option('output');
        $direction = $this->option('direction');

        // Only show progress message if not outputting JSON or Mermaid directly
        if (! in_array($format, ['json', 'mermaid'], true)) {
            $this->info('Generating model relationship graph...');
        }

        $modelData = $manager->scan('models', [
            'include_relationships' => true,
        ]);

        $result = $format === 'mermaid' ? $this->generateMermaidGraph($modelData, $direction) : $modelData;

        if ($output) {
            if ($format === 'mermaid') {
                file_put_contents($output, $result);
            } else {
                file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT));
            }

            if (! in_array($format, ['json', 'mermaid'], true)) {
                $this->info("Graph saved to: {$output}");
            }
        } elseif ($format === 'json') {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } elseif ($format === 'mermaid') {
            $this->line($result);
        } else {
            $this->displayResults($result);
        }

        return self::SUCCESS;
    }

    private function displayResults(array $result): void
    {
        $data = $result['data'] ?? [];
        $this->line('Found '.count($data).' models with relationships:');
        $this->newLine();

        foreach ($data as $model) {
            $modelName = $model['name'] ?? 'Unknown';
            $this->line("📄 {$modelName}");

            if (isset($model['relationships']) && ! empty($model['relationships'])) {
                foreach ($model['relationships'] as $relationshipName => $relationshipData) {
                    $type = $relationshipData['type'] ?? 'unknown';
                    $related = $relationshipData['related'] ?? 'unknown';
                    $this->line("   → {$relationshipName}: {$type} ({$related})");
                }
            } else {
                $this->line('   (no relationships found)');
            }
            $this->newLine();
        }
    }

    private function generateMermaidGraph(array $modelData, string $direction): string
    {
        $data = $modelData['data'] ?? [];

        $mermaid = "graph {$direction}\n";
        $relationships = [];

        // Process each model and its relationships
        foreach ($data as $model) {
            $modelName = $this->sanitizeNodeName($model['name'] ?? 'Unknown');

            // Define the model node
            $mermaid .= "    {$modelName}[{$model['name']}]\n";

            if (isset($model['relationships']) && ! empty($model['relationships'])) {
                foreach ($model['relationships'] as $key => $relationshipData) {
                    // Handle both array structures: indexed array and associative array
                    if (is_string($key)) {
                        // Associative array case (test data format)
                        $relationshipName = $key;
                        $relatedModel = $this->sanitizeNodeName($relationshipData['related'] ?? 'Unknown');
                        $type = $relationshipData['type'] ?? 'unknown';
                    } else {
                        // Indexed array case (ModelScanner format)
                        $relationshipName = $relationshipData['name'] ?? 'unknown';
                        $relatedModel = $this->sanitizeNodeName($relationshipData['related'] ?? 'Unknown');
                        $type = $relationshipData['type'] ?? 'unknown';
                    }

                    // Create relationship key to avoid duplicates
                    $relationshipKey = "{$modelName}|{$relatedModel}|{$type}";

                    if (! in_array($relationshipKey, $relationships, true)) {
                        $relationships[] = $relationshipKey;

                        // Generate relationship line based on type
                        $relationshipLine = $this->getMermaidRelationship($modelName, $relatedModel, $type, $relationshipName);
                        $mermaid .= $relationshipLine;
                    }
                }
            }
        }

        return $mermaid;
    }

    private function sanitizeNodeName(string $name): string
    {
        // Remove namespace and special characters to create valid Mermaid node names
        $name = class_basename($name);

        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    private function getMermaidRelationship(string $from, string $to, string $type, string $relationshipName): string
    {
        // Create different arrow types based on relationship type
        switch (mb_strtolower($type)) {
            case 'hasone':
                return "    {$from} ||--|| {$to} : {$relationshipName}\n";
            case 'hasmany':
                return "    {$from} ||--o{ {$to} : {$relationshipName}\n";
            case 'belongsto':
                return "    {$from} }o--|| {$to} : {$relationshipName}\n";
            case 'belongstomany':
                return "    {$from} }o--o{ {$to} : {$relationshipName}\n";
            case 'hasmanythrrough':
            case 'hasmanythrough':
                return "    {$from} ||..o{ {$to} : {$relationshipName}\n";
            case 'morphto':
            case 'morphone':
            case 'morphmany':
                return "    {$from} ||~~|| {$to} : {$relationshipName}\n";
            default:
                return "    {$from} --> {$to} : {$relationshipName}\n";
        }
    }
}
