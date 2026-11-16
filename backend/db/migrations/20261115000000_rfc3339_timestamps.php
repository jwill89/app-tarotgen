<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Stores every timestamp as RFC 3339 in UTC, to the second
 * (2026-11-07T09:18:09Z), and every date as 2026-11-07, and makes each column
 * refuse any other form.
 *
 * Before it, those columns were TEXT holding SQLite's space form
 * (2026-11-07 09:18:09, what CURRENT_TIMESTAMP writes) and refused nothing.
 * Text compares by its characters and a space sorts before a T, so a bound in
 * one form counts the wrong rows of a column in the other. SQLite can't change
 * a column's declared type or add a CHECK in place, so each table below is
 * rebuilt by SQLite's documented procedure:
 *
 * - a timestamp column is declared DATETIME with
 *   CHECK (col IS strftime('%Y-%m-%dT%H:%M:%SZ', col)), which refuses the space
 *   form, an offset, fractional seconds and impossible dates, and a default of
 *   now is DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));
 * - a date column (daily_cards.day) is declared DATE with CHECK (col IS date(col));
 * - each value is rewritten in that form (an offset moved to UTC, fractions of
 *   a second dropped), and an empty string, which is no time at all, becomes NULL.
 *   A value already in that form, which the new code can write in the moments
 *   between a deploy's code swap and its migration, comes out unchanged.
 *
 * Every other column and constraint is declared as it was; the indexes and
 * triggers are put back from their own statements, the rowids are kept, and so
 * is each AUTOINCREMENT table's sequence, so a deleted id is still never
 * reused. sessions.expires is left an INTEGER: it holds Unix seconds, written
 * and compared only as PHP's time().
 *
 * It refuses, changing nothing, when a table's columns aren't the ones listed
 * here, when a value isn't a date and time SQLite can read (it would otherwise
 * become NULL), or when the connection has foreign keys on, where dropping a
 * table deletes the rows that reference it. Phinx runs it in one transaction
 * with them off; it ends with PRAGMA foreign_key_check.
 */
