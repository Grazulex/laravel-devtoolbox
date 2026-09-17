<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Scanners\AbstractScanner;

final class SchemaDeclaringScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'schema-declaring';
    }

    public function getDescription(): string
    {
        return 'Declares its schema';
    }

    public function scan(array $options = []): array
    {
        return [];
    }

    public function getOptionSchema(): array
    {
        return [
            'verbose' => ['type' => 'boolean', 'description' => 'Verbose output', 'default' => false],
            'limit' => ['type' => 'integer', 'description' => 'Max items'],
        ];
    }
}

final class LegacyOptionsScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'legacy';
    }

    public function getDescription(): string
    {
        return 'Only declares legacy options';
    }

    public function scan(array $options = []): array
    {
        return [];
    }

    public function getAvailableOptions(): array
    {
        return ['tables' => 'Tables to analyze (array)', 'unused_only' => 'Show only unused'];
    }
}

final class BareScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'bare';
    }

    public function getDescription(): string
    {
        return 'Declares nothing';
    }

    public function scan(array $options = []): array
    {
        return [];
    }
}

final class ExtendingLegacyScanner extends AbstractScanner
{
    public function getName(): string
    {
        return 'extending-legacy';
    }

    public function getDescription(): string
    {
        return 'Calls parent::getAvailableOptions() from an override';
    }

    public function scan(array $options = []): array
    {
        return [];
    }

    public function getAvailableOptions(): array
    {
        return array_merge(parent::getAvailableOptions(), ['extra' => 'Extra option (array)']);
    }
}

it('derives getAvailableOptions from a declared schema', function (): void {
    $scanner = new SchemaDeclaringScanner($this->app);

    expect($scanner->getAvailableOptions())->toBe(['verbose' => 'Verbose output', 'limit' => 'Max items']);
});

it('infers the schema from legacy getAvailableOptions', function (): void {
    $scanner = new LegacyOptionsScanner($this->app);

    expect($scanner->getOptionSchema())->toBe([
        'tables' => ['type' => 'array', 'description' => 'Tables to analyze (array)'],
        'unused_only' => ['type' => 'boolean', 'description' => 'Show only unused'],
    ]);
});

it('returns empty schema and options when nothing is declared', function (): void {
    $scanner = new BareScanner($this->app);

    expect($scanner->getOptionSchema())->toBe([])
        ->and($scanner->getAvailableOptions())->toBe([]);
});

it('does not recurse when a legacy override calls parent::getAvailableOptions()', function (): void {
    $scanner = new ExtendingLegacyScanner($this->app);

    expect($scanner->getAvailableOptions())->toBe(['extra' => 'Extra option (array)'])
        ->and($scanner->getOptionSchema())->toBe([
            'extra' => ['type' => 'array', 'description' => 'Extra option (array)'],
        ]);
});
