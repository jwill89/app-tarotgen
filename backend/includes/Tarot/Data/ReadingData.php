<?php

namespace Tarot\Data;

use Closure;
use Tarot\Structure\Reading;
use Tarot\Structure\ReadingSpread;
use Tarot\Structure\ReadingSummary;
use Tarot\Structure\ReadingTag;
use Tarot\Utility\Clock;
use Throwable;

/**
 * The readings table and its child reading_tags: a reading's tags are written
 * and read with it.
 */
class ReadingData extends AbstractData
{
    /**
     * Columns selected for every Reading read. Note `password_hash` is exposed
     * only as the derived `password_protected` flag and `visibility` — the hash
     * itself is never hydrated onto the Reading (so it can't leak through
     * serialization); `is_private` likewise only as `visibility`. The tags come
     * as one JSON array, sorted. `daily_card_day` and `tags` name the table
     * (`readings.reading_id`), so these columns are read FROM readings without an
     * alias.
     */
    private const string SELECT_COLS
        = 'reading_id, reading_info, reading_time, user_id, hide_user, reading_name, reading_notes, is_final,
         CASE WHEN password_hash IS NOT NULL AND password_hash <> \'\' THEN 1 ELSE 0 END AS password_protected,
         (SELECT dc.day FROM daily_cards dc WHERE dc.reading_id = readings.reading_id) AS daily_card_day,
         question, querent,
         (SELECT json_group_array(t.tag)
            FROM (SELECT rt.tag FROM reading_tags rt WHERE rt.reading_id = readings.reading_id ORDER BY rt.tag) t) AS tags,
         CASE WHEN is_private = 1 THEN \'private\'
              WHEN password_hash IS NOT NULL AND password_hash <> \'\' THEN \'password\'
              ELSE \'link\' END AS visibility,
         expires_at';

    /** The spread name the reading page gives a free draw that was never placed. */
    private const string FREE_DRAW = 'Free Draw';

    /**
     * The spread a reading was laid out in, by the name it recorded: FREE_DRAW
     * for a free draw never placed (no spread, or a null one, as the reading
     * page and the journal export read it), NULL when it has no name to be
     * found by. Personal spreads, custom readings and placed free draws all
     * record spread_id 0, so a spread is known by its name. The spread filter
     * and the spread list both read this, so the list offers exactly what the
     * filter finds; the name is trimmed, as the filter's value is (an admin's
     * spread name is stored as typed). A reading_info that isn't JSON is NULL
     * here rather than an error (CASE stops at the first WHEN that holds).
     */
    private const string SPREAD_NAME
        = "CASE WHEN NOT json_valid(reading_info) THEN NULL
                WHEN IFNULL(json_type(reading_info, '$.spread'), 'null') = 'null' THEN '" . self::FREE_DRAW . "'
                WHEN json_type(reading_info, '$.spread.name') = 'text' THEN trim(json_extract(reading_info, '$.spread.name'))
           END";

    /**
     * A reading by id. One past its expiry (a guest's choice) is gone already,
     * for everyone, though the nightly retention run hasn't deleted it yet.
     */
    public function retrieve(string $reading_id): ?Reading
    {
        return $this->fetchOne(
            'SELECT ' . self::SELECT_COLS . ' FROM readings
             WHERE reading_id = :reading_id AND (expires_at IS NULL OR expires_at > :now)',
            [':reading_id' => $reading_id, ':now' => Clock::now()],
            Reading::class
        );
    }

    /**
     * A user's readings, newest first, narrowed by $filter. With a $limit, one
     * page of them (the account page); without, all of them (what the plugin's
     * list and the journal export read).
     *
     * @return list<Reading>
     */
    public function listByUser(int $userId, ?int $limit = null, int $offset = 0, ReadingFilter $filter = new ReadingFilter()): array
    {
        [$where, $params] = self::matching($userId, $filter);
        $sql = 'SELECT ' . self::SELECT_COLS . ' FROM readings WHERE ' . $where . '
                ORDER BY reading_time DESC, reading_id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params += [':limit' => $limit, ':offset' => $offset];
        }

