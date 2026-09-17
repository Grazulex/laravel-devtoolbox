<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Illuminate\Support\Facades\File;

final class ModelUsageScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'model-usage';
    }

    public function getDescription(): string
    {
        return 'Scan for usage of a specific model throughout the application';
    }

    public function getOptionSchema(): array
    {
        return [
            'model' => ['type' => 'string', 'description' => 'The model class name or path to analyze', 'required' => true],
            'scan_controllers' => ['type' => 'boolean', 'description' => 'Scan controllers for model usage', 'default' => true],
            'scan_views' => ['type' => 'boolean', 'description' => 'Scan views for model usage', 'default' => true],
            'scan_routes' => ['type' => 'boolean', 'description' => 'Scan routes for model usage', 'default' => true],
            'scan_models' => ['type' => 'boolean', 'description' => 'Scan other models for relationships', 'default' => true],
            'scan_jobs' => ['type' => 'boolean', 'description' => 'Scan jobs for model usage', 'default' => true],
            'scan_observers' => ['type' => 'boolean', 'description' => 'Scan observers for model usage', 'default' => true],
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);
        $model = $options['model'] ?? null;

        if (! $model) {
            // Si aucun modèle n'est spécifié, retourner une structure vide
            $emptyData = [
                'model' => null,
                'usage' => [
                    'controllers' => [],
                    'views' => [],
                    'routes' => [],
                    'other_models' => [],
                    'jobs' => [],
                    'observers' => [],
                ],
            ];

            return $this->addMetadata($emptyData, $options);
        }

        $modelClass = $this->resolveModelClass($model);
        $modelShortName = class_basename($modelClass);

        $usage = [
            'model' => $modelClass,
            'short_name' => $modelShortName,
            'usage' => [],
        ];

        if ($options['scan_controllers'] ?? true) {
            $usage['usage']['controllers'] = $this->scanControllers($modelClass, $modelShortName);
        }

        if ($options['scan_views'] ?? true) {
            $usage['usage']['views'] = $this->scanViews($modelShortName);
        }

        if ($options['scan_routes'] ?? true) {
            $usage['usage']['routes'] = $this->scanRoutes($modelClass, $modelShortName);
        }

        if ($options['scan_models'] ?? true) {
            $usage['usage']['other_models'] = $this->scanModels($modelClass, $modelShortName);
        }

        if ($options['scan_jobs'] ?? true) {
            $usage['usage']['jobs'] = $this->scanJobs($modelClass, $modelShortName);
        }

        if ($options['scan_observers'] ?? true) {
            $usage['usage']['observers'] = $this->scanObservers($modelClass, $modelShortName);
        }

        return $this->addMetadata($usage, $options);
    }

    private function resolveModelClass(string $model): string
    {
        // Si c'est déjà un nom de classe complet
        if (class_exists($model)) {
            return $model;
        }

        // Si c'est juste un nom de classe, essayer dans App\Models
        $fullClassName = "App\\Models\\{$model}";
        if (class_exists($fullClassName)) {
            return $fullClassName;
        }

        // Si c'est un chemin de fichier, extraire la classe
        if (str_contains($model, '.php')) {
            $content = File::get(base_path($model));
            if (preg_match('/class\s+(\w+)/', $content, $matches)) {
                $className = $matches[1];
                if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
                    return $nsMatches[1].'\\'.$className;
                }

                return $className;
            }
        }

        // En environnement de test ou quand la classe n'existe pas,
        // retourner le nom complet supposé
        return str_contains($model, '\\') ? $model : "App\\Models\\{$model}";
    }

    private function scanControllers(string $modelClass, string $modelShortName): array
    {
        $usage = [];
        $controllersPath = app_path('Http/Controllers');

        if (! File::exists($controllersPath)) {
            return $usage;
        }

        $files = File::allFiles($controllersPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $content = File::get($file->getPathname());
                $relativeFile = str_replace(base_path().'/', '', $file->getPathname());

                $usages = $this->findUsageInFile($content, $modelClass, $modelShortName);
                if ($usages !== []) {
                    $usage[] = [
                        'file' => $relativeFile,
                        'controller' => $this->extractClassName($content),
                        'usages' => $usages,
                    ];
                }
            }
        }

        return $usage;
    }

    private function scanViews(string $modelShortName): array
    {
        $usage = [];
        $viewsPath = resource_path('views');

        if (! File::exists($viewsPath)) {
            return $usage;
        }

        $files = File::allFiles($viewsPath);

        foreach ($files as $file) {
            if (in_array($file->getExtension(), ['blade.php', 'php'])) {
                $content = File::get($file->getPathname());
                $relativeFile = str_replace(resource_path('views').'/', '', $file->getPathname());

                $usages = $this->findUsageInBladeFile($content, $modelShortName);
                if ($usages !== []) {
                    $usage[] = [
                        'file' => $relativeFile,
                        'view' => str_replace(['/', '.blade.php', '.php'], ['.', '', ''], $relativeFile),
                        'usages' => $usages,
                    ];
                }
            }
        }

        return $usage;
    }

    private function scanRoutes(string $modelClass, string $modelShortName): array
    {
        $usage = [];
        $routesPath = base_path('routes');

        if (! File::exists($routesPath)) {
            return $usage;
        }

        $files = File::allFiles($routesPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $content = File::get($file->getPathname());
                $relativeFile = str_replace(base_path().'/', '', $file->getPathname());

                $usages = $this->findUsageInFile($content, $modelClass, $modelShortName);
                if ($usages !== []) {
                    $usage[] = [
                        'file' => $relativeFile,
                        'usages' => $usages,
                    ];
                }
            }
        }

        return $usage;
    }

    private function scanModels(string $modelClass, string $modelShortName): array
    {
        $usage = [];
        $modelsPath = app_path('Models');

        if (! File::exists($modelsPath)) {
            return $usage;
        }

        $files = File::allFiles($modelsPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $content = File::get($file->getPathname());
                $relativeFile = str_replace(base_path().'/', '', $file->getPathname());

                // Skip the model itself
                if (str_contains($content, "class {$modelShortName}")) {
                    continue;
                }

                $usages = $this->findUsageInFile($content, $modelClass, $modelShortName);
                $relationships = $this->findRelationships($content, $modelShortName);

                if ($usages !== [] || $relationships !== []) {
                    $usage[] = [
                        'file' => $relativeFile,
                        'model' => $this->extractClassName($content),
                        'usages' => $usages,
                        'relationships' => $relationships,
                    ];
                }
            }
        }

        return $usage;
    }

    private function scanJobs(string $modelClass, string $modelShortName): array
    {
        $usage = [];
        $jobsPath = app_path('Jobs');

        if (! File::exists($jobsPath)) {
            return $usage;
        }

        $files = File::allFiles($jobsPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $content = File::get($file->getPathname());
                $relativeFile = str_replace(base_path().'/', '', $file->getPathname());

                $usages = $this->findUsageInFile($content, $modelClass, $modelShortName);
                if ($usages !== []) {
                    $usage[] = [
                        'file' => $relativeFile,
                        'job' => $this->extractClassName($content),
                        'usages' => $usages,
                    ];
                }
            }
        }

        return $usage;
    }

    private function scanObservers(string $modelClass, string $modelShortName): array
    {
        $usage = [];
        $observersPath = app_path('Observers');

        if (! File::exists($observersPath)) {
            return $usage;
        }

        $files = File::allFiles($observersPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $content = File::get($file->getPathname());
                $relativeFile = str_replace(base_path().'/', '', $file->getPathname());

                $usages = $this->findUsageInFile($content, $modelClass, $modelShortName);
                if ($usages !== []) {
                    $usage[] = [
                        'file' => $relativeFile,
                        'observer' => $this->extractClassName($content),
                        'usages' => $usages,
                    ];
                }
            }
        }

        return $usage;
    }

    private function findUsageInFile(string $content, string $modelClass, string $modelShortName): array
    {
        $usages = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            $line = mb_trim($line);

            // Rechercher les uses
            if (preg_match("/use\s+".preg_quote($modelClass, '/').';/', $line)) {
                $usages[] = [
                    'type' => 'import',
                    'line' => $lineNumber + 1,
                    'code' => $line,
                ];
            }

            // Rechercher les utilisations directes
            $quotedModelShortName = preg_quote($modelShortName, '/');
            if (preg_match("/{$quotedModelShortName}::/", $line) ||
                preg_match("/new\s+{$quotedModelShortName}/", $line) ||
                preg_match("/{$quotedModelShortName}\s*\(/", $line)) {
                $usages[] = [
                    'type' => 'usage',
                    'line' => $lineNumber + 1,
                    'code' => $line,
                ];
            }

            // Rechercher les type hints
            if (preg_match("/function\s+\w+\([^)]*{$quotedModelShortName}/", $line)) {
                $usages[] = [
                    'type' => 'type_hint',
                    'line' => $lineNumber + 1,
                    'code' => $line,
                ];
            }
        }

        return $usages;
    }

    private function findUsageInBladeFile(string $content, string $modelShortName): array
    {
        $usages = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            $line = mb_trim($line);

            // Rechercher les utilisations dans les templates Blade
            $quotedModelShortName = preg_quote($modelShortName, '/');
            if (preg_match('/\$'.$quotedModelShortName.'/', $line) ||
                preg_match("/@php.*{$quotedModelShortName}/", $line) ||
                preg_match("/{{.*{$quotedModelShortName}/", $line)) {
                $usages[] = [
                    'type' => 'blade_usage',
                    'line' => $lineNumber + 1,
                    'code' => $line,
                ];
            }
        }

        return $usages;
    }

    private function findRelationships(string $content, string $modelShortName): array
    {
        $relationships = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            $line = mb_trim($line);

            // Rechercher les méthodes de relation qui retournent le modèle
            $pattern = '/return\s+\$this->(hasOne|hasMany|belongsTo|belongsToMany)\s*\(\s*'.preg_quote($modelShortName, '/').'::/';
            if (preg_match($pattern, $line)) {
                $relationships[] = [
                    'type' => 'relationship',
                    'line' => $lineNumber + 1,
                    'code' => $line,
                ];
            }
        }

        return $relationships;
    }

    private function extractClassName(string $content): ?string
    {
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
