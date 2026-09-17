<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Grazulex\LaravelDevtoolbox\Registry\ScannerRegistry;
use Illuminate\Support\Str;

final class ToolFactory
{
    /**
     * Scanners that execute code (internal request, queries) rather than only
     * reading application metadata. They are exposed without read-only hints.
     *
     * @var list<string>
     */
    public const ACTIVE_SCANNERS = ['sql-trace', 'sql-analysis', 'performance'];

    public function __construct(
        private readonly OptionSchemaCompiler $compiler,
        private readonly ResponseTruncator $truncator,
    ) {}

    /**
     * @return list<ScannerTool>
     */
    public function make(ScannerRegistry $registry): array
    {
        $tools = [];

        foreach ($registry->getScanners() as $name => $scanner) {
            $class = in_array($name, self::ACTIVE_SCANNERS, true) ? ActiveScannerTool::class : ReadOnlyScannerTool::class;

            $tools[] = new $class(
                $scanner,
                'devtoolbox-'.$name,
                Str::headline($name),
                $scanner->getDescription(),
                $this->compiler,
                $this->truncator,
            );
        }

        return $tools;
    }
}
