<?php

declare(strict_types=1);

use Tarot\Config\Paths;
use Tarot\Database\Schema;

/*
 * Phinx, which owns the schema: `composer migrate` / `composer migrate:status`
 * here, `php vendor/bin/phinx migrate` as www-data on the host (AGENTS.md §7).
 *
 * It opens the database the app opens, Paths::database(), so TAROT_DATA_DIR
 * picks the same file for the site, the CLI scripts and the migrations.
 * `mode=rw` opens it read-write but never creates it, as Connection does: a
 * mis-set TAROT_DATA_DIR fails here rather than migrating a new empty file while
 * the live database stays behind. A new database starts as an empty file.
 */
return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
    ],
    'environments' => [
        'default_migration_table' => Schema::LOG_TABLE,
        'default_environment' => 'app',
        'app' => [
            'adapter' => 'sqlite',
            'name' => Paths::database(),
            // The name is the whole file name; Phinx would otherwise add .sqlite3.
            'suffix' => '',
            'mode' => 'rw',
        ],
    ],
    'version_order' => 'creation',
];
