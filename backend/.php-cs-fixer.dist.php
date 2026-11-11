<?php

/**
 * The backend's one code-style tool: `composer lint` checks, `composer lint:fix`
 * fixes, and CI runs the check.
 *
 * The shared rules (lint/php: PER Coding Style 3.0, PSR-12's
 * successor, pinned so a fixer upgrade can't move the standard) and the migration
 * set for the PHP composer.json requires, so the fixer never writes syntax the
 * server can't run.
 */

return new PhpCsFixer\Config()
    ->setRules([
        ...require __DIR__ . '/../shared/lint/php/php-cs-fixer-rules.php',
        '@PHP8x5Migration' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/api', __DIR__ . '/bin', __DIR__ . '/db/migrations', __DIR__ . '/includes', __DIR__ . '/scripts', __DIR__ . '/tests'])
            ->append([__DIR__ . '/og.php', __DIR__ . '/dev-router.php', __DIR__ . '/phinx.php', __DIR__ . '/rector.php', __FILE__])
    )
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
