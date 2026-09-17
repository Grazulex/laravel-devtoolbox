<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Exception;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

final class ContainerBindingsScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'container-bindings';
    }

    public function getDescription(): string
    {
        return 'Analyzes Laravel container bindings, singletons, and dependency injection mappings';
    }

    public function getOptionSchema(): array
    {
        return [
            'filter' => ['type' => 'string', 'description' => 'Filter bindings by name, namespace, or type'],
            'show_resolved' => ['type' => 'boolean', 'description' => 'Attempt to resolve bindings and show actual instances', 'default' => false],
            'show_aliases' => ['type' => 'boolean', 'description' => 'Include container aliases in output', 'default' => false],
            'group_by' => ['type' => 'string', 'description' => 'Group results by type, namespace or singleton', 'enum' => ['type', 'namespace', 'singleton'], 'default' => 'type'],
        ];
    }

    public function scan(array $options = []): array
    {
        $filter = $options['filter'] ?? null;
        $showResolved = $options['show_resolved'] ?? false;
        $showAliases = $options['show_aliases'] ?? false;
        $groupBy = $options['group_by'] ?? 'type'; // type, namespace, singleton

        try {
            $container = app();
            $bindings = $this->getContainerBindings($container);

            // Apply filter if provided
            if ($filter) {
                $bindings = $this->filterBindings($bindings, $filter);
            }

            // Resolve bindings if requested
            if ($showResolved) {
                $bindings = $this->resolveBindings($bindings, $container);
            }

            // Get aliases if requested
            $aliases = $showAliases ? $this->getAliases($container) : [];

            // Group bindings
            $grouped = $this->groupBindings($bindings, $groupBy);

            return [
                'bindings' => $bindings,
                'grouped' => $grouped,
                'aliases' => $aliases,
                'statistics' => $this->generateStatistics($bindings, $aliases),
                'options' => $options,
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to analyze container bindings: '.$e->getMessage(),
                'bindings' => [],
                'statistics' => [],
            ];
        }
    }

    private function getContainerBindings(Container $container): array
    {
        $bindings = [];

        // Access protected bindings property via reflection
        $reflection = new ReflectionClass($container);
        $bindingsProperty = $reflection->getProperty('bindings');
        $bindingsProperty->setAccessible(true);
        $containerBindings = $bindingsProperty->getValue($container);

        // Access instances (singletons)
        $instancesProperty = $reflection->getProperty('instances');
        $instancesProperty->setAccessible(true);
        $instances = $instancesProperty->getValue($container);

        foreach ($containerBindings as $abstract => $binding) {
            $info = [
                'abstract' => $abstract,
                'concrete' => $this->getConcreteInfo($binding),
                'shared' => $binding['shared'] ?? false,
                'is_singleton' => isset($instances[$abstract]),
                'is_interface' => interface_exists($abstract),
                'is_class' => class_exists($abstract),
                'namespace' => $this->getNamespace($abstract),
                'type' => $this->getBindingType($abstract, $binding),
            ];

            // Add constructor parameters info if it's a class
            if ($info['is_class']) {
                $info['constructor_parameters'] = $this->getConstructorParameters($abstract);
            }

            $bindings[$abstract] = $info;
        }

        // Add instances that might not be in bindings
        foreach ($instances as $abstract => $instance) {
            if (! isset($bindings[$abstract])) {
                $concreteClass = is_object($instance) ? get_class($instance) : (string) $instance;

                $bindings[$abstract] = [
                    'abstract' => $abstract,
                    'concrete' => $concreteClass,
                    'shared' => true,
                    'is_singleton' => true,
                    'is_interface' => interface_exists($abstract),
                    'is_class' => class_exists($abstract),
                    'namespace' => $this->getNamespace($abstract),
                    'type' => 'instance',
                    'instance_class' => $concreteClass,
                ];
            }
        }

        return $bindings;
    }

    private function getConcreteInfo(array $binding): string
    {
        $concrete = $binding['concrete'] ?? null;

        if (is_string($concrete)) {
            return $concrete;
        }

        if (is_callable($concrete)) {
            return 'Closure';
        }

        if (is_object($concrete)) {
            return get_class($concrete);
        }

        return 'Unknown';
    }

    private function getNamespace(string $class): string
    {
        $parts = explode('\\', $class);
        array_pop($parts); // Remove class name

        return implode('\\', $parts);
    }

    private function getBindingType(string $abstract, array $binding): string
    {
        if ($binding['shared'] ?? false) {
            return 'singleton';
        }

        if (interface_exists($abstract)) {
            return 'interface';
        }

        if (class_exists($abstract)) {
            return 'class';
        }

        return 'other';
    }

    private function getConstructorParameters(string $class): array
    {
        try {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if (! $constructor) {
                return [];
            }

            $parameters = [];
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                $typeName = 'mixed';

                if ($type) {
                    $typeName = $type instanceof ReflectionNamedType
                        ? $type->getName()
                        : (string) $type;
                }

                $parameters[] = [
                    'name' => $param->getName(),
                    'type' => $typeName,
                    'optional' => $param->isOptional(),
                    'has_default' => $param->isDefaultValueAvailable(),
                    'default_value' => $param->isDefaultValueAvailable()
                        ? $param->getDefaultValue()
                        : null,
                ];
            }

            return $parameters;

        } catch (ReflectionException $e) {
            return [];
        }
    }

    private function filterBindings(array $bindings, string $filter): array
    {
        return array_filter($bindings, function (array $binding) use ($filter): bool {
            $searchIn = [
                $binding['abstract'],
                $binding['concrete'],
                $binding['namespace'],
                $binding['type'],
            ];

            foreach ($searchIn as $value) {
                if (mb_stripos($value, $filter) !== false) {
                    return true;
                }
            }

            return false;
        });
    }

    private function resolveBindings(array $bindings, Container $container): array
    {
        foreach ($bindings as $abstract => &$binding) {
            try {
                $resolved = $container->make($abstract);
                $binding['resolved_class'] = get_class($resolved);
                $binding['can_resolve'] = true;
            } catch (BindingResolutionException $e) {
                $binding['resolved_class'] = null;
                $binding['can_resolve'] = false;
                $binding['resolution_error'] = $e->getMessage();
            }
        }

        return $bindings;
    }

    private function getAliases(Container $container): array
    {
        try {
            $reflection = new ReflectionClass($container);
            $aliasesProperty = $reflection->getProperty('aliases');
            $aliasesProperty->setAccessible(true);

            return $aliasesProperty->getValue($container);
        } catch (ReflectionException $e) {
            return [];
        }
    }

    private function groupBindings(array $bindings, string $groupBy): array
    {
        $grouped = [];

        foreach ($bindings as $binding) {
            $key = match ($groupBy) {
                'namespace' => $binding['namespace'] ?: 'Global',
                'singleton' => $binding['is_singleton'] ? 'Singletons' : 'Transient',
                'type' => ucfirst($binding['type']),
                default => 'All',
            };

            if (! isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $binding;
        }

        // Sort groups by key
        ksort($grouped);

        return $grouped;
    }

    private function generateStatistics(array $bindings, array $aliases): array
    {
        $stats = [
            'total_bindings' => count($bindings),
            'total_aliases' => count($aliases),
            'singletons' => 0,
            'interfaces' => 0,
            'classes' => 0,
            'closures' => 0,
            'namespaces' => [],
        ];

        foreach ($bindings as $binding) {
            if ($binding['is_singleton']) {
                $stats['singletons']++;
            }

            if ($binding['is_interface']) {
                $stats['interfaces']++;
            }

            if ($binding['is_class']) {
                $stats['classes']++;
            }

            if ($binding['concrete'] === 'Closure') {
                $stats['closures']++;
            }

            $namespace = $binding['namespace'] ?: 'Global';
            if (! isset($stats['namespaces'][$namespace])) {
                $stats['namespaces'][$namespace] = 0;
            }
            $stats['namespaces'][$namespace]++;
        }

        $stats['unique_namespaces'] = count($stats['namespaces']);

        return $stats;
    }
}
