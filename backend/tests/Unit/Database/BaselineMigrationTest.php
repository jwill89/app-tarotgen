<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Database;

use InvalidArgumentException;
use Phinx\Config\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tarot\Config\Paths;
use Tarot\Database\Schema;

/**
 * The baseline (db/migrations/20261106000000_baseline.php) and the migrations
 * after it, run as the deploy runs them: with phinx.php's settings, on a file.
 * An empty file gets the whole schema (SchemaTest); a database that already
 * has it, as production does, must come out exactly as it went in.
 */
#[CoversClass(Schema::class)]
#[CoversClass(Paths::class)]
final class BaselineMigrationTest extends TestCase
{
    use ScratchDatabases;

    public function testADatabaseBuiltFromSchemaSqlIsLeftExactlyAsItWas(): void
    {
        $path = $this->databaseFrom($this->schemaSql());
        // Data the baseline must not touch: no reseeding of a category an admin
        // renamed or removed.
        $this->execute($path, <<<'SQL'
            INSERT INTO users (email, password_hash, display_name, is_active) VALUES ('reader@example.test', 'x', 'Reader', 1);
            INSERT INTO readings (reading_id, reading_info, user_id) VALUES ('abc123', '{}', 1);
            INSERT INTO daily_cards (user_id, day, reading_id) VALUES (1, '2026-11-06', 'abc123');
            UPDATE spread_categories SET name = 'Love' WHERE category_id = 2;
            DELETE FROM spread_categories WHERE category_id = 6;
            SQL);
        $before = $this->snapshot($path);

        Schema::migrate($path);

        $this->assertSame($before, $this->snapshot($path));
        $this->assertSame($this->migrations(), $this->migrationsRun($path));
        $this->assertSame('Baseline', $this->migrationsRun($path)[0]);

        // And again: nothing left to run, nothing changes.
        Schema::migrate($path);

        $this->assertSame($before, $this->snapshot($path));
        $this->assertSame($this->migrations(), $this->migrationsRun($path));
    }

    public function testLayoutAndIdentifierQuotesAreNotDrift(): void
    {
        // The same schema, each statement on one line, no space inside brackets
        // or before commas, two names quoted: a database built by other hands
        // can hold the same definitions laid out differently, and the baseline
        // accepts them as they are.
        $sql = (string) preg_replace('/^--.*$/m', '', $this->schemaSql());
        $sql = (string) preg_replace('/\s+/', ' ', $sql);
        $sql = str_replace(['( ', ' )', ' ,'], ['(', ')', ','], $sql);
        $sql = str_replace(['CREATE TABLE users (', 'ON contacts (user_id)'], ['CREATE TABLE "users" (', 'ON "contacts" ("user_id")'], $sql);
        $path = $this->databaseFrom($sql);
        $before = $this->snapshot($path);

        Schema::migrate($path);

        $this->assertSame($before, $this->snapshot($path));
        $this->assertSame($this->migrations(), $this->migrationsRun($path));
    }

    public function testAPartlyMigratedDatabaseIsRefusedAndLeftAsItWas(): void
    {
        // As if the daily-card and sign-in-method scripts had never run.
        $path = $this->databaseFrom($this->schemaSql());
        $this->execute($path, 'DROP TABLE daily_cards; ALTER TABLE users DROP COLUMN password_login_disabled;');
        $before = $this->snapshot($path);

        try {
            Schema::migrate($path);
            $this->fail('Expected the baseline to refuse a partly migrated database.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Missing: table daily_cards, index idx_daily_cards_reading.', $e->getMessage());
            $this->assertStringContainsString('Different: table users.', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot($path));
        $this->assertSame([], $this->migrationsRun($path));
    }

    public function testADatabaseOfSomethingElseIsRefused(): void
    {
        // A mis-pointed TAROT_DATA_DIR that found another app's database.
        $path = $this->databaseFrom('CREATE TABLE media (media_id INTEGER PRIMARY KEY, file_name TEXT NOT NULL);');

        try {
            Schema::migrate($path);
            $this->fail('Expected the baseline to refuse a database that holds something else.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Missing: table contacts, table deck_system_cards', $e->getMessage());
            $this->assertStringContainsString('Different: none.', $e->getMessage());
        }

        $this->assertSame(['media'], array_column($this->snapshot($path)['(objects)'], 'name'));
        $this->assertSame([], $this->migrationsRun($path));
    }

    public function testTheRetiredTablesAreDroppedAndNothingElse(): void
    {
        // The share relay's tables and the old changelog, still there with rows.
        $path = $this->databaseFrom($this->schemaSql());
        $this->execute($path, <<<'SQL'
            CREATE TABLE plugin_clients (client_id INTEGER PRIMARY KEY, token_hash TEXT NOT NULL, user_id INTEGER REFERENCES users(user_id));
            CREATE INDEX idx_plugin_clients_hash ON plugin_clients (token_hash);
            CREATE TABLE plugin_client_identities (client_id INTEGER NOT NULL REFERENCES plugin_clients(client_id), identity_hash TEXT NOT NULL);
            CREATE INDEX idx_client_identities_hash ON plugin_client_identities (identity_hash);
            CREATE TABLE plugin_messages (message_id INTEGER PRIMARY KEY, to_client INTEGER NOT NULL REFERENCES plugin_clients(client_id));
            CREATE TABLE plugin_blocks (blocker INTEGER NOT NULL, blocked INTEGER NOT NULL, PRIMARY KEY (blocker, blocked));
            CREATE TABLE changelog (id INTEGER PRIMARY KEY, body TEXT NOT NULL);
            INSERT INTO plugin_clients VALUES (1, 'hash', NULL);
            INSERT INTO plugin_client_identities VALUES (1, 'identity');
            INSERT INTO changelog VALUES (1, 'An old entry');
            SQL);

        Schema::migrate($path);

        $this->assertSame($this->snapshot($this->databaseFrom($this->schemaSql(), 'plain.db')), $this->snapshot($path));
        $this->assertSame($this->migrations(), $this->migrationsRun($path));
    }

    public function testMigratingNeverCreatesADatabase(): void
    {
        // phinx.php's mode=rw, as Connection: a wrong path is an error, not a new
        // empty database with the whole schema in it.
        $path = dirname($this->emptyDatabase()) . '/missing.db';

        try {
            Schema::migrate($path);
            $this->fail('Expected Phinx to refuse a database that does not exist.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unable to open database file', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testPhinxOpensTheDatabaseTheAppOpens(): void
    {
        putenv('TAROT_DATA_DIR=/var/lib/tarotgen');
        try {
            $config = Config::fromPhp(dirname(__DIR__, 3) . '/phinx.php');
        } finally {
            putenv('TAROT_DATA_DIR');
        }

        $app = $config->getEnvironment('app');
        $this->assertIsArray($app);
        $this->assertSame('/var/lib/tarotgen/db/tarotdb.db', $app['name']);
        $this->assertSame(['adapter' => 'sqlite', 'suffix' => '', 'mode' => 'rw', 'migration_table' => Schema::LOG_TABLE], array_diff_key($app, ['name' => true]));
        $this->assertSame('app', $config->getDefaultEnvironment());
        $this->assertSame([realpath(dirname(__DIR__, 3) . '/db/migrations')], array_map(realpath(...), $config->getMigrationPaths()));
    }
}
