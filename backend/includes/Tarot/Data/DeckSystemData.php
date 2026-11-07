<?php

namespace Tarot\Data;

use PDOException;
use Tarot\Exception\NameTakenException;
use Tarot\Structure\DeckSystem;
use Tarot\Structure\DeckSystemCard;

class DeckSystemData extends AbstractData
{
    // Listed, not *: FETCH_CLASS turns any column the Structure doesn't declare
    // into a dynamic property (deprecated, fatal in PHP 9) — AUDIT §1.2/§2.4.
    private const string SYSTEM_COLS = 'deck_system_id, name, short_name, total_cards, approved, submitted_by';
    private const string CARD_COLS = 'deck_system_id, card_id, name, keywords, meaning, advice, '
        . 'reversed_keywords, reversed_meaning, reversed_advice';

    /**
     * @return list<DeckSystem>
     */
    public function retrieve(?int $id = null): array
    {
        if ($id !== null) {
            return $this->fetchAllAs(
                "SELECT " . self::SYSTEM_COLS . " FROM deck_systems WHERE deck_system_id = :id",
                [':id' => $id],
                DeckSystem::class
            );
        }

        return $this->fetchAllAs(
            "SELECT " . self::SYSTEM_COLS . " FROM deck_systems ORDER BY name",
            [],
            DeckSystem::class
        );
    }

    /**
     * @return list<DeckSystem>
     */
    public function retrieveApproved(): array
    {
        return $this->fetchAllAs(
            "SELECT " . self::SYSTEM_COLS . " FROM deck_systems WHERE approved = 1 ORDER BY name",
            [],
            DeckSystem::class
        );
    }

    /**
     * @return list<DeckSystem>
     */
    public function retrievePending(): array
    {
        return $this->fetchAllAs(
            "SELECT " . self::SYSTEM_COLS . " FROM deck_systems WHERE approved = 0 ORDER BY name",
            [],
            DeckSystem::class
        );
    }

    /**
     * Null when the system couldn't be created — notably when its name is taken
     * (deck_systems.name is UNIQUE), which callers report rather than crash on.
     *
     * @param array<string,mixed> $data
     */
    public function store(array $data): ?DeckSystem
    {
        $sql = "INSERT INTO deck_systems (name, short_name, total_cards, approved, submitted_by)
                VALUES (:name, :short_name, :total_cards, :approved, :submitted_by)";

        $stmt = $this->db->prepare($sql);
        try {
            $stmt->execute([
                ':name'         => $data['name'] ?? '',
                ':short_name'   => $data['short_name'] ?? '',
                ':total_cards'  => (int) ($data['total_cards'] ?? 78),
                ':approved'     => (int) ($data['approved'] ?? false),
                ':submitted_by' => $data['submitted_by'] ?? null,
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
     * Null when the system doesn't exist or $data holds no editable field.
     *
     * @param array<string,mixed> $data
     * @throws NameTakenException When the new name is another system's.
     */
    public function update(int $id, array $data): ?DeckSystem
    {
        $allowed  = ['name', 'short_name', 'total_cards', 'approved'];
        $boolCols = ['approved'];
        $intCols  = ['total_cards'];

        try {
            if (!$this->applyUpdate('deck_systems', $data, $allowed, ['deck_system_id' => $id], $intCols, $boolCols)) {
                return null;
            }
        } catch (PDOException $e) {
            if (self::isNameTaken($e)) {
                throw new NameTakenException('A deck system with that name already exists.', 0, $e);
            }
            throw $e;
        }

        $result = $this->retrieve($id);
        return $result[0] ?? null;
    }

    /** deck_systems.name is UNIQUE (short_name isn't): the one constraint error callers report. */
    private static function isNameTaken(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'UNIQUE constraint failed: deck_systems.name');
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM deck_systems WHERE deck_system_id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── Deck System Cards ────────────────────────────────────────

    /**
     * @return list<DeckSystemCard>
     */
    public function retrieveCards(int $systemId): array
    {
        return $this->fetchAllAs(
            "SELECT " . self::CARD_COLS . " FROM deck_system_cards WHERE deck_system_id = :id ORDER BY card_id",
            [':id' => $systemId],
            DeckSystemCard::class
        );
    }

    /**
     * @param list<int> $cardIds
     * @return list<DeckSystemCard>
     */
    public function retrieveCardsByIds(int $systemId, array $cardIds): array
    {
        if ($cardIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($cardIds), '?'));
        $params = array_merge([$systemId], $cardIds);

        return $this->fetchAllAs(
            "SELECT " . self::CARD_COLS . " FROM deck_system_cards
             WHERE deck_system_id = ? AND card_id IN ($placeholders)",
            $params,
            DeckSystemCard::class
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public function storeCard(array $data): bool
    {
        $sql = "INSERT OR REPLACE INTO deck_system_cards (deck_system_id, card_id, name, keywords, meaning, advice, reversed_keywords, reversed_meaning, reversed_advice)
                VALUES (:sys, :cid, :name, :kw, :meaning, :advice, :rkw, :rm, :ra)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':sys'     => (int) ($data['deck_system_id'] ?? 0),
            ':cid'     => (int) ($data['card_id'] ?? 0),
            ':name'    => $data['name'] ?? '',
            ':kw'      => $data['keywords'] ?? null,
            ':meaning' => $data['meaning'] ?? null,
            ':advice'  => $data['advice'] ?? null,
            ':rkw'     => $data['reversed_keywords'] ?? null,
            ':rm'      => $data['reversed_meaning'] ?? null,
            ':ra'      => $data['reversed_advice'] ?? null,
        ]);

        return true;
    }

    /**
     * @param list<array<string,mixed>> $cards
     */
    public function storeCards(int $systemId, array $cards): void
    {
        $sql = "INSERT OR REPLACE INTO deck_system_cards (deck_system_id, card_id, name, keywords, meaning, advice, reversed_keywords, reversed_meaning, reversed_advice)
                VALUES (:sys, :cid, :name, :kw, :meaning, :advice, :rkw, :rm, :ra)";

        $stmt = $this->db->prepare($sql);

        foreach ($cards as $card) {
            $stmt->execute([
                ':sys'     => $systemId,
                ':cid'     => (int) ($card['card_id'] ?? 0),
                ':name'    => $card['name'] ?? '',
                ':kw'      => $card['keywords'] ?? null,
                ':meaning' => $card['meaning'] ?? null,
                ':advice'  => $card['advice'] ?? null,
                ':rkw'     => $card['reversed_keywords'] ?? null,
                ':rm'      => $card['reversed_meaning'] ?? null,
                ':ra'      => $card['reversed_advice'] ?? null,
            ]);
        }
    }

    public function deleteCards(int $systemId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM deck_system_cards WHERE deck_system_id = :id");
        $stmt->execute([':id' => $systemId]);
        return true;
    }
}
