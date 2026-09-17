<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrowFunctionParamArrayWhereDimFetchRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true
    )
    ->withSkip([
        // Keep these closures untyped: they guard external data with a defensive check.
        AddArrayFunctionClosureParamTypeRector::class => [
            __DIR__.'/src/Scanners/ProviderTimelineScanner.php',
        ],
        AddArrowFunctionParamArrayWhereDimFetchRector::class => [
            __DIR__.'/src/Console/Commands/DevRoutesUnusedCommand.php',
        ],
    ]);
