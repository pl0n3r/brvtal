<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\ArrayExplicitBoolCompareRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPhpSets(php85: true)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withRules([
        ArrayExplicitBoolCompareRector::class,
    ]);
