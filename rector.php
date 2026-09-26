<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPhpSets(php85: true)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(10);
