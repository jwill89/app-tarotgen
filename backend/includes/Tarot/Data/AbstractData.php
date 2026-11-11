<?php

namespace Tarot\Data;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Shared database plumbing for the Data layer: a PDO handle plus a couple of
 * helpers that remove the repetitive prepare/execute/fetch boilerplate and the
 * hand-rolled "dynamic UPDATE from an allow-list" pattern.
 *
 * The PDO handle is injected so the Data layer never reaches for a global
 * singleton, which keeps it isolatable in tests (pass an in-memory SQLite PDO).
 *
 * Every row read fetches straight into a class through fetchOne()/fetchAllAs(),
 * not FETCH_ASSOC plus a hand-written hydrate(). So the SELECT must produce
 * exactly the class's properties:
 * - List the columns, never `*`: FETCH_CLASS turns a column the class doesn't
 *   declare into a dynamic property.
 * - Derive in SQL what SQL can derive (`… IS NOT NULL AS password_protected`,
 *   `EXISTS (…) AS has_passkeys`), which also keeps the source column in SQL.
 * - What SQL can't produce, the class converts itself: a JSON column arrives as
 *   text and the property's set hook decodes it (Spread::$positions).
 * - A row holding a secret goes into a class that keeps it private
 *   (UserCredentials); a row only the backend reads gets a Structure with no
 *   API schema (UserToken).
 * - FETCH_CLASS assigns with PHP's type rules and no casts, so a nullable
 *   column needs a nullable property.
 * Scalars (counts, existence, id lists) use fetchColumn()/FETCH_COLUMN.
 */
abstract class AbstractData
{
    /**
     * Construct first, then fill in the row. Plain FETCH_CLASS does it the other
     * way round, so a Structure constructor that set anything would overwrite
     * what was just fetched.
     */
    private const int FETCH_MODE = PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE;

    public function __construct(protected readonly PDO $db) {}

    /**
     * Run a parameterless query and return the executed statement.
     *
     * PDO is configured in exception mode (see Connection), so a failed query
     * throws rather than returning `false`; this wrapper makes that contract
     * explicit so callers can chain `->fetch*()` without a redundant guard.
     */
    protected function query(string $sql): PDOStatement
    {
        $stmt = $this->db->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Query failed: ' . $sql);
        }

        return $stmt;
    }

    /**
     * Fetch a single row hydrated into $class, or null when there is no match.
     *
     * @template T of object
     * @param array<array-key,mixed> $params Bound statement params (named or positional).
     * @param class-string<T> $class
     * @return T|null
     */
    protected function fetchOne(string $sql, array $params, string $class): ?object
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $stmt->setFetchMode(self::FETCH_MODE, $class);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Fetch every matching row hydrated into $class.
     *
     * @template T of object
     * @param array<array-key,mixed> $params Bound statement params (named or positional).
     * @param class-string<T> $class
     * @return list<T>
     */
    protected function fetchAllAs(string $sql, array $params, string $class): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // FETCH_CLASS yields a sequentially-indexed array — a list<T> by
        // construction, which PDO's signature can't express on its own.
        /** @var list<T> $rows */
        $rows = $stmt->fetchAll(self::FETCH_MODE, $class);

        return $rows;
    }

    /**
     * Build and run a dynamic UPDATE limited to an allow-list of columns.
     * Returns true when at least one column was written.
     *
     * @param array<string,mixed> $data     Incoming field => value pairs.
     * @param string[]            $allowed  Columns that may be updated.
     * @param array<string,mixed> $where    Column => value identifying the row(s).
     * @param string[]            $intCols  Columns cast to int.
     * @param string[]            $boolCols Columns cast to a 0/1 int.
     */
    protected function applyUpdate(
        string $table,
        array $data,
        array $allowed,
        array $where,
        array $intCols = [],
        array $boolCols = [],
    ): bool {
        $fields = [];
        $params = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            $fields[] = "{$key} = :{$key}";

            if (in_array($key, $boolCols, true)) {
                $params[":{$key}"] = (int) (bool) $value;
            } elseif (in_array($key, $intCols, true)) {
                $params[":{$key}"] = (int) $value;
            } else {
                $params[":{$key}"] = $value;
            }
        }

        if ($fields === []) {
            return false;
        }

        // WHERE placeholders are prefixed so they never collide with field names.
        $conditions = [];
        foreach ($where as $col => $val) {
            $conditions[] = "{$col} = :w_{$col}";
            $params[":w_{$col}"] = $val;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $fields)
             . " WHERE " . implode(' AND ', $conditions);

        $this->db->prepare($sql)->execute($params);

        return true;
    }
}
