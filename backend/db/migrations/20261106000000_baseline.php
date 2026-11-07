<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * The baseline: the schema the one-off db/ scripts had built by 2026-11-06,
 * frozen in 20261106000000_baseline.sql. Production already has it.
 *
 * It looks before it builds:
 *
 * - A database with no tables (Phinx's log aside) gets the whole schema, with
 *   the starting spread categories.
 * - Any other database must already hold every table and index defined there,
 *   each as defined there (layout and identifier quotes aside). Then nothing is
 *   changed, and Phinx records the baseline as run. Objects it doesn't define
 *   are left alone; later migrations drop the retired ones.
 * - Otherwise it refuses, naming what is missing or different, and nothing is
 *   recorded. A partly migrated or drifted database needs a person: creating
 *   only the missing tables would leave an old table's columns behind and still
 *   report success.
 */
final class Baseline extends AbstractMigration
{
    private const string SCHEMA = __DIR__ . '/20261106000000_baseline.sql';

    /** Each object's kind and name, its CREATE statement and its table; SQLite's own left out. */
    private const string OBJECTS = <<<'SQL'
        SELECT type || ' ' || name, sql, tbl_name FROM sqlite_master
        WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite\_%' ESCAPE '\'
        SQL;

    public function up(): void
    {
        $schema = (string) file_get_contents(self::SCHEMA);
        $log = $this->getAdapter()->getOption('migration_table');
        $log = is_string($log) ? $log : 'phinxlog';

        // Built first, so that on a live database the read below and Phinx's
        // write of its log row come as close together as they can.
        $scratch = new PDO('sqlite::memory:');
        $scratch->exec($schema);
        $expected = self::objects($scratch->query(self::OBJECTS), $log);

        $found = self::objects($this->query(self::OBJECTS), $log);

        if ($found === []) {
            $this->execute($schema);

            return;
        }

        $missing = array_keys(array_diff_key($expected, $found));
        $different = array_keys(array_filter(
            array_intersect_key($expected, $found),
            static fn(string $sql, string $object): bool => self::normalize($sql) !== self::normalize($found[$object]),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($missing !== [] || $different !== []) {
            throw new RuntimeException(sprintf(
                'The baseline changed nothing: this database is neither empty nor the schema in %s. Missing: %s. Different: %s. '
                . 'Bring it to that schema first, or start from an empty file (AGENTS.md §7).',
                basename(self::SCHEMA),
                $missing === [] ? 'none' : implode(', ', $missing),
                $different === [] ? 'none' : implode(', ', $different),
            ));
        }
    }

    /**
     * The baseline is the whole schema, and on production it holds the site.
     * Going back before it means restoring a backup, not dropping tables.
     */
    public function down(): never
    {
        throw new IrreversibleMigrationException('The baseline is the whole schema and is never rolled back; restore a backup instead.');
    }

    /**
     * @return array<string,string> "table users" => its CREATE statement, Phinx's log table left out
     */
    private static function objects(mixed $rows, string $log): array
    {
        if (!$rows instanceof PDOStatement) {
            throw new UnexpectedValueException('Could not read sqlite_master.');
        }

        $objects = [];
        foreach ($rows->fetchAll(PDO::FETCH_NUM) as [$object, $sql, $table]) {
            if ($table !== $log) {
                $objects[(string) $object] = (string) $sql;
            }
        }

        return $objects;
    }

    /** A statement without its layout or identifier quotes, which the live database's copy may not share. */
    private static function normalize(string $sql): string
    {
        $sql = trim((string) preg_replace('/\s+/', ' ', str_replace('"', '', $sql)));

        return (string) preg_replace('/ ?([(),]) ?/', '$1', $sql);
    }
}
