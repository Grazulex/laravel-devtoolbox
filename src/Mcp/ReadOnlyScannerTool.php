<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
final class ReadOnlyScannerTool extends ScannerTool {}
