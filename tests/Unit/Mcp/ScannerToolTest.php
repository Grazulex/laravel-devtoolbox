<?php

declare(strict_types=1);

use Grazulex\LaravelDevtoolbox\Contracts\ScannerInterface;
use Grazulex\LaravelDevtoolbox\Mcp\ActiveScannerTool;
use Grazulex\LaravelDevtoolbox\Mcp\OptionSchemaCompiler;
use Grazulex\LaravelDevtoolbox\Mcp\ReadOnlyScannerTool;
use Grazulex\LaravelDevtoolbox\Mcp\ResponseTruncator;
use Grazulex\LaravelDevtoolbox\Scanners\AbstractScanner;

final class FakeScanner extends AbstractScanner implements ScannerInterface
{
    public array $received = [];

    public function __construct(private readonly array $result = ['count' => 1, 'items' => ['a']], private readonly ?Throwable $throws = null)
    {
        parent::__construct(app());
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function getDescription(): string
    {
        return 'A fake scanner';
    }

    public function getOptionSchema(): array
    {
        return [
            'verbose' => ['type' => 'boolean', 'description' => 'Verbose', 'default' => false],
            'target' => ['type' => 'string', 'description' => 'Target', 'required' => true],
        ];
    }

    public function scan(array $options = []): array
    {
        $this->received = $options;
        if ($this->throws instanceof Throwable) {
            throw $this->throws;
        }

        return $this->result;
    }
}

function makeTool(FakeScanner $scanner, string $class = ReadOnlyScannerTool::class): ReadOnlyScannerTool|ActiveScannerTool
{
    return new $class($scanner, 'devtoolbox-fake', 'Fake', 'A fake scanner', new OptionSchemaCompiler, new ResponseTruncator(262_144));
}

it('exposes name, title, description and input schema', function (): void {
    $tool = makeTool(new FakeScanner);
    $array = $tool->toArray();

    expect($tool->name())->toBe('devtoolbox-fake')
        ->and($tool->title())->toBe('Fake')
        ->and($tool->description())->toBe('A fake scanner')
        ->and($array['inputSchema']['properties'])->toHaveKeys(['verbose', 'target'])
        ->and($array['inputSchema']['required'])->toBe(['target']);
});

it('marks read-only tools with annotations and active tools without', function (): void {
    expect(makeTool(new FakeScanner)->annotations())->toMatchArray(['readOnlyHint' => true, 'idempotentHint' => true])
        ->and(makeTool(new FakeScanner, ActiveScannerTool::class)->annotations())->toBe([]);
});

it('forwards validated options to the scanner with format=array', function (): void {
    $scanner = new FakeScanner;
    $tool = makeTool($scanner);

    $response = $tool->handle(new Laravel\Mcp\Request(['target' => 'App\\Models\\User', 'verbose' => true]));

    expect($scanner->received)->toEqual(['target' => 'App\\Models\\User', 'verbose' => true, 'format' => 'array'])
        ->and($response)->toBeInstanceOf(Laravel\Mcp\ResponseFactory::class);
});

it('returns an error response when the scanner throws', function (): void {
    $tool = makeTool(new FakeScanner(throws: new RuntimeException('boom')));

    $response = $tool->handle(new Laravel\Mcp\Request(['target' => 'x']));

    expect($response)->toBeInstanceOf(Laravel\Mcp\Response::class)
        ->and($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('fake: boom');
});

it('returns a json response for an empty scan result', function (): void {
    $tool = makeTool(new FakeScanner(result: []));

    $response = $tool->handle(new Laravel\Mcp\Request(['target' => 'x']));

    expect($response)->toBeInstanceOf(Laravel\Mcp\Response::class)
        ->and($response->isError())->toBeFalse();
});

it('refuses to run once disabled', function (): void {
    $scanner = new FakeScanner;
    $tool = makeTool($scanner);
    $tool->disable('DevToolbox MCP is disabled in this environment.');

    $response = $tool->handle(new Laravel\Mcp\Request(['target' => 'x']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('disabled in this environment')
        ->and($scanner->received)->toBe([]);
});
