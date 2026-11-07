<?php

namespace Tarot\Database;

use PDO;
use PDOException;
use Pdo\Sqlite;
use RuntimeException;
use Tarot\Config\Paths;

final class Connection
{
    // Access Through Connection
    private static PDO $conn;

    // Prevent New Object Instantiation
    private function __construct() {}

    // Prevent cloning
    private function __clone() {}

    public static function getInstance(): PDO
    {
        // If the connection isn't set, set it.
        if (!isset(self::$conn)) {
            // An absolute path (TAROT_DATA_DIR, or the project root in dev), so it
            // resolves identically regardless of the caller's working directory
            // (web request from /, /api, the og.php shim, or a CLI/cron script).
            $path = Paths::database();

            // Open read-write but never CREATE: a mis-set TAROT_DATA_DIR must fail
            // loudly, not silently start a brand-new empty database (the site
            // would look wiped, and a migration would run against nothing).
            try {
                self::$conn = new PDO('sqlite:' . $path, null, null, [
                    Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READWRITE,
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException("Cannot open the database at {$path} (check TAROT_DATA_DIR).", 0, $e);
            }
            self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Enforce declared foreign keys (off by default in SQLite), wait a
            // few seconds instead of failing instantly when the file is locked,
            // and use WAL for better read/write concurrency.
            self::$conn->exec('PRAGMA foreign_keys = ON');
            self::$conn->exec('PRAGMA busy_timeout = 5000');
            self::$conn->exec('PRAGMA journal_mode = WAL');
            // With WAL, NORMAL is the recommended durability level: it drops the
            // per-transaction fsync (only syncing at checkpoints), which sharply
            // cuts write latency for the frequent session-row writes every
            // authenticated request makes. Trade-off: a transaction committed in
            // the last moment before an OS crash / power loss can roll back — no
            // corruption. Acceptable here; revert to FULL if strict durability is
            // required.
            self::$conn->exec('PRAGMA synchronous = NORMAL');
        }

        return self::$conn;
    }
}
