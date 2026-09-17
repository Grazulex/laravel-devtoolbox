<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Scanners\OptionSchemaInferrer;

it('infers array from an "(array)" hint in the description', function (): void {
    $schema = OptionSchemaInferrer::fromDescriptions(['tables' => 'Specific tables to analyze (array)']);

    expect($schema['tables'])->toBe(['type' => 'array', 'description' => 'Specific tables to analyze (array)']);
});

it('infers string for well-known scalar keys', function (string $key): void {
    $schema = OptionSchemaInferrer::fromDescriptions([$key => 'Some description']);

    expect($schema[$key]['type'])->toBe('string');
})->with(['route', 'url', 'method', 'model', 'class', 'name', 'pattern', 'path', 'file', 'connection', 'table', 'column', 'target', 'filter', 'middleware', 'group_by']);

it('infers integer for well-known numeric keys', function (string $key): void {
    $schema = OptionSchemaInferrer::fromDescriptions([$key => 'Some description']);

    expect($schema[$key]['type'])->toBe('integer');
})->with(['limit', 'threshold', 'slow_threshold', 'top', 'lines', 'count']);

it('infers object from a "JSON object" hint', function (): void {
    $schema = OptionSchemaInferrer::fromDescriptions(['headers' => 'Request headers as JSON object']);

    expect($schema['headers']['type'])->toBe('object');
});

it('falls back to boolean', function (): void {
    $schema = OptionSchemaInferrer::fromDescriptions(['detect_unused' => 'Attempt to detect unused routes']);

    expect($schema['detect_unused']['type'])->toBe('boolean');
});

it('keeps the description verbatim and preserves order', function (): void {
    $schema = OptionSchemaInferrer::fromDescriptions(['b' => 'Second', 'a' => 'First']);

    expect(array_keys($schema))->toBe(['b', 'a'])
        ->and($schema['a']['description'])->toBe('First');
});