final class Rfc3339Timestamps extends AbstractMigration
{
    /**
     * Each rebuilt table's column definitions and table constraints, columns
     * in the order the table already has them.
     */
    private const array TABLES = [
        'contacts' => <<<'SQL'
                contact_id   INTEGER  PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER  DEFAULT NULL,
                name         TEXT     NOT NULL,
                email        TEXT     NOT NULL,
                message      TEXT     NOT NULL,
                is_read      INTEGER  NOT NULL DEFAULT 0,
                submitted_at DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                             CHECK (submitted_at IS strftime('%Y-%m-%dT%H:%M:%SZ', submitted_at)),
                FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
            SQL,
        'pending_spreads' => <<<'SQL'
                pending_id   INTEGER  PRIMARY KEY AUTOINCREMENT,
                name         TEXT     NOT NULL DEFAULT '',
                description  TEXT     NOT NULL DEFAULT '',
                card_count   INTEGER  NOT NULL DEFAULT 1,
                positions    TEXT     NOT NULL DEFAULT '[]',
                submitter    TEXT     NOT NULL DEFAULT '',
                submitted_at DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                             CHECK (submitted_at IS strftime('%Y-%m-%dT%H:%M:%SZ', submitted_at)),
                user_id      INTEGER  DEFAULT NULL REFERENCES users (user_id) ON DELETE SET NULL
            SQL,
        'readings' => <<<'SQL'
                reading_id    TEXT     NOT NULL UNIQUE,
                reading_info  TEXT     NOT NULL,
                reading_time  DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                              CHECK (reading_time IS strftime('%Y-%m-%dT%H:%M:%SZ', reading_time)),
                user_id       INTEGER  DEFAULT NULL REFERENCES users (user_id) ON DELETE CASCADE,
                hide_user     INTEGER  NOT NULL DEFAULT 0,
                reading_name  TEXT     DEFAULT NULL,
                password_hash TEXT     DEFAULT NULL,
                reading_notes TEXT     DEFAULT NULL,
                is_final      INTEGER  NOT NULL DEFAULT 0,
                question      TEXT     DEFAULT NULL,
                querent       TEXT     DEFAULT NULL,
                is_private    INTEGER  NOT NULL DEFAULT 0,
                expires_at    DATETIME DEFAULT NULL
                              CHECK (expires_at IS strftime('%Y-%m-%dT%H:%M:%SZ', expires_at)),
                PRIMARY KEY (reading_id)
            SQL,
        'user_favorite_decks' => <<<'SQL'
                user_id    INTEGER  NOT NULL REFERENCES users (user_id) ON DELETE CASCADE,
                deck_id    INTEGER  NOT NULL REFERENCES decks (deck_id) ON DELETE CASCADE,
                created_at DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                           CHECK (created_at IS strftime('%Y-%m-%dT%H:%M:%SZ', created_at)),
                PRIMARY KEY (user_id, deck_id)
            SQL,
        'user_favorite_spreads' => <<<'SQL'
                user_id     INTEGER  NOT NULL REFERENCES users (user_id) ON DELETE CASCADE,
                spread_type TEXT     NOT NULL CHECK (spread_type IN ('public', 'personal')),
                spread_id   INTEGER  NOT NULL,
                created_at  DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                            CHECK (created_at IS strftime('%Y-%m-%dT%H:%M:%SZ', created_at)),
                PRIMARY KEY (user_id, spread_type, spread_id)
            SQL,
        'user_passkeys' => <<<'SQL'
                passkey_id     INTEGER  PRIMARY KEY AUTOINCREMENT,
                user_id        INTEGER  NOT NULL,
                credential_id  TEXT     NOT NULL,
                public_key_pem TEXT     NOT NULL,
                name           TEXT     NOT NULL DEFAULT 'My Passkey',
                sign_count     INTEGER  NOT NULL DEFAULT 0,
                created_at     DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                               CHECK (created_at IS strftime('%Y-%m-%dT%H:%M:%SZ', created_at)),
                last_used_at   DATETIME DEFAULT NULL
                               CHECK (last_used_at IS strftime('%Y-%m-%dT%H:%M:%SZ', last_used_at)),
                FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
            SQL,
        'user_spreads' => <<<'SQL'
                user_spread_id INTEGER  PRIMARY KEY AUTOINCREMENT,
                user_id        INTEGER  NOT NULL REFERENCES users (user_id) ON DELETE CASCADE,
                name           TEXT     NOT NULL DEFAULT '',
                description    TEXT     NOT NULL DEFAULT '',
                card_count     INTEGER  NOT NULL DEFAULT 1,
                positions      TEXT     NOT NULL DEFAULT '[]',
                created_at     DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                               CHECK (created_at IS strftime('%Y-%m-%dT%H:%M:%SZ', created_at)),
                updated_at     DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                               CHECK (updated_at IS strftime('%Y-%m-%dT%H:%M:%SZ', updated_at))
            SQL,
        'user_tokens' => <<<'SQL'
                token_id   INTEGER  PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER  NOT NULL,
                type       TEXT     NOT NULL,
                token_hash TEXT     NOT NULL,
                expires_at DATETIME NOT NULL
                           CHECK (expires_at IS strftime('%Y-%m-%dT%H:%M:%SZ', expires_at)),
                created_at DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                           CHECK (created_at IS strftime('%Y-%m-%dT%H:%M:%SZ', created_at)),
                used_at    DATETIME DEFAULT NULL
                           CHECK (used_at IS strftime('%Y-%m-%dT%H:%M:%SZ', used_at)),
                FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
            SQL,
        'plugin_tokens' => <<<'SQL'
                id           INTEGER  PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER  NOT NULL REFERENCES users (user_id) ON DELETE CASCADE,
                token_hash   TEXT     NOT NULL,
                label        TEXT     NOT NULL DEFAULT 'TarotGen plugin',
                scope        TEXT     NOT NULL DEFAULT 'account',
                created_at   DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                             CHECK (created_at IS strftime('%Y-%m-%dT%H:%M:%SZ', created_at)),
                last_used_at DATETIME DEFAULT NULL
                             CHECK (last_used_at IS strftime('%Y-%m-%dT%H:%M:%SZ', last_used_at)),
                expires_at   DATETIME DEFAULT NULL
                             CHECK (expires_at IS strftime('%Y-%m-%dT%H:%M:%SZ', expires_at)),
                revoked_at   DATETIME DEFAULT NULL
                             CHECK (revoked_at IS strftime('%Y-%m-%dT%H:%M:%SZ', revoked_at))
            SQL,
        'plugin_auth_codes' => <<<'SQL'
                id             INTEGER  PRIMARY KEY AUTOINCREMENT,
                user_id        INTEGER  NOT NULL REFERENCES users (user_id) ON DELETE CASCADE,
                code_hash      TEXT     NOT NULL,
                code_challenge TEXT     NOT NULL,
                scope          TEXT     NOT NULL DEFAULT 'account',
                created_at     DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                               CHECK (created_at IS strftime('%Y-%m-%dT%H:%M:%SZ', created_at)),
                expires_at     DATETIME NOT NULL
                               CHECK (expires_at IS strftime('%Y-%m-%dT%H:%M:%SZ', expires_at)),
                used_at        DATETIME DEFAULT NULL
                               CHECK (used_at IS strftime('%Y-%m-%dT%H:%M:%SZ', used_at))
            SQL,
        'plugin_draw_keys' => <<<'SQL'
                id              INTEGER  PRIMARY KEY AUTOINCREMENT,
                code_hash       TEXT     NOT NULL,
                code_challenge  TEXT     NOT NULL,
                code_expires_at DATETIME NOT NULL
                                CHECK (code_expires_at IS strftime('%Y-%m-%dT%H:%M:%SZ', code_expires_at)),
                key_hash        TEXT     DEFAULT NULL,
                created_at      DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                                CHECK (created_at IS strftime('%Y-%m-%dT%H:%M:%SZ', created_at)),
                issued_at       DATETIME DEFAULT NULL
                                CHECK (issued_at IS strftime('%Y-%m-%dT%H:%M:%SZ', issued_at)),
                last_used_at    DATETIME DEFAULT NULL
                                CHECK (last_used_at IS strftime('%Y-%m-%dT%H:%M:%SZ', last_used_at)),
                revoked_at      DATETIME DEFAULT NULL
                                CHECK (revoked_at IS strftime('%Y-%m-%dT%H:%M:%SZ', revoked_at))
            SQL,
        'card_reports' => <<<'SQL'
                report_id         INTEGER  PRIMARY KEY AUTOINCREMENT,
                deck_id           INTEGER  NOT NULL REFERENCES decks (deck_id) ON DELETE CASCADE,
                card_id           INTEGER  NOT NULL,
                card_name         TEXT     NOT NULL DEFAULT '',
                report_count      INTEGER  NOT NULL DEFAULT 1,
                resolved_at       DATETIME DEFAULT NULL
                                  CHECK (resolved_at IS strftime('%Y-%m-%dT%H:%M:%SZ', resolved_at)),
                first_reported_at DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                                  CHECK (first_reported_at IS strftime('%Y-%m-%dT%H:%M:%SZ', first_reported_at)),
                last_reported_at  DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                                  CHECK (last_reported_at IS strftime('%Y-%m-%dT%H:%M:%SZ', last_reported_at)),
                round             INTEGER  NOT NULL DEFAULT 1
            SQL,
        'card_report_submissions' => <<<'SQL'
                submission_id INTEGER  PRIMARY KEY AUTOINCREMENT,
                report_id     INTEGER  NOT NULL REFERENCES card_reports (report_id) ON DELETE CASCADE,
                round         INTEGER  NOT NULL,
                reason        TEXT     NOT NULL,
                details       TEXT     NOT NULL DEFAULT '',
                submitted_at  DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                              CHECK (submitted_at IS strftime('%Y-%m-%dT%H:%M:%SZ', submitted_at))
            SQL,
        'daily_cards' => <<<'SQL'
                user_id    INTEGER NOT NULL REFERENCES users (user_id) ON DELETE CASCADE,
                day        DATE    NOT NULL CHECK (day IS date(day)),
                reading_id TEXT    DEFAULT NULL REFERENCES readings (reading_id) ON DELETE SET NULL,
                PRIMARY KEY (user_id, day)
            SQL,
        'users' => <<<'SQL'
                user_id                 INTEGER  PRIMARY KEY AUTOINCREMENT,
                email                   TEXT     NOT NULL COLLATE NOCASE,
                password_hash           TEXT     NOT NULL,
                display_name            TEXT     NOT NULL COLLATE NOCASE,
                is_active               INTEGER  NOT NULL DEFAULT 0,
                is_admin                INTEGER  NOT NULL DEFAULT 0,
                registered_at           DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                                        CHECK (registered_at IS strftime('%Y-%m-%dT%H:%M:%SZ', registered_at)),
                last_login_at           DATETIME DEFAULT NULL
                                        CHECK (last_login_at IS strftime('%Y-%m-%dT%H:%M:%SZ', last_login_at)),
                google_id               TEXT     DEFAULT NULL,
                password_login_disabled INTEGER  NOT NULL DEFAULT 0
            SQL,
    ];