        return $this->fetchAllAs($sql, $params, Reading::class);
    }

    /** How many of a user's readings $filter matches. */
    public function countByUser(int $userId, ReadingFilter $filter = new ReadingFilter()): int
    {
        [$where, $params] = self::matching($userId, $filter);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM readings WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every tag the user has given their readings, alphabetically, with how many
     * readings carry it.
     *
     * @return list<ReadingTag>
     */
    public function tagCounts(int $userId): array
    {
        return $this->fetchAllAs(
            'SELECT rt.tag, COUNT(*) AS count
             FROM readings r
             JOIN reading_tags rt ON rt.reading_id = r.reading_id
             WHERE r.user_id = :uid
             GROUP BY rt.tag
             ORDER BY rt.tag',
            [':uid' => $userId],
            ReadingTag::class
        );
    }

    /**
     * Every spread the user's readings were laid out in, by name, with how many
     * readings: the names the spread filter finds, alphabetically. Names that
     * differ only in ASCII case are one spread, as they are to the filter, shown
     * under the first of them in binary order (capitals first).
     *
     * @return list<ReadingSpread>
     */
    public function spreadCounts(int $userId): array
    {
        return $this->fetchAllAs(
            'SELECT MIN(spread) AS name, COUNT(*) AS count
             FROM (SELECT ' . self::SPREAD_NAME . ' AS spread FROM readings WHERE user_id = :uid)
             WHERE spread <> \'\'
             GROUP BY spread COLLATE NOCASE
             ORDER BY name COLLATE NOCASE',
            [':uid' => $userId],
            ReadingSpread::class
        );
    }

    /**
     * Save a new reading and its tags, in one transaction. `is_private` follows
     * the reading's visibility; a view password is passed as its hash.
     */
    public function store(Reading $reading, ?string $passwordHash = null): ?Reading
    {
        $this->atomically(function () use ($reading, $passwordHash): void {
            $this->db->prepare(
                'INSERT INTO readings (reading_id, reading_info, reading_time, user_id, hide_user, reading_name, reading_notes,
                                       password_hash, question, querent, is_private, expires_at)
                 VALUES (:reading_id, :reading_info, CURRENT_TIMESTAMP, :user_id, :hide_user, :reading_name, :reading_notes,
                         :password_hash, :question, :querent, :is_private, :expires_at)'
            )->execute([
                ':reading_id'    => $reading->reading_id,
                ':reading_info'  => $reading->reading_info,
                ':user_id'       => $reading->user_id,
                ':hide_user'     => $reading->hide_user ? 1 : 0,
                ':reading_name'  => $reading->reading_name,
                ':reading_notes' => $reading->reading_notes,
                ':password_hash' => $passwordHash,
                ':question'      => $reading->question,
                ':querent'       => $reading->querent,
                ':is_private'    => $reading->visibility === 'private' ? 1 : 0,
                ':expires_at'    => $reading->expires_at,
            ]);
            $this->insertTags($reading->reading_id, $reading->tags);
        });

        return $this->retrieve($reading->reading_id);
    }

    /** Verify a view password against the stored hash. */
    public function verifyPassword(string $reading_id, string $password): bool
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM readings WHERE reading_id = :id');
        $stmt->execute([':id' => $reading_id]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) && $hash !== '' && password_verify($password, $hash);
    }

    /**
     * Update owner-editable metadata, scoped to the owner so a non-owner can't
     * change someone else's reading. Only keys present in $fields are written:
     *   - reading_name, reading_notes, question, querent : string|null
     *   - hide_user, is_private                          : bool
     *   - password_hash : string|null  (null clears the password)
     *   - tags          : list<string> (replaces them all)
     *
     * @param array<string,mixed> $fields
     * @return Reading|null  The updated reading, or null when nothing matched
     *                       (wrong owner / unknown id / no fields).
     */
    public function updateMeta(string $reading_id, int $userId, array $fields): ?Reading
    {
        $set    = [];
        $params = [':id' => $reading_id, ':uid' => $userId];

        foreach (['reading_name', 'reading_notes', 'question', 'querent', 'password_hash'] as $column) {
            if (array_key_exists($column, $fields)) {
                $set[] = "{$column} = :{$column}";
                $params[":{$column}"] = $fields[$column];
            }
        }
        foreach (['hide_user', 'is_private'] as $flag) {
            if (array_key_exists($flag, $fields)) {
                $set[] = "{$flag} = :{$flag}";
                $params[":{$flag}"] = (bool) $fields[$flag] ? 1 : 0;
            }
        }
        $tags = $fields['tags'] ?? null;

        if ($set === [] && !is_array($tags)) {
            return null;
        }

        $owned = $this->db->prepare('SELECT 1 FROM readings WHERE reading_id = :id AND user_id = :uid');
        $owned->execute([':id' => $reading_id, ':uid' => $userId]);
        if ($owned->fetchColumn() === false) {
            return null;
        }

        $this->atomically(function () use ($set, $params, $tags, $reading_id): void {
            if ($set !== []) {
                $this->db->prepare('UPDATE readings SET ' . implode(', ', $set) . ' WHERE reading_id = :id AND user_id = :uid')
                    ->execute($params);
            }
            if (is_array($tags)) {
                $this->db->prepare('DELETE FROM reading_tags WHERE reading_id = :id')->execute([':id' => $reading_id]);
                $this->insertTags($reading_id, array_values(array_filter($tags, is_string(...))));
            }
        });

        return $this->retrieve($reading_id);
    }

    /**
     * Update the reading_info JSON blob for a reading. Optionally scoped to a
     * specific owner (pass null to allow any reading — e.g. guest readings
     * identified by ID alone).
     */
    public function updateReadingInfo(string $reading_id, string $readingInfo, ?int $userId = null): ?Reading
    {
        if ($userId !== null) {
            $stmt = $this->db->prepare(
                'UPDATE readings SET reading_info = :info WHERE reading_id = :id AND user_id = :uid'
            );
            $stmt->execute([':info' => $readingInfo, ':id' => $reading_id, ':uid' => $userId]);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE readings SET reading_info = :info WHERE reading_id = :id'
            );
            $stmt->execute([':info' => $readingInfo, ':id' => $reading_id]);
        }

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->retrieve($reading_id);
    }

    /**
     * Mark a reading as final (locked against further draws), scoped to its
     * owner. One-way: the flag is never cleared. Returns the updated reading, or
     * null when nothing matched (wrong owner / unknown id).
     */
    public function markFinal(string $reading_id, int $userId): ?Reading
    {
        $stmt = $this->db->prepare(
            'UPDATE readings SET is_final = 1 WHERE reading_id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $reading_id, ':uid' => $userId]);

        // rowCount() is 0 when the value was already 1, so re-read rather than
        // relying on it. Report success only when the row belongs to this owner
        // (guards against an unknown id or a non-owner caller).
        $reading = $this->retrieve($reading_id);
        if ($reading === null || $reading->user_id !== $userId) {
            return null;
        }

        return $reading;
    }

    /** Delete a reading, scoped to its owner. Returns true when a row was removed. */
    public function deleteForOwner(string $reading_id, int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM readings WHERE reading_id = :id AND user_id = :uid');
        $stmt->execute([':id' => $reading_id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** Admin: delete any reading regardless of owner. */
    public function delete(string $reading_id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM readings WHERE reading_id = :id');
        $stmt->execute([':id' => $reading_id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Admin: paginated list of all readings (lightweight — no reading_info body).
     * The page is cut first, so the JSON is read and the deck looked up only for
     * the rows shown.
     *
     * @return array{rows: list<ReadingSummary>, total: int}
     */
    public function listAll(int $limit, int $offset): array
    {
        $total = (int) $this->query('SELECT COUNT(*) FROM readings')->fetchColumn();

        $rows = $this->fetchAllAs(
            "SELECT page.reading_id, page.reading_time, page.user_id, page.display_name, page.deck_id,
                    COALESCE(d.name, 'Deck #' || page.deck_id) AS deck_name, page.spread_name
             FROM (
                 SELECT r.reading_id, r.reading_time, r.user_id,
                        COALESCE(u.display_name, 'Guest') AS display_name,
                        CAST(COALESCE(json_extract(r.reading_info, '$.deck_id'), 0) AS INTEGER) AS deck_id,
                        COALESCE(json_extract(r.reading_info, '$.spread.name'), '') AS spread_name
                 FROM readings r
                 LEFT JOIN users u ON r.user_id = u.user_id
                 ORDER BY r.reading_time DESC, r.reading_id DESC
                 LIMIT :limit OFFSET :offset
             ) page
             LEFT JOIN decks d ON d.deck_id = page.deck_id
             ORDER BY page.reading_time DESC, page.reading_id DESC",
            [':limit' => $limit, ':offset' => $offset],
            ReadingSummary::class
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Admin: delete guest (unowned) readings older than a given number of days.
     *
     * @return int Number of rows deleted.
     */
    public function cleanGuestOlderThan(int $days): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM readings
             WHERE user_id IS NULL
               AND reading_time < datetime('now', :interval)"
        );
        $stmt->execute([':interval' => "-{$days} days"]);

        return $stmt->rowCount();
    }

    /**
     * Delete guest readings past the expiry their maker chose. (Reads already
     * treat them as gone; this frees the rows.)
     *
     * @return int Number of rows deleted.
     */
    public function deleteExpiredGuest(): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM readings
             WHERE user_id IS NULL
               AND expires_at IS NOT NULL
               AND expires_at <= :now'
        );
        $stmt->execute([':now' => Clock::now()]);

        return $stmt->rowCount();
    }

    /**
     * The nightly retention run (scripts/retention.php): delete guest readings
     * past their chosen expiry, then those older than $days, in one transaction.
     * A dry run does exactly that and rolls it back, so its counts are what a
     * real run would delete.
     *
     * @return array{expired: int, aged: int}
     */
    public function purgeGuests(int $days, bool $dryRun): array
    {
        $this->db->beginTransaction();
        try {
            $counts = ['expired' => $this->deleteExpiredGuest(), 'aged' => $this->cleanGuestOlderThan($days)];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($dryRun) {
            $this->db->rollBack();
        } else {
            $this->db->commit();
        }

        return $counts;
    }

    /**
     * The WHERE clause and its parameters for a user's readings under $filter.
     * Every value is bound; the SQL around them is fixed text.
     *
     * @return array{string, array<string,mixed>}
     */
    private static function matching(int $userId, ReadingFilter $filter): array
    {
        $where  = ['user_id = :uid'];
        $params = [':uid' => $userId];

        if ($filter->text !== null) {
            // LIKE ignores ASCII case; the text's own % and _ are literal.
            $where[] = "(reading_name LIKE :text ESCAPE '!' OR reading_notes LIKE :text ESCAPE '!'
                         OR question LIKE :text ESCAPE '!' OR querent LIKE :text ESCAPE '!')";
            $params[':text'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter->text) . '%';
        }
        if ($filter->deckId !== null) {
            $where[] = "CAST(json_extract(reading_info, '$.deck_id') AS INTEGER) = :deck_id";
            $params[':deck_id'] = $filter->deckId;
        }
        if ($filter->spread !== null) {
            $where[] = '(' . self::SPREAD_NAME . ') = :spread COLLATE NOCASE';
            $params[':spread'] = $filter->spread;
        }
        if ($filter->tag !== null) {
            $where[] = 'reading_id IN (SELECT rt.reading_id FROM reading_tags rt WHERE rt.tag = :tag)';
            $params[':tag'] = $filter->tag;
        }
        if ($filter->from !== null) {
            $where[] = 'reading_time >= :from';
            $params[':from'] = $filter->from;
        }
        if ($filter->until !== null) {
            $where[] = 'reading_time < :until';
            $params[':until'] = $filter->until;
        }
        if ($filter->daily !== null) {
            $where[] = ($filter->daily ? '' : 'NOT ') . 'EXISTS (SELECT 1 FROM daily_cards dc WHERE dc.reading_id = readings.reading_id)';
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param list<string> $tags */
    private function insertTags(string $readingId, array $tags): void
    {
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO reading_tags (reading_id, tag) VALUES (:id, :tag)');
        foreach ($tags as $tag) {
            $stmt->execute([':id' => $readingId, ':tag' => $tag]);
        }
    }

    /**
     * Run $work in a transaction of its own, or as part of the caller's when one
     * is already open (the retention dry run, a test).
     *
     * @param Closure(): void $work
     */
    private function atomically(Closure $work): void
    {
        if ($this->db->inTransaction()) {
            $work();
            return;
        }

        $this->db->beginTransaction();
        try {
            $work();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
