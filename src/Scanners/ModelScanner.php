<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;

final class ModelScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'models';
    }

    public function getDescription(): string
    {
        return 'Scan Laravel Eloquent models and their relationships';
    }

    public function getAvailableOptions(): array
    {
        return [
            'paths' => 'Array of paths to scan for models (default: app/Models)',
            'include_relationships' => 'Include model relationships in results',
            'include_attributes' => 'Include model attributes and fillable fields',
            'include_scopes' => 'Include model scopes',
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);
        $paths = $options['paths'] ?: ['app/Models'];

        $models = [];

        foreach ($paths as $path) {
            $models = array_merge($models, $this->scanPath($path, $options));
        }

        return $this->addMetadata($models, $options);
    }

    private function scanPath(string $path, array $options): array
    {
        $models = [];
        $fullPath = base_path($path);

        if (! File::exists($fullPath)) {
            return $models;
        }

        $files = File::allFiles($fullPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $model = $this->analyzeModelFile($file->getPathname(), $options);
                if ($model !== null && $model !== []) {
                    $models[] = $model;
                }
            }
        }

        return $models;
    }

    private function analyzeModelFile(string $filePath, array $options): ?array
    {
        $content = File::get($filePath);

        // Basic check if file contains a model
        if (in_array(preg_match('/class\s+(\w+)\s+extends\s+Model/', $content, $matches), [0, false], true)) {
            return null;
        }

        $className = $matches[1];
        $namespace = $this->extractNamespace($content);
        $fullClassName = ! in_array($namespace, [null, '', '0'], true) ? $namespace.'\\'.$className : $className;

        try {
            $reflection = new ReflectionClass($fullClassName);

            if (! $reflection->isSubclassOf(Model::class)) {
                return null;
            }

            $modelData = [
                'name' => $className,
                'namespace' => $namespace,
                'full_class' => $fullClassName,
                'file_path' => $filePath,
                'is_abstract' => $reflection->isAbstract(),
            ];

            if ($options['include_attributes'] ?? false) {
                $modelData['attributes'] = $this->getModelAttributes($fullClassName);
            }

            if ($options['include_relationships'] ?? false) {
                $modelData['relationships'] = $this->getModelRelationships($reflection);
            }

            if ($options['include_scopes'] ?? false) {
                $modelData['scopes'] = $this->getModelScopes($reflection);
            }

            return $modelData;

        } catch (Exception $e) {
            return [
                'name' => $className,
                'namespace' => $namespace,
                'full_class' => $fullClassName,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function extractNamespace(string $content): ?string
    {
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            return mb_trim($matches[1]);
        }

        return null;
    }

    private function getModelAttributes(string $className): array
    {
        try {
            $model = new $className();

            return [
                'fillable' => $model->getFillable(),
                'guarded' => $model->getGuarded(),
                'hidden' => $model->getHidden(),
                'casts' => $model->getCasts(),
                'dates' => method_exists($model, 'getDates') ? $model->getDates() : [],
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getModelRelationships(ReflectionClass $reflection): array
    {
        $relationships = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($this->isRelationshipMethod($method)) {
                $relationships[] = [
                    'name' => $method->getName(),
                    'type' => $this->guessRelationshipType(),
                ];
            }
        }

        return $relationships;
    }

    private function getModelScopes(ReflectionClass $reflection): array
    {
        $scopes = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), 'scope')) {
                $scopes[] = [
                    'name' => $method->getName(),
                    'scope_name' => lcfirst(mb_substr($method->getName(), 5)),
                ];
            }
        }

        return $scopes;
    }

    private function isRelationshipMethod(ReflectionMethod $method): bool
    {
        // This is a simplified check - you'd want to analyze the actual method content
        return ! in_array($method->getName(), ['getFillable', 'getGuarded', 'getHidden', 'getCasts']) &&
               ! str_starts_with($method->getName(), 'get') &&
               ! str_starts_with($method->getName(), 'set') &&
               ! str_starts_with($method->getName(), 'scope');
    }

    private function guessRelationshipType(): string
    {
        // This would require actual code analysis to be accurate
        return 'unknown';
    }
}
