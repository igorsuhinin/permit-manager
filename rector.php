<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/migrations',
        __DIR__ . '/translations', __DIR__ . '/config', __DIR__ . '/bootstrap', __DIR__ . '/bin',
        __DIR__ . '/public/index.php',
    ])
    ->withPhpSets()
    ->withCache(__DIR__ . '/.rector.cache');
