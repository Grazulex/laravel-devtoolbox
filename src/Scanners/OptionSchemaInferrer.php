<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Scanners;

/**
 * Builds a typed option schema from the legacy "name => description" format,
 * for scanners that do not declare getOptionSchema() themselves.
 */
final class OptionSchemaInferrer
{
    private const STRING_KEYS = [
        'route', 'url', 'method', 'model', 'class', 'name', 'pattern', 'path', 'file',
        'connection', 'table', 'column', 'target', 'filter', 'middleware', 'group_by',
    ];

    private const INTEGER_KEYS = ['limit', 'threshold', 'slow_threshold', 'top', 'lines', 'count'];

    /**
     * @param  array<string, string>  $options
     * @return array<string, array{type: string, description: string}>
     */
    public static function fromDescriptions(array $options): array
    {
        $schema = [];

        foreach ($options as $name => $description) {
            $schema[$name] = [
                'type' => self::inferType($name, $description),
                'description' => $description,
            ];
        }

        return $schema;
    }

    private static function inferType(string $name, string $description): string
    {
        $lower = mb_strtolower($description);

        if (str_contains($lower, '(array)') || str_starts_with($lower, 'array of')) {
            return 'array';
        }

        if (str_contains($lower, 'json object')) {
            return 'object';
        }

        if (in_array($name, self::INTEGER_KEYS, true)) {
            return 'integer';
        }

        if (in_array($name, self::STRING_KEYS, true)) {
            return 'string';
        }

        return 'boolean';
    }
}
