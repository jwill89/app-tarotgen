<?php

namespace Tarot\Data;

use PDOException;
use Throwable;
use Tarot\Exception\NameTakenException;
use Tarot\Structure\Deck;

class DeckData extends AbstractData
{
    // Columns are listed, not d.*: FETCH_CLASS turns any column Deck doesn't
    // declare into a dynamic property (deprecated, fatal in PHP 9), so a column
    // left over in a dev DB built from a stale schema would break every read.
    // artist and purchase_url are nullable columns (a PATCH can send null), and a
    // deck outlives a deleted system, leaving the join empty: a NULL into Deck's
    // string/int properties is a TypeError that would take down every deck list. A
    // 0 total is what getEffectiveTotalCards() reads as "no system".
    private const string SELECT_WITH_SYSTEM = "
        SELECT d.deck_id, d.deck_system_id, d.name,
               COALESCE(d.artist, '') AS artist, COALESCE(d.purchase_url, '') AS purchase_url, d.additional_cards,
               d.card_aspect_w, d.card_aspect_h, d.approved, d.usable, d.submitted_by,
               COALESCE(ds.short_name, '') AS system_short_name, COALESCE(ds.total_cards, 0) AS system_total_cards
        FROM decks d
        LEFT JOIN deck_systems ds ON ds.deck_system_id = d.deck_system_id
    ";

    /**
     * @return list<Deck>
     */
    public function retrieve(?int $deck_id = null): array
    {
        if ($deck_id !== null) {
            return $this->fetchAllAs(
                self::SELECT_WITH_SYSTEM . " WHERE d.deck_id = :deck_id",
                [':deck_id' => $deck_id],
                Deck::class
            );
        }

        return $this->fetchAllAs(self::SELECT_WITH_SYSTEM, [], Deck::class);
    }

    /**
     * Retrieve only decks marked as usable (for public-facing selectors).
     *
     * @return list<Deck>
     */
    public function retrieveUsable(): array
    {
        return $this->fetchAllAs(self::SELECT_WITH_SYSTEM . " WHERE d.usable = 1", [], Deck::class);
    }

    /**
     * Retrieve decks that are approved but not necessarily usable (for admin approved list).
     *
     * @return list<Deck>
     */
    public function retrieveApproved(): array
    {
        return $this->fetchAllAs(self::SELECT_WITH_SYSTEM . " WHERE d.approved = 1", [], Deck::class);
    }

    /**
     * Retrieve decks that are NOT approved (submitted, pending review).
     *
     * @return list<Deck>
     */
    public function retrievePending(): array
    {
        return $this->fetchAllAs(self::SELECT_WITH_SYSTEM . " WHERE d.approved = 0", [], Deck::class);
    }

    /**
     * Null when the deck couldn't be created — notably when its name is taken
     * (decks.name is UNIQUE), which callers report rather than crash on.
     *
     * @param array<string,mixed> $data
     */
    public function store(array $data): ?Deck
    {
        $sql = "INSERT INTO decks (name, artist, purchase_url, deck_system_id, additional_cards, card_aspect_w, card_aspect_h, approved, usable, submitted_by)
                VALUES (:name, :artist, :purchase_url, :deck_system_id, :additional_cards, :card_aspect_w, :card_aspect_h, :approved, :usable, :submitted_by)";

        $stmt = $this->db->prepare($sql);
        try {
            $stmt->execute([
                ':name'             => $data['name'] ?? '',
                ':artist'           => $data['artist'] ?? '',
                ':purchase_url'     => $data['purchase_url'] ?? '',
                ':deck_system_id'   => (int) ($data['deck_system_id'] ?? 1),
                ':additional_cards' => (int) ($data['additional_cards'] ?? 0),
                ':card_aspect_w'    => $this->clampAspect($data['card_aspect_w'] ?? 5),
                ':card_aspect_h'    => $this->clampAspect($data['card_aspect_h'] ?? 8.6),
                ':approved'         => (int) ($data['approved'] ?? true),
                ':usable'           => (int) ($data['usable'] ?? false),
                ':submitted_by'     => $data['submitted_by'] ?? null,
            ]);
        } catch (PDOException $e) {
            if (self::isNameTaken($e)) {
                return null;
            }
            throw $e;
        }

        $id = (int) $this->db->lastInsertId();
        $result = $this->retrieve($id);

        return $result[0] ?? null;
    }

    /**
     * Null when the deck doesn't exist or $data holds no editable field.
     *
     * @param array<string,mixed> $data
     * @throws NameTakenException When the new name is another deck's.
     */
    public function update(int $deck_id, array $data): ?Deck
    {
        // Clamp aspect components to a sane positive range before persisting.
        foreach (['card_aspect_w', 'card_aspect_h'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $this->clampAspect($data[$key]);
            }
        }

        $allowed   = ['name', 'artist', 'purchase_url', 'deck_system_id', 'additional_cards', 'card_aspect_w', 'card_aspect_h', 'approved', 'usable'];
        $boolCols  = ['approved', 'usable'];
        $intCols   = ['additional_cards', 'deck_system_id'];

        try {
            if (!$this->applyUpdate('decks', $data, $allowed, ['deck_id' => $deck_id], $intCols, $boolCols)) {
                return null;
            }
        } catch (PDOException $e) {
            if (self::isNameTaken($e)) {
                throw new NameTakenException('A deck with that name already exists.', 0, $e);
            }
            throw $e;
        }

        $result = $this->retrieve($deck_id);

        return $result[0] ?? null;
    }

    /** decks.name is UNIQUE: the one constraint error callers report rather than crash on. */
    private static function isNameTaken(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'UNIQUE constraint failed: decks.name');
    }

    /**
     * Keep aspect components positive and sane.
     */
    private function clampAspect(mixed $v): float
    {
        return max(0.1, min(100000.0, (float) $v));
    }

    /**
     * Delete a deck. Its special cards, card reports and favourites go with it:
     * every table that references decks does so ON DELETE CASCADE.
     */
    public function delete(int $deck_id): bool
    {
        try {
            return $this->db->prepare('DELETE FROM decks WHERE deck_id = :deck_id')
                ->execute([':deck_id' => $deck_id]);
        } catch (Throwable) {
            return false;
        }
    }
}
