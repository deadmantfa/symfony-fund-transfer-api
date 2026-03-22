<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
    ])
    ->withSkip([
        // Pest's Expectation fluent API confuses Rector's type inference —
        // it infers Pest\Mixins\Expectation instead of Pest\Expectation,
        // which causes a TypeError at runtime with strict_types.
        AddArrowFunctionReturnTypeRector::class => [__DIR__ . '/tests'],
    ])
    ->withImportNames();
