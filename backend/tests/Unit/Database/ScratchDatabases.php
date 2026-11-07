<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\After;

/**
 * Throwaway SQLite files for running the migrations on, in a temp dir of
 * their own that goes after each test. Every helper opens and closes its own
 * connection, so nothing holds a file open when it is migrated or deleted.
 */
trait ScratchDatabases
{
    private ?string $scratchDir = null;

    /** An empty file: what Schema::migrate() builds the whole schema in. */
    private function emptyDatabase(string $name = 'tarotdb.db'): string
    {
        if ($this->scratchDir === null) {
            $this->scratchDir = sys_get_temp_dir() . '/tarot-migrations-' . bin2hex(random_bytes(4));
            mkdir($this->scratchDir);
        }

        $path = $this->scratchDir . '/' . $name;
        touch($path);

        return $path;
    }

    /** A database built by running $sql, as `sqlite3 x.db < schema.sql` would. */
    private function databaseFrom(string $sql, string $name = 'tarotdb.db'): string
    {
        $path = $this->emptyDatabase($name);
        $this->execute($path, $sql);

        return $path;
    }

    private function schemaSql(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql');
    }

    private function execute(string $path, string $sql): void
    {
        new PDO('sqlite:' . $path)->exec($sql);
    }

    /**
     * Everything a migration could change: each object's definition and each
     * table's rows (sqlite_sequence included), with Phinx's log left out.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function snapshot(string $path): array
    {
        $db = new PDO('sqlite:' . $path);
        $snapshot = ['(objects)' => $this->rows($db, "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE tbl_name <> 'phinxlog' ORDER BY type, name")];

        foreach ($this->rows($db, "SELECT name FROM sqlite_master WHERE type = 'table' AND name <> 'phinxlog' ORDER BY name") as $table) {
            $snapshot[(string) $table['name']] = $this->rows($db, "SELECT * FROM \"{$table['name']}\"");
        }

        return $snapshot;
    }

    /**
     * The migrations Phinx has recorded as run, oldest first.
     *
     * @return list<string>
     */
    private function migrationsRun(string $path): array
    {
        $db = new PDO('sqlite:' . $path);

        return array_map(
            static fn(array $row): string => (string) $row['migration_name'],
            $this->rows($db, 'SELECT migration_name FROM phinxlog ORDER BY version'),
        );
    }

    /**
     * Every migration in db/migrations, by class name, oldest first.
     *
     * @return list<string>
     */
    private function migrations(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/db/migrations/*.php');

        return array_map(
            static fn(string $file): string => str_replace('_', '', ucwords((string) preg_replace('/^\d+_/', '', basename($file, '.php')), '_')),
            $files === false ? [] : $files,
        );
    }

    /** @return list<array<string,mixed>> */
    private function rows(PDO $db, string $sql): array
    {
        $stmt = $db->prepare($sql);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    #[After]
    protected function removeScratchDatabases(): void
    {
        if ($this->scratchDir === null) {
            return;
        }

        $files = glob($this->scratchDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->scratchDir);
        $this->scratchDir = null;
    }
}
