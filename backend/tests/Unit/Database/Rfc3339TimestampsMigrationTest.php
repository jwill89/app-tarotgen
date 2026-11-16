<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tarot\Database\Schema;

/**
 * db/migrations/20261115000000_rfc3339_timestamps.php on a database the
 * migrations before it built, holding rows in the space form SQLite's
 * CURRENT_TIMESTAMP writes, as production's do.
 */
#[CoversClass(Schema::class)]
final class Rfc3339TimestampsMigrationTest extends TestCase
{
    use ScratchDatabases;

    /** The last migration before it. */
    private const int BEFORE = 20261106000001;

    private const string TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /** Every timestamp (DATETIME) and date (DATE) column, by table. */
    private const array COLUMNS = [
        'card_report_submissions' => ['submitted_at' => 'DATETIME'],
        'card_reports' => ['resolved_at' => 'DATETIME', 'first_reported_at' => 'DATETIME', 'last_reported_at' => 'DATETIME'],
        'contacts' => ['submitted_at' => 'DATETIME'],
        'daily_cards' => ['day' => 'DATE'],
        'pending_spreads' => ['submitted_at' => 'DATETIME'],
        'plugin_auth_codes' => ['created_at' => 'DATETIME', 'expires_at' => 'DATETIME', 'used_at' => 'DATETIME'],
        'plugin_draw_keys' => ['code_expires_at' => 'DATETIME', 'created_at' => 'DATETIME', 'issued_at' => 'DATETIME', 'last_used_at' => 'DATETIME', 'revoked_at' => 'DATETIME'],
        'plugin_tokens' => ['created_at' => 'DATETIME', 'last_used_at' => 'DATETIME', 'expires_at' => 'DATETIME', 'revoked_at' => 'DATETIME'],
        'readings' => ['reading_time' => 'DATETIME', 'expires_at' => 'DATETIME'],
        'user_favorite_decks' => ['created_at' => 'DATETIME'],
        'user_favorite_spreads' => ['created_at' => 'DATETIME'],
        'user_passkeys' => ['created_at' => 'DATETIME', 'last_used_at' => 'DATETIME'],
        'user_spreads' => ['created_at' => 'DATETIME', 'updated_at' => 'DATETIME'],
        'user_tokens' => ['expires_at' => 'DATETIME', 'created_at' => 'DATETIME', 'used_at' => 'DATETIME'],
        'users' => ['registered_at' => 'DATETIME', 'last_login_at' => 'DATETIME'],
    ];

