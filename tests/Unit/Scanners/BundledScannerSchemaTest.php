<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Grazulex\LaravelDevtoolbox\Scanners\AbstractScanner;

const INTERNAL_OPTIONS = ['format', 'include_metadata', 'exclude'];
const ALLOWED_TYPES = ['boolean', 'string', 'integer', 'array', 'object'];

// Kept as a literal list (rather than derived from `(new DevtoolboxManager(app()))->registry()->all()`)
// because dataset providers run before the Testbench application is bootstrapped: at that point
// `app()` resolves to a bare `Illuminate\Container\Container`, which fails DevtoolboxManager's
// `?Application` type-hint. The "registers exactly the expected scanners" test below still cross-checks
// this list against the live registry, so any drift is caught.
const BUNDLED_SCANNER_NAMES = [
    'models', 'routes', 'route-where-lookup', 'container-bindings', 'middleware-usage',
    'sql-analysis', 'provider-timeline', 'commands', 'services', 'middleware', 'views',
    'model-usage', 'sql-trace', 'security', 'db-column-usage', 'performance',
];

function bundledScanners(): array
{
    return array_map(fn (string $name): array => [$name], BUNDLED_SCANNER_NAMES);
}

it('declares an explicit typed schema', function (string $name): void {
    $scanner = (new DevtoolboxManager(app()))->registry()->get($name);

    $declaring = (new ReflectionMethod($scanner, 'getOptionSchema'))->getDeclaringClass()->getName();
    expect($declaring)->not->toBe(AbstractScanner::class, "$name must override getOptionSchema()");

    $legacy = (new ReflectionMethod($scanner, 'getAvailableOptions'))->getDeclaringClass()->getName();
    expect($legacy)->toBe(AbstractScanner::class, "$name must not override getAvailableOptions()");

    $schema = $scanner->getOptionSchema();
    expect($schema)->not->toBeEmpty();

    foreach ($schema as $option => $definition) {
        expect($option)->not->toBeIn(INTERNAL_OPTIONS, "$name exposes internal option $option");
        expect($definition['type'])->toBeIn(ALLOWED_TYPES, "$name.$option has invalid type");
        expect($definition['description'])->toBeString()->not->toBe('');

        if (isset($definition['enum'])) {
            expect($definition['type'])->toBe('string');
            expect($definition['enum'])->toBeArray()->not->toBeEmpty();
            if (array_key_exists('default', $definition)) {
                expect($definition['default'])->toBeIn($definition['enum']);
            }
        }

        if (array_key_exists('default', $definition)) {
            $expectedPhpType = match ($definition['type']) {
                'boolean' => 'bool', 'string' => 'string', 'integer' => 'int', default => 'array',
            };
            expect(get_debug_type($definition['default']))->toBe($expectedPhpType, "$name.$option default has wrong type");
        }
    }

    expect($scanner->getAvailableOptions())->toBe(array_map(fn (array $d): string => $d['description'], $schema));
})->with(bundledScanners());

it('registers exactly the expected scanners', function (): void {
    expect((new DevtoolboxManager(app()))->registry()->all())->toEqualCanonicalizing(BUNDLED_SCANNER_NAMES);
});