    public function up(): void
    {
        if ((int) $this->value('PRAGMA foreign_keys') !== 0) {
            throw new RuntimeException('Foreign keys are on, so dropping a table to rebuild it would delete the rows that reference it. Nothing was changed.');
        }

        foreach (self::TABLES as $table => $definition) {
            $this->rebuild($table, $definition);
        }

        $broken = $this->rows('PRAGMA foreign_key_check');
        if ($broken !== []) {
            throw new RuntimeException(sprintf(
                'The rebuilt tables break %d foreign key(s), first in %s; nothing was changed.',
                count($broken),
                (string) $broken[0]['table'],
            ));
        }
    }

    /** Going back would mean writing the space form again; restore the pre-deploy snapshot instead. */
    public function down(): never
    {
        throw new IrreversibleMigrationException('The timestamps are not put back in the space form; restore a backup instead.');
    }

    /**
     * Rebuild $table as $definition declares it: create the new table, copy and
     * convert the rows, drop the old table, rename the new one, and put back the
     * sequence, indexes and triggers.
     */
    private function rebuild(string $table, string $definition): void
    {
        $new = "new_{$table}";
        $this->execute("CREATE TABLE \"{$new}\" (\n{$definition}\n)");

        $columns = $this->columns($new);
        $found = array_keys($this->columns($table));
        if ($found !== array_keys($columns)) {
            throw new RuntimeException(sprintf(
                'Table %s has the columns %s, not the %s this migration rebuilds; nothing was changed.',
                $table,
                implode(', ', $found),
                implode(', ', array_keys($columns)),
            ));
        }

        $values = [];
        foreach ($columns as $column => $type) {
            $value = match ($type) {
                'DATETIME' => "strftime('%Y-%m-%dT%H:%M:%SZ', NULLIF(\"{$column}\", ''))",
                'DATE' => "date(NULLIF(\"{$column}\", ''))",
                default => "\"{$column}\"",
            };
            if ($value !== "\"{$column}\"") {
                $this->refuseUnreadable($table, $column, $value);
            }
            $values[] = $value;
        }

        // A table whose INTEGER PRIMARY KEY is its rowid copies it as that
        // column; any other keeps its rowids, and with them its rows' order.
        $rowid = $this->rowidIsAColumn($table) ? '' : 'rowid, ';
        $this->execute(sprintf(
            'INSERT INTO "%s" (%s%s) SELECT %s%s FROM "%s"',
            $new,
            $rowid,
            implode(', ', array_map(static fn(string $column): string => "\"{$column}\"", array_keys($columns))),
            $rowid,
            implode(', ', $values),
            $table,
        ));

        $before = (int) $this->value("SELECT COUNT(*) FROM \"{$table}\"");
        $after = (int) $this->value("SELECT COUNT(*) FROM \"{$new}\"");
        if ($before !== $after) {
            throw new RuntimeException("Rebuilding {$table} copied {$after} of its {$before} rows; nothing was changed.");
        }

        $sequence = $this->rows('SELECT seq FROM sqlite_sequence WHERE name = :table', [':table' => $table]);
        $dependents = $this->rows(
            "SELECT sql FROM sqlite_master WHERE tbl_name = :table AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY rowid",
            [':table' => $table],
        );

        // In this order, so that no other table's foreign key is rewritten to
        // follow a rename: they name $table, which exists again once new_ takes
        // its name.
        $this->execute("DROP TABLE \"{$table}\"");
        $this->execute("ALTER TABLE \"{$new}\" RENAME TO \"{$table}\"");

        // The copy started new_'s sequence at the highest id copied; the old
        // one may be higher, from rows deleted since.
        $this->execute('DELETE FROM sqlite_sequence WHERE name = :table', [':table' => $table]);
        if ($sequence !== []) {
            // Bound values arrive as text, which seq (a column with no type) would keep.
            $this->execute(
                'INSERT INTO sqlite_sequence (name, seq) VALUES (:table, CAST(:seq AS INTEGER))',
                [':table' => $table, ':seq' => (int) $sequence[0]['seq']],
            );
        }

        foreach ($dependents as $dependent) {
            $this->execute((string) $dependent['sql']);
        }
    }

