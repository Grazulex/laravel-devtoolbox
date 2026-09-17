<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\DevtoolboxManager;
use Grazulex\LaravelDevtoolbox\Mcp\ActiveScannerTool;
use Grazulex\LaravelDevtoolbox\Mcp\OptionSchemaCompiler;
use Grazulex\LaravelDevtoolbox\Mcp\ReadOnlyScannerTool;
use Grazulex\LaravelDevtoolbox\Mcp\ResponseTruncator;
use Grazulex\LaravelDevtoolbox\Mcp\ToolFactory;

function factory(): ToolFactory
{
    return new ToolFactory(new OptionSchemaCompiler, new ResponseTruncator(262_144));
}

it('builds one tool per registered scanner with a devtoolbox- prefix', function (): void {
    $registry = (new DevtoolboxManager(app()))->registry();

    $tools = factory()->make($registry);

    expect($tools)->toHaveCount(16)
        ->and(array_map(fn ($tool): string => $tool->name(), $tools))
        ->toEqualCanonicalizing(array_map(fn (string $name): string => "devtoolbox-$name", $registry->all()));
});

it('uses the scanner description and a headline title', function (): void {
    $tools = collect(factory()->make((new DevtoolboxManager(app()))->registry()))->keyBy(fn ($tool): string => $tool->name());

    expect($tools['devtoolbox-route-where-lookup']->title())->toBe('Route Where Lookup')
        ->and($tools['devtoolbox-routes']->description())->toBe('Scan Laravel routes and analyze their usage');
});

it('marks exactly sql-trace, sql-analysis and performance as active', function (): void {
    $tools = factory()->make((new DevtoolboxManager(app()))->registry());

    $active = array_values(array_map(
        fn ($tool): string => $tool->scanner()->getName(),
        array_filter($tools, fn ($tool): bool => $tool instanceof ActiveScannerTool),
    ));
    $readOnly = array_filter($tools, fn ($tool): bool => $tool instanceof ReadOnlyScannerTool);

    expect($active)->toEqualCanonicalizing(['sql-trace', 'sql-analysis', 'performance'])
        ->and($readOnly)->toHaveCount(13);
});
