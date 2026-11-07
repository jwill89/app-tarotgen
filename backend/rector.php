<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;

// The shared Rector set (shared-tooling's lint/php: no dead code) and current PHP
// idioms for the PHP composer.json requires (`composer rector` to apply,
// `composer rector:check` — the CI step — to list).
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/api',
        __DIR__ . '/bin',
        __DIR__ . '/db/migrations',
        __DIR__ . '/includes',
        __DIR__ . '/scripts',
        __DIR__ . '/tests',
        __DIR__ . '/og.php',
        __DIR__ . '/dev-router.php',
        __DIR__ . '/phinx.php',
    ])
    ->withSets([__DIR__ . '/../shared/lint/php/rector-set.php'])
    ->withPhpSets(php85: true)
    ->withSkip([
        // Production compiles the DI container, which has to export each factory:
        // an array callable compiles as-is, a first-class-callable closure is
        // code PHP-DI would have to lift out of the file.
        ArrayToFirstClassCallableRector::class => [__DIR__ . '/api/dependencies.php'],
    ]);
