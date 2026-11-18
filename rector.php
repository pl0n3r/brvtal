<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPhpSets(php85: true)
    // Hostinger production runs PHP 8.2; keep this safety-critical runtime file
    // out of PHP 8.4+ autofixes such as ForeachToArrayAllRector.
    ->withSkip([__DIR__ . '/config/migration_reconcile.php'])
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0);
