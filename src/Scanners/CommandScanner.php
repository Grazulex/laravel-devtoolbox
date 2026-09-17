<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

use Illuminate\Support\Facades\Artisan;

final class CommandScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'commands';
    }

    public function getDescription(): string
    {
        return 'Scan Laravel Artisan commands';
    }

    public function getOptionSchema(): array
    {
        return [
            'custom_only' => ['type' => 'boolean', 'description' => 'Show only custom (non-Laravel) commands', 'default' => false],
            'include_signatures' => ['type' => 'boolean', 'description' => 'Include command signatures and descriptions', 'default' => false],
            'group_by_namespace' => ['type' => 'boolean', 'description' => 'Group commands by their namespace', 'default' => false],
        ];
    }

    public function scan(array $options = []): array
    {
        $options = $this->mergeOptions($options);

        $allCommands = Artisan::all();
        $commands = [];

        foreach ($allCommands as $name => $command) {
            $commandData = $this->analyzeCommand($name, $command, $options);

            if ($options['custom_only'] ?? false) {
                if ($this->isCustomCommand($name)) {
                    $commands[] = $commandData;
                }
            } else {
                $commands[] = $commandData;
            }
        }

        $result = [
            'commands' => $commands,
            'count' => count($commands),
        ];

        if ($options['group_by_namespace'] ?? false) {
            $result['grouped_by_namespace'] = $this->groupByNamespace($commands);
        }

        return $this->addMetadata($result, $options);
    }

    private function analyzeCommand(string $name, $command, array $options): array
    {
        $commandData = [
            'name' => $name,
            'class' => get_class($command),
        ];

        if ($options['include_signatures'] ?? false) {
            $commandData['signature'] = $command->getSignature() ?? $name;
            $commandData['description'] = $command->getDescription();
            $commandData['help'] = $command->getHelp();
        }

        return $commandData;
    }

    private function isCustomCommand(string $name): bool
    {
        $laravelPrefixes = [
            'cache:', 'config:', 'db:', 'event:', 'key:', 'make:',
            'migrate:', 'notifications:', 'optimize:', 'package:',
            'queue:', 'route:', 'schedule:', 'storage:', 'vendor:',
            'view:', 'auth:', 'session:', 'tinker', 'serve', 'down',
            'up', 'inspire', 'test',
        ];

        foreach ($laravelPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function groupByNamespace(array $commands): array
    {
        $grouped = [];

        foreach ($commands as $command) {
            $namespace = $this->extractNamespace($command['name']);
            $grouped[$namespace][] = $command;
        }

        return $grouped;
    }

    private function extractNamespace(string $commandName): string
    {
        $parts = explode(':', $commandName);

        return count($parts) > 1 ? $parts[0] : 'default';
    }
}