    /** Stops the migration if $table.$column holds a value that $converted would turn into NULL. */
    private function refuseUnreadable(string $table, string $column, string $converted): void
    {
        $unreadable = (int) $this->value("SELECT COUNT(*) FROM \"{$table}\" WHERE \"{$column}\" <> '' AND {$converted} IS NULL");
        if ($unreadable > 0) {
            throw new RuntimeException("{$table}.{$column} holds {$unreadable} value(s) that are not a date SQLite can read; nothing was changed.");
        }
    }

    /**
     * Each column's name and declared type, in table order.
     *
     * @return array<string,string>
     */
    private function columns(string $table): array
    {
        $columns = [];
        foreach ($this->rows("PRAGMA table_info(\"{$table}\")") as $column) {
            $columns[(string) $column['name']] = strtoupper((string) $column['type']);
        }

        return $columns;
    }

    /** Whether $table's primary key is a single INTEGER column, which SQLite makes its rowid. */
    private function rowidIsAColumn(string $table): bool
    {
        $key = array_values(array_filter($this->rows("PRAGMA table_info(\"{$table}\")"), static fn(array $column): bool => (int) $column['pk'] > 0));

        return count($key) === 1 && strtoupper((string) $key[0]['type']) === 'INTEGER';
    }

    private function value(string $sql): mixed
    {
        $row = $this->rows($sql)[0] ?? [];

        return reset($row);
    }

    /**
     * @param array<string,int|string> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        if (!$stmt instanceof PDOStatement) {
            throw new UnexpectedValueException('Query failed: ' . $sql);
        }
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $rows;
    }
}