    /**
     * A row in every table it rebuilds, written as the code before it wrote
     * them, and a few already in the new form: the deploy swaps the code in
     * before it migrates, so the new code can write rows in between.
     */
    private const string ROWS = <<<'SQL'
        INSERT INTO users (user_id, email, password_hash, display_name, is_active, registered_at, last_login_at) VALUES
            (1, 'reader@example.test', 'x', 'Reader', 1, '2026-11-07 09:18:09', '2026-11-08T10:00:00Z'),
            (2, 'new@example.test', 'x', 'New', 0, '2026-11-07 23:59:59', ''),
            (3, 'gone@example.test', 'x', 'Gone', 0, '2026-11-07 10:00:00', NULL);
        DELETE FROM users WHERE user_id = 3;
        INSERT INTO decks (deck_id, name) VALUES (1, 'Deck');
        INSERT INTO readings (reading_id, reading_info, reading_time, user_id, expires_at) VALUES
            ('guest00001', '{}', '2026-11-06 08:00:00', NULL, '2026-11-13 08:00:00'),
            ('mine000001', '{}', '2026-11-07 09:18:09', 1, NULL),
            ('guest00002', '{}', '2026-11-07T11:18:09+02:00', NULL, ''),
            ('mine000002', '{}', '2026-11-08T10:00:01Z', 1, NULL),
            ('guest00003', '{}', '2026-11-08T10:00:02Z', NULL, '2026-11-15T10:00:02Z');
        INSERT INTO reading_tags (reading_id, tag) VALUES ('mine000001', 'love');
        INSERT INTO daily_cards (user_id, day, reading_id) VALUES (1, '2026-11-07', 'mine000001');
        INSERT INTO contacts (user_id, name, email, message, submitted_at) VALUES (1, 'Reader', 'reader@example.test', 'Hello', '2026-11-07 09:18:09');
        INSERT INTO contacts (name, email, message) VALUES ('Guest', 'guest@example.test', 'Hi');
        INSERT INTO pending_spreads (name, user_id, submitted_at) VALUES ('Crossroads', 1, '2026-11-07 09:18:09');
        INSERT INTO user_favorite_decks (user_id, deck_id, created_at) VALUES (1, 1, '2026-11-07 09:18:09');
        INSERT INTO user_favorite_spreads (user_id, spread_type, spread_id, created_at) VALUES (1, 'public', 1, '2026-11-07 09:18:09');
        INSERT INTO user_passkeys (user_id, credential_id, public_key_pem, created_at, last_used_at) VALUES (1, 'credential', 'pem', '2026-11-07 09:18:09', NULL);
        INSERT INTO user_spreads (user_id, name, created_at, updated_at) VALUES (1, 'Mine', '2026-11-07 09:18:09', '2026-11-08 09:18:09');
        INSERT INTO user_tokens (user_id, type, token_hash, expires_at, created_at, used_at) VALUES (2, 'activation', 'token', '2026-11-08 09:18:09', '2026-11-07 09:18:09', '');
        INSERT INTO plugin_tokens (user_id, token_hash, created_at, last_used_at, expires_at, revoked_at) VALUES (1, 'token', '2026-11-07 09:18:09', '2026-11-07 09:20:00', '', NULL);
        INSERT INTO plugin_auth_codes (user_id, code_hash, code_challenge, created_at, expires_at, used_at) VALUES (1, 'code', 'challenge', '2026-11-07 09:18:09', '2026-11-07 09:23:09', NULL);
        INSERT INTO plugin_draw_keys (code_hash, code_challenge, code_expires_at, key_hash, created_at, issued_at, last_used_at, revoked_at)
            VALUES ('code', 'challenge', '2026-11-07 09:28:09', 'key', '2026-11-07 09:18:09', '2026-11-07 09:19:00', '2026-11-07 09:30:00.250', NULL);
        INSERT INTO card_reports (deck_id, card_id, card_name, resolved_at, first_reported_at, last_reported_at) VALUES (1, 0, 'The Fool', NULL, '2026-11-07 09:18:09', '2026-11-08 09:18:09');
        INSERT INTO card_report_submissions (report_id, round, reason, submitted_at) VALUES (1, 1, 'blurry', '2026-11-07 09:18:09');
        SQL;

