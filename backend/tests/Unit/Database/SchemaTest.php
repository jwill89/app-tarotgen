<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Database\Schema;

/**
 * The migrations are the schema; db/schema.sql is generated from them
 * (scripts/dump_schema.php) and is what every other test loads. So it must be
 * exactly what the migrations build.
 */
#[CoversClass(Schema::class)]
final class SchemaTest extends TestCase
{
    use ScratchDatabases;

    public function testSchemaSqlIsWhatTheMigrationsBuild(): void
    {
        $this->assertSame(
            $this->schemaSql(),
            Schema::build(),
            'db/schema.sql is out of date: run `php scripts/dump_schema.php` and commit the result.',
        );
    }

    public function testSchemaSqlLoadsIntoTheDatabaseTheMigrationsBuild(): void
    {
        // Doesn't trust the dump: loading the file must give the same objects,
        // byte for byte, and the same rows (the seeds, sqlite_sequence).
        $migrated = $this->emptyDatabase('migrated.db');
        Schema::migrate($migrated);
        $loaded = $this->databaseFrom($this->schemaSql(), 'loaded.db');

        $snapshot = $this->snapshot($migrated);
        $this->assertSame($this->snapshot($loaded), $snapshot);
        $this->assertSame(
            ['General', 'Love & relationships', 'Career', 'Decisions', 'Self-reflection', 'Seasonal'],
            array_column($snapshot['spread_categories'], 'name'),
        );
    }

    public function testDumpListsObjectsInCreationOrderEachTableWithItsRows(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec(<<<'SQL'
            CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT, weight REAL, extra TEXT);
            INSERT INTO notes VALUES (1, 'it''s', 1.5, NULL), (2, 'two', 2.0, 'x');
            CREATE INDEX idx_notes_body ON notes (body);
            CREATE TABLE phinxlog (version INTEGER);
            INSERT INTO phinxlog VALUES (20261106000000);
            CREATE TABLE empty (id INTEGER)
            SQL);

        $dump = Schema::dump($db);

        $this->assertStringStartsWith('-- TarotGen.io database schema', $dump);
        $this->assertStringEndsWith(<<<'SQL'
            PRAGMA foreign_keys = ON;

            CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT, weight REAL, extra TEXT);

            INSERT INTO notes (id, body, weight, extra) VALUES
                (1, 'it''s', 1.5, NULL),
                (2, 'two', 2.0, 'x');

            CREATE INDEX idx_notes_body ON notes (body);

            CREATE TABLE empty (id INTEGER);

            SQL, $dump);
        $this->assertStringNotContainsString(Schema::LOG_TABLE, $dump);
    }
}
