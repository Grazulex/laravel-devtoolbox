<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Grazulex\LaravelDevtoolbox\Contracts\ScannerInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Adapts one DevToolbox scanner to an MCP tool.
 */
abstract class ScannerTool extends Tool
{
    private ?string $disabledReason = null;

    public function __construct(
        private readonly ScannerInterface $scanner,
        string $name,
        string $title,
        string $description,
        private readonly OptionSchemaCompiler $compiler,
        private readonly ResponseTruncator $truncator,
    ) {
        $this->name = $name;
        $this->title = $title;
        $this->description = $description;
    }

    public function scanner(): ScannerInterface
    {
        return $this->scanner;
    }

    public function disable(string $reason): void
    {
        $this->disabledReason = $reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->compiler->toJsonSchema($this->optionSchema(), $schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($this->disabledReason !== null) {
            return Response::error($this->disabledReason);
        }

        $options = $request->validate($this->compiler->toValidationRules($this->optionSchema()));

        try {
            $result = $this->scanner->scan($options + ['format' => 'array']);
        } catch (Throwable $exception) {
            Log::debug('[devtoolbox.mcp] scanner failed', [
                'scanner' => $this->scanner->getName(),
                'exception' => $exception,
            ]);

            return Response::error(sprintf('%s: %s', $this->scanner->getName(), $exception->getMessage()));
        }

        if ($result === []) {
            return Response::json([]);
        }

        return Response::structured($this->truncator->truncate($result, $this->optionSchema()));
    }

    /**
     * @return array<string, array{type: string, description: string, default?: mixed, enum?: list<string>, required?: bool}>
     */
    private function optionSchema(): array
    {
        return method_exists($this->scanner, 'getOptionSchema')
            ? $this->scanner->getOptionSchema()
            : \Grazulex\LaravelDevtoolbox\Scanners\OptionSchemaInferrer::fromDescriptions($this->scanner->getAvailableOptions());
    }
}
