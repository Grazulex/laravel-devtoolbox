<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Mcp\OptionSchemaCompiler;
use Illuminate\JsonSchema\JsonSchema;

const SAMPLE_SCHEMA = [
    'detect_unused' => ['type' => 'boolean', 'description' => 'Detect unused', 'default' => false],
    'method' => ['type' => 'string', 'description' => 'HTTP method', 'enum' => ['GET', 'POST'], 'default' => 'GET'],
    'threshold' => ['type' => 'integer', 'description' => 'Threshold', 'default' => 2],
    'tables' => ['type' => 'array', 'description' => 'Tables'],
    'headers' => ['type' => 'object', 'description' => 'Headers'],
    'target' => ['type' => 'string', 'description' => 'Target', 'required' => true],
];

it('compiles every option into a JSON schema property', function (): void {
    $compiled = JsonSchema::object(fn (JsonSchema $schema): array => (new OptionSchemaCompiler)->toJsonSchema(SAMPLE_SCHEMA, $schema))->toArray();

    expect($compiled['properties'])->toHaveKeys(array_keys(SAMPLE_SCHEMA))
        ->and($compiled['properties']['detect_unused'])->toMatchArray(['type' => 'boolean', 'description' => 'Detect unused', 'default' => false])
        ->and($compiled['properties']['method'])->toMatchArray(['type' => 'string', 'enum' => ['GET', 'POST'], 'default' => 'GET'])
        ->and($compiled['properties']['threshold'])->toMatchArray(['type' => 'integer', 'default' => 2])
        ->and($compiled['properties']['tables']['type'])->toBe('array')
        ->and($compiled['properties']['headers']['type'])->toBe('object')
        ->and($compiled['required'])->toBe(['target']);
});

it('compiles validation rules', function (): void {
    $rules = (new OptionSchemaCompiler)->toValidationRules(SAMPLE_SCHEMA);

    expect($rules)->toBe([
        'detect_unused' => ['sometimes', 'nullable', 'boolean'],
        'method' => ['sometimes', 'nullable', 'string', 'in:GET,POST'],
        'threshold' => ['sometimes', 'nullable', 'integer'],
        'tables' => ['sometimes', 'nullable', 'array'],
        'headers' => ['sometimes', 'nullable', 'array'],
        'target' => ['required', 'string'],
    ]);
});

it('compiles an empty schema to no properties and no rules', function (): void {
    $compiler = new OptionSchemaCompiler;

    expect(JsonSchema::object(fn (JsonSchema $s): array => $compiler->toJsonSchema([], $s))->toArray())->not->toHaveKey('required')
        ->and($compiler->toValidationRules([]))->toBe([]);
});
