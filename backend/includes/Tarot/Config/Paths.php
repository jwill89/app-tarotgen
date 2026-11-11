<?php

namespace Tarot\Config;

/**
 * Where the app's private files live: the `.env` and the SQLite database.
 *
 * In production these sit OUTSIDE the web root. The deploy flattens backend/
 * into the document root, so anything kept there is one missing .htaccess rule
 * away from being downloadable. The server sets TAROT_DATA_DIR (Apache
 * `SetEnv` for web requests, /etc/environment and the cron file for CLI) to a
 * directory holding `.env` and `db/tarotdb.db`.
 *
 * When TAROT_DATA_DIR is unset — local development — the project root is used,
 * i.e. backend/.env and backend/db/tarotdb.db.
 */
final class Paths
{
    private const string VARIABLE = 'TAROT_DATA_DIR';

    public static function dataDir(): string
    {
        // mod_php exposes Apache `SetEnv` values in $_SERVER and getenv(); the
        // CLI sees the process environment through getenv().
        $dir = $_SERVER[self::VARIABLE] ?? getenv(self::VARIABLE);

        if (is_string($dir) && $dir !== '') {
            return rtrim($dir, '/\\');
        }

        return dirname(__DIR__, 3);
    }

    public static function envFile(): string
    {
        return self::dataDir() . '/.env';
    }

    public static function database(): string
    {
        return self::dataDir() . '/db/tarotdb.db';
    }
}
