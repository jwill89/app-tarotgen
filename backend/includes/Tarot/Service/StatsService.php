<?php

namespace Tarot\Service;

use PDO;

/**
 * Read-only usage analytics for the admin dashboard. Aggregates the saved
 * `readings` rows — using SQLite's JSON_extract over each row's reading_info
 * snapshot — into a small overview payload. Purely reporting: it never writes.
 */
class StatsService
{
    public function __construct(private readonly PDO $db) {}

    /**
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        return [
            'totals'     => $this->totals(),
            'byType'     => $this->byType(),
            'topDecks'   => $this->topDecks(),
            'topSpreads' => $this->topSpreads(),
            'daily'      => $this->daily(),
        ];
    }

    /**
     * Row counts for the admin dashboard tiles — one cheap COUNT(*) per table,
     * instead of fetching whole lists just to measure their length.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        return [
            'deckSystems'        => $this->countRows('deck_systems'),
            'decks'              => $this->countRows('decks'),
            'specialCards'       => $this->countRows('special_cards'),
            'spreads'            => $this->countRows('spreads'),
            'readings'           => $this->countRows('readings'),
            'users'              => $this->countRows('users'),
            'unreadContacts'     => $this->countUnreadContacts(),
            'openCardReports'    => $this->countOpenCardReports(),
            // Every queued submission is waiting: approving or rejecting removes it.
            'pendingSpreads'     => $this->countRows('pending_spreads'),
            'pendingDeckSystems' => $this->countPendingDeckSystems(),
        ];
    }

    /** $table comes only from the fixed allow-list in counts(), never user input. */
    private function countRows(string $table): int
    {
        return $this->scalarInt("SELECT COUNT(*) FROM $table");
    }

    private function countUnreadContacts(): int
    {
        return $this->scalarInt("SELECT COUNT(*) FROM contacts WHERE is_read = 0");
    }

    /**
     * Submitted systems waiting for review. Unlike a spread submission, one stays
     * in its table: approving it flips the flag, rejecting it deletes the row.
     */
    private function countPendingDeckSystems(): int
    {
        return $this->scalarInt("SELECT COUNT(*) FROM deck_systems WHERE approved = 0");
    }

    /** Reported cards still waiting for a fix (one row per card, however often reported). */
    private function countOpenCardReports(): int
    {
        return $this->scalarInt("SELECT COUNT(*) FROM card_reports WHERE resolved_at IS NULL");
    }

    /** @return array{readings:int,last7:int,last30:int} */
    private function totals(): array
    {
        $row = $this->row(
            "SELECT
                COUNT(*) AS readings,
                SUM(CASE WHEN reading_time >= datetime('now', '-7 days')  THEN 1 ELSE 0 END) AS last7,
                SUM(CASE WHEN reading_time >= datetime('now', '-30 days') THEN 1 ELSE 0 END) AS last30
             FROM readings"
        );

        return [
            'readings' => (int) ($row['readings'] ?? 0),
            'last7'    => (int) ($row['last7'] ?? 0),
            'last30'   => (int) ($row['last30'] ?? 0),
        ];
    }

    /** @return array{freeDraw:int,spread:int,custom:int} */
    private function byType(): array
    {
        $row = $this->row(
            "SELECT
                SUM(CASE WHEN json_extract(reading_info, '$.spread') IS NULL THEN 1 ELSE 0 END) AS free_draw,
                SUM(CASE WHEN json_extract(reading_info, '$.spread.spread_id') > 0 THEN 1 ELSE 0 END) AS spread,
                SUM(CASE WHEN json_extract(reading_info, '$.spread.spread_id') = 0 THEN 1 ELSE 0 END) AS custom
             FROM readings"
        );

        return [
            'freeDraw' => (int) ($row['free_draw'] ?? 0),
            'spread'   => (int) ($row['spread'] ?? 0),
            'custom'   => (int) ($row['custom'] ?? 0),
        ];
    }

    /** @return list<array{deck_id:int,name:string,count:int}> */
    private function topDecks(): array
    {
        $rows = $this->rows(
            "SELECT json_extract(reading_info, '$.deck_id') AS deck_id, COUNT(*) AS count
             FROM readings
             GROUP BY deck_id
             ORDER BY count DESC
             LIMIT 10"
        );

        // Resolve names in one pass instead of joining on a JSON-extracted column.
        $names = [];
        foreach ($this->rows("SELECT deck_id, name FROM decks") as $d) {
            $names[(int) $d['deck_id']] = (string) $d['name'];
        }

        $out = [];
        foreach ($rows as $row) {
            $deckId = (int) $row['deck_id'];
            $out[] = [
                'deck_id' => $deckId,
                'name'    => $names[$deckId] ?? ('Deck #' . $deckId),
                'count'   => (int) $row['count'],
            ];
        }

        return $out;
    }

    /** @return list<array{name:string,count:int}> */
    private function topSpreads(): array
    {
        $rows = $this->rows(
            "SELECT json_extract(reading_info, '$.spread.name') AS name, COUNT(*) AS count
             FROM readings
             WHERE json_extract(reading_info, '$.spread.spread_id') > 0
             GROUP BY name
             ORDER BY count DESC
             LIMIT 10"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name'  => (string) ($row['name'] ?? 'Untitled'),
                'count' => (int) $row['count'],
            ];
        }

        return $out;
    }

    /** @return list<array{date:string,count:int}> Readings per day for the last 14 days. */
    private function daily(): array
    {
        $rows = $this->rows(
            "SELECT date(reading_time) AS date, COUNT(*) AS count
             FROM readings
             WHERE reading_time >= datetime('now', '-13 days')
             GROUP BY date
             ORDER BY date"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = ['date' => (string) $row['date'], 'count' => (int) $row['count']];
        }

        return $out;
    }

    // ── Query helpers ────────────────────────────────────────────
    // PDO runs in exception mode (see Connection), so query() never returns
    // false — these wrappers make that explicit and remove the repeated
    // fetch-mode/`?: []` boilerplate from every aggregate above.

    /** A single scalar COUNT/aggregate as an int (0 when there's no row). */
    private function scalarInt(string $sql): int
    {
        return (int) $this->run($sql)->fetchColumn();
    }

    /**
     * The first row as an associative array (empty when there's no match).
     *
     * @return array<string,mixed>
     */
    private function row(string $sql): array
    {
        $row = $this->run($sql)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * Every row as a list of associative arrays.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->run($sql)->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function run(string $sql): \PDOStatement
    {
        $stmt = $this->db->query($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Stats query failed: ' . $sql);
        }

        return $stmt;
    }
}
