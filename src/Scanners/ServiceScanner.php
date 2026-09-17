<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Closure;
use ReflectionClass;
use ReflectionException;

final class ServiceScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'services';
    }

    public function getDescription(): string
    {
        return 'Scan Laravel service container bindings';
    }

    public function getOptionSchema(): array
    {
        return [
            'include_singletons' => ['type' => 'boolean', 'description' => 'Include singleton services separately', 'default' => false],
            'include_aliases' => ['type' => 'boolean', 'description' => 'Include service aliases', 'default' => false],
            'filter_custom' => ['type' => 'boolean', 'description' => 'Show only custom (non-Laravel) services', 'default' => false],
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);

        // Use reflection to access protected bindings property
        $bindings = $this->getAppBindings();
        $services = [];

        foreach ($bindings as $abstract => $binding) {
            $serviceData = $this->analyzeService($abstract, $binding);

            if ($options['filter_custom'] ?? false) {
                if ($this->isCustomService($abstract)) {
                    $services[] = $serviceData;
                }
            } else {
                $services[] = $serviceData;
            }
        }

        $result = [
            'services' => $services,
            'count' => count($services),
        ];

        if ($options['include_singletons'] ?? false) {
            $result['singletons'] = $this->getSingletons();
        }

        if ($options['include_aliases'] ?? false) {
            $result['aliases'] = $this->getAliases();
        }

        return $this->addMetadata($result, $options);
    }

    private function analyzeService(string $abstract, array $binding): array
    {
        $concrete = $binding['concrete'] ?? null;

        // Convert Closure to readable string
        if ($concrete instanceof Closure) {
            $concrete = 'Closure';
        }

        return [
            'abstract' => $abstract,
            'concrete' => $concrete,
            'shared' => $binding['shared'] ?? false,
        ];
    }

    private function isCustomService(string $abstract): bool
    {
        $laravelServices = [
            'Illuminate\\', 'Laravel\\', 'Symfony\\', 'Psr\\',
            'app', 'auth', 'cache', 'config', 'db', 'events',
            'files', 'log', 'queue', 'redis', 'request', 'response',
            'route', 'session', 'validator', 'view',
        ];

        foreach ($laravelServices as $service) {
            if (str_starts_with($abstract, $service)) {
                return false;
            }
        }

        return true;
    }

    private function getSingletons(): array
    {
        $singletons = [];
        $bindings = $this->getAppBindings();

        foreach ($bindings as $abstract => $binding) {
            if ($binding['shared'] ?? false) {
                $singletons[] = [
                    'abstract' => $abstract,
                    'concrete' => $binding['concrete'] ?? null,
                ];
            }
        }

        return $singletons;
    }

    private function getAliases(): array
    {
        return $this->getAppAliases();
    }

    /**
     * Get application bindings using reflection
     */
    private function getAppBindings(): array
    {
        try {
            $reflection = new ReflectionClass($this->app);
            $property = $reflection->getProperty('bindings');
            $property->setAccessible(true);

            return $property->getValue($this->app) ?? [];
        } catch (ReflectionException $e) {
            return [];
        }
    }

    /**
     * Get application aliases using reflection
     */
    private function getAppAliases(): array
    {
        try {
            $reflection = new ReflectionClass($this->app);
            $property = $reflection->getProperty('aliases');
            $property->setAccessible(true);

            return $property->getValue($this->app) ?? [];
        } catch (ReflectionException $e) {
            return [];
        }
    }
}
