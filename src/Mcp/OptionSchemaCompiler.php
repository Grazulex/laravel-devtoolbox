<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Turns a scanner option schema (see AbstractScanner::getOptionSchema())
 * into an MCP input schema and into Laravel validation rules.
 */
final class OptionSchemaCompiler
{
    /**
     * @param  array<string, array{type: 'boolean'|'string'|'integer'|'array'|'object', description: string, default?: mixed, enum?: list<string>, required?: bool}>  $schema
     * @return array<string, Type>
     */
    public function toJsonSchema(array $schema, JsonSchema $factory): array
    {
        $properties = [];

        foreach ($schema as $name => $definition) {
            $type = match ($definition['type']) {
                'boolean' => $this->compileBoolean($factory, $definition),
                'integer' => $this->compileInteger($factory, $definition),
                'array' => $this->compileArray($factory, $definition),
                'object' => $this->compileObject($factory, $definition),
                default => $this->compileString($factory, $definition),
            };

            $properties[$name] = $type;
        }

        return $properties;
    }

    /**
     * @param  array<string, array{type: 'boolean'|'string'|'integer'|'array'|'object', description: string, default?: mixed, enum?: list<string>, required?: bool}>  $schema
     * @return array<string, list<string>>
     */
    public function toValidationRules(array $schema): array
    {
        $rules = [];

        foreach ($schema as $name => $definition) {
            $ruleSet = ($definition['required'] ?? false) ? ['required'] : ['sometimes', 'nullable'];

            $ruleSet[] = match ($definition['type']) {
                'boolean' => 'boolean',
                'integer' => 'integer',
                'array', 'object' => 'array',
                default => 'string',
            };

            if (isset($definition['enum'])) {
                $ruleSet[] = 'in:'.implode(',', $definition['enum']);
            }

            $rules[$name] = $ruleSet;
        }

        return $rules;
    }

    /**
     * @param  array{type: 'boolean'|'string'|'integer'|'array'|'object', description: string, default?: mixed, enum?: list<string>, required?: bool}  $definition
     */
    private function compileBoolean(JsonSchema $factory, array $definition): Type
    {
        $type = $factory->boolean()->description($definition['description']);

        if (array_key_exists('default', $definition)) {
            $type = $type->default((bool) $definition['default']);
        }

        return $this->finalize($type, $definition);
    }

    /**
     * @param  array{type: 'boolean'|'string'|'integer'|'array'|'object', description: string, default?: mixed, enum?: list<string>, required?: bool}  $definition
     */
    private function compileString(JsonSchema $factory, array $definition): Type
    {
        $type = $factory->string()->description($definition['description']);

        if (array_key_exists('default', $definition)) {
            $type = $type->default((string) $definition['default']);
        }

        return $this->finalize($type, $definition);
    }

    /**
     * @param  array{type: 'boolean'|'string'|'integer'|'array'|'object', description: string, default?: mixed, enum?: list<string>, required?: bool}  $definition
     */
    private function compileInteger(JsonSchema $factory, array $definition): Type
    {
        $type = $factory->integer()->description($definition['description']);

        if (array_key_exists('default', $definition)) {
            $type = $type->default((int) $definition['default']);
        }

        return $this->finalize($type, $definition);
    }

    /**
     * @param  array{type: 'boolean'|'string'|'integer'|'array'|'object', description: string, default?: mixed, enum?: list<string>, required?: bool}  $definition
     */
    private function compileArray(JsonSchema $factory, array $definition): Type
    {
        $type = $factory->array()->description($definition['description']);

        if (array_key_exists('default', $definition) && is_array($definition['default'])) {
            $type = $type->default($definition['default']);
        }

        return $this->finalize($type, $definition);
    }

    /**
     * @param  array{type: 'boolean'|'string'|'integer'|'array'|'object', description: string, default?: mixed, enum?: list<string>, required?: bool}  $definition
     */
    private function compileObject(JsonSchema $factory, array $definition): Type
    {
        $type = $factory->object()->description($definition['description']);

        if (array_key_exists('default', $definition) && is_array($definition['default'])) {
            $type = $type->default($definition['default']);
        }

        return $this->finalize($type, $definition);
    }

    /**
     * @template TType of Type
     *
     * @param  TType  $type
     * @param  array{type: 'boolean'|'string'|'integer'|'array'|'object', description: string, default?: mixed, enum?: list<string>, required?: bool}  $definition
     * @return TType
     */
    private function finalize(Type $type, array $definition): Type
    {
        if (isset($definition['enum'])) {
            $type = $type->enum($definition['enum']);
        }

        if ($definition['required'] ?? false) {
            $type = $type->required();
        }

        return $type;
    }
}