    public function testEveryTimestampIsRewrittenAndEachColumnDeclaredAndChecked(): void
    {
        $declared = $this->declarations($this->oldDatabase('reference.db'));
        $path = $this->oldDatabase();
        $this->execute($path, self::ROWS);
        $before = $this->snapshot($path);
        $readingRowids = $this->rows(new PDO('sqlite:' . $path), 'SELECT rowid, reading_id FROM readings ORDER BY rowid');

        Schema::migrate($path);

        $this->assertSame($this->migrations(), $this->migrationsRun($path));
        $after = $this->snapshot($path);
        $db = $this->open($path);

        // The schema the migrations build, which db/schema.sql holds.
        $this->assertSame($this->snapshot($this->databaseFrom($this->schemaSql(), 'fresh.db'))['(objects)'], $after['(objects)']);

        // Each row kept; each value in the new form, an offset moved to UTC, a
        // fraction of a second dropped, an empty string now NULL, and a value
        // already in the new form left as it was.
        foreach ($before as $table => $rows) {
            $this->assertCount(count($rows), $after[$table], $table);
        }
        $this->assertSame([
            ['reading_id' => 'guest00001', 'reading_time' => '2026-11-06T08:00:00Z', 'expires_at' => '2026-11-13T08:00:00Z'],
            ['reading_id' => 'mine000001', 'reading_time' => '2026-11-07T09:18:09Z', 'expires_at' => null],
            ['reading_id' => 'guest00002', 'reading_time' => '2026-11-07T09:18:09Z', 'expires_at' => null],
            ['reading_id' => 'mine000002', 'reading_time' => '2026-11-08T10:00:01Z', 'expires_at' => null],
            ['reading_id' => 'guest00003', 'reading_time' => '2026-11-08T10:00:02Z', 'expires_at' => '2026-11-15T10:00:02Z'],
        ], $this->rows($db, 'SELECT reading_id, reading_time, expires_at FROM readings ORDER BY rowid'));
        $this->assertSame($readingRowids, $this->rows($db, 'SELECT rowid, reading_id FROM readings ORDER BY rowid'));
        $this->assertSame([
            ['user_id' => 1, 'registered_at' => '2026-11-07T09:18:09Z', 'last_login_at' => '2026-11-08T10:00:00Z'],
            ['user_id' => 2, 'registered_at' => '2026-11-07T23:59:59Z', 'last_login_at' => null],
        ], $this->rows($db, 'SELECT user_id, registered_at, last_login_at FROM users ORDER BY user_id'));
        $this->assertSame([['last_used_at' => '2026-11-07T09:30:00Z']], $this->rows($db, 'SELECT last_used_at FROM plugin_draw_keys'));
        $this->assertSame([['used_at' => null]], $this->rows($db, 'SELECT used_at FROM user_tokens'));
        $this->assertSame([['expires_at' => null]], $this->rows($db, 'SELECT expires_at FROM plugin_tokens'));
        $this->assertSame([['day' => '2026-11-07']], $this->rows($db, 'SELECT day FROM daily_cards'));
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $type) {
                foreach (array_column($after[$table], $column) as $value) {
                    if ($value !== null) {
                        $this->assertMatchesRegularExpression($type === 'DATE' ? '/^\d{4}-\d{2}-\d{2}$/' : self::TIMESTAMP, (string) $value, "{$table}.{$column}");
                    }
                }
            }
        }

        // Exactly these columns are declared DATETIME or DATE, a default of now
        // writes the new form, and every other column is declared as it was.
        foreach ($this->declarations($path) as $table => $columns) {
            $this->assertSame(array_keys($declared[$table]), array_keys($columns), $table);
            foreach ($columns as $name => $column) {
                $was = $declared[$table][$name];
                $type = self::COLUMNS[$table][$name] ?? null;
                if ($type === null) {
                    $this->assertSame($was, $column, "{$table}.{$name}");
                    continue;
                }
                $this->assertSame($type, $column['type'], "{$table}.{$name}");
                $this->assertSame($was['notnull'], $column['notnull'], "{$table}.{$name}");
                $this->assertSame(
                    $was['dflt_value'] === 'CURRENT_TIMESTAMP' ? "strftime('%Y-%m-%dT%H:%M:%SZ', 'now')" : $was['dflt_value'],
                    $column['dflt_value'],
                    "{$table}.{$name}",
                );
            }
        }

        // The sequence of a table whose last row was deleted stays past it.
        $this->assertSame([['seq' => 3]], $this->rows($db, "SELECT seq FROM sqlite_sequence WHERE name = 'users'"));
        $db->exec("INSERT INTO users (email, password_hash, display_name) VALUES ('next@example.test', 'x', 'Next')");
        $this->assertSame(4, (int) $db->lastInsertId());

        // The foreign keys still hold and still point at their parents.
        $this->assertSame([], $this->rows($db, 'PRAGMA foreign_key_check'));
        $db->exec('DELETE FROM users WHERE user_id = 1');
        $this->assertSame([], $this->rows($db, "SELECT reading_id FROM readings WHERE user_id = 1"));
        $this->assertSame([], $this->rows($db, 'SELECT * FROM daily_cards'));
        $this->assertSame([], $this->rows($db, "SELECT tag FROM reading_tags WHERE reading_id = 'mine000001'"));
        $this->assertSame([['user_id' => null], ['user_id' => null]], $this->rows($db, 'SELECT user_id FROM contacts'));
        $this->assertSame([], $this->rows($db, 'SELECT * FROM plugin_tokens'));
    }

    public function testEachColumnRefusesAnyOtherFormAndDefaultsToNowInItsOwn(): void
    {
        $path = $this->oldDatabase();
        $this->execute($path, self::ROWS);
        Schema::migrate($path);
        $db = $this->open($path);

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $type) {
                $refused = $type === 'DATE'
                    ? ['2026-11-07T00:00:00Z', '2026-11-7', '2026-02-30', '']
                    : ['2026-11-07 09:18:09', '2026-11-07T09:18:09+00:00', '2026-11-07T09:18:09.250Z', '2026-02-30T09:18:09Z', '2026-11-07', ''];
                foreach ($refused as $value) {
                    try {
                        $db->prepare("UPDATE \"{$table}\" SET \"{$column}\" = :value")->execute([':value' => $value]);
                        $this->fail("{$table}.{$column} took '{$value}'.");
                    } catch (PDOException $e) {
                        $this->assertStringContainsString('CHECK constraint failed', $e->getMessage(), "{$table}.{$column} = '{$value}'");
                    }
                }

                $good = $type === 'DATE' ? '2026-11-08' : '2026-11-08T07:06:05Z';
                $db->prepare("UPDATE \"{$table}\" SET \"{$column}\" = :value")->execute([':value' => $good]);
                $this->assertSame([[$column => $good]], $this->rows($db, "SELECT \"{$column}\" FROM \"{$table}\" LIMIT 1"));
            }
        }

        $db->exec("INSERT INTO contacts (name, email, message) VALUES ('Later', 'later@example.test', 'Hi')");
        $now = $this->rows($db, "SELECT submitted_at, strftime('%s', 'now') - strftime('%s', submitted_at) AS age FROM contacts WHERE name = 'Later'");
        $this->assertMatchesRegularExpression(self::TIMESTAMP, (string) $now[0]['submitted_at']);
        $this->assertLessThanOrEqual(2, abs((int) $now[0]['age']));
    }

    public function testTheIndexesAreRecreatedAsTheyWere(): void
    {
        $path = $this->oldDatabase();
        $indexes = "SELECT name, tbl_name, sql FROM sqlite_master WHERE type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY name";
        $before = $this->rows(new PDO('sqlite:' . $path), $indexes);

        Schema::migrate($path);

        $this->assertNotEmpty($before);
        $this->assertSame($before, $this->rows(new PDO('sqlite:' . $path), $indexes));
    }

    public function testAValueThatIsNoDateStopsItWithNothingChanged(): void
    {
        $path = $this->oldDatabase();
        $this->execute($path, self::ROWS . "UPDATE readings SET expires_at = 'next week' WHERE reading_id = 'guest00001';");
        $before = $this->snapshot($path);

        try {
            Schema::migrate($path);
            $this->fail('Expected the migration to refuse a value that is not a date.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('readings.expires_at holds 1 value(s) that are not a date', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot($path));
        $this->assertSame(['Baseline', 'DropRetiredTables'], $this->migrationsRun($path));
    }

    public function testATableWithOtherColumnsStopsItWithNothingChanged(): void
    {
        $path = $this->oldDatabase();
        $this->execute($path, self::ROWS . 'ALTER TABLE contacts ADD COLUMN phone TEXT;');
        $before = $this->snapshot($path);

        try {
            Schema::migrate($path);
            $this->fail('Expected the migration to refuse a table with columns it does not list.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Table contacts has the columns contact_id, user_id, name, email, message, is_read, submitted_at, phone', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot($path));
        $this->assertSame(['Baseline', 'DropRetiredTables'], $this->migrationsRun($path));
    }

    /** A database the migrations before this one built, as production holds. */
    private function oldDatabase(string $name = 'tarotdb.db'): string
    {
        $path = $this->emptyDatabase($name);
        Schema::migrate($path, self::BEFORE);

        return $path;
    }

    /**
     * Each table's columns as PRAGMA table_info describes them, by name.
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function declarations(string $path): array
    {
        $db = new PDO('sqlite:' . $path);
        $tables = [];
        foreach ($this->rows($db, "SELECT name FROM sqlite_master WHERE type = 'table' AND name <> 'phinxlog' ORDER BY name") as $table) {
            foreach ($this->rows($db, "PRAGMA table_info(\"{$table['name']}\")") as $column) {
                unset($column['cid']);
                $tables[(string) $table['name']][(string) $column['name']] = $column;
            }
        }

        return $tables;
    }

    private function open(string $path): PDO
    {
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');

        return $db;
    }
}
