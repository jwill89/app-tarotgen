<?php

namespace Tarot\Data;

use PDOException;
use Tarot\Structure\SpecialCard;

class SpecialCardData extends AbstractData
{
    // Listed, not *: FETCH_CLASS turns any column the Structure doesn't declare
    // into a dynamic property (deprecated, fatal in PHP 9).
    private const string SELECT_COLS = 'deck_id, card_id, name, keywords, meaning, advice, '
        . 'keywords_reversed, meaning_reversed, advice_reversed';

    public function retrieve(int $deck_id, int $card_id): ?SpecialCard
    {
        return $this->fetchOne(
            "SELECT " . self::SELECT_COLS . " FROM special_cards WHERE deck_id = :deck_id AND card_id = :card_id",
            [':deck_id' => $deck_id, ':card_id' => $card_id],
            SpecialCard::class
        );
    }

    /**
     * @param list<int> $card_ids
     * @return list<SpecialCard>
     */
    public function retrieveMultiple(int $deck_id, array $card_ids): array
    {
        if ($card_ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($card_ids), '?'));

        return $this->fetchAllAs(
            "SELECT " . self::SELECT_COLS . " FROM special_cards WHERE deck_id = ? AND card_id IN ($placeholders)",
            array_merge([$deck_id], $card_ids),
            SpecialCard::class
        );
    }

    /**
     * @return list<SpecialCard>
     */
    public function retrieveAll(?int $deck_id = null): array
    {
        if ($deck_id !== null) {
            return $this->fetchAllAs(
                "SELECT " . self::SELECT_COLS . " FROM special_cards WHERE deck_id = :deck_id ORDER BY card_id",
                [':deck_id' => $deck_id],
                SpecialCard::class
            );
        }

        return $this->fetchAllAs(
            "SELECT " . self::SELECT_COLS . " FROM special_cards ORDER BY deck_id, card_id",
            [],
            SpecialCard::class
        );
    }

    /**
     * Null when the deck already has a special card with that card_id (the
     * table's primary key), which callers report rather than crash on.
     *
     * @param array<string,mixed> $data
     */
    public function store(array $data): ?SpecialCard
    {
        $deck_id = (int) ($data['deck_id'] ?? 0);
        $card_id = (int) ($data['card_id'] ?? 0);

        $stmt = $this->db->prepare(
            "INSERT INTO special_cards
                (deck_id, card_id, name, keywords, meaning, advice, keywords_reversed, meaning_reversed, advice_reversed)
             VALUES
                (:deck_id, :card_id, :name, :keywords, :meaning, :advice, :keywords_reversed, :meaning_reversed, :advice_reversed)"
        );
        try {
            $stmt->execute([
                ':deck_id'           => $deck_id,
                ':card_id'           => $card_id,
                ':name'              => $data['name'] ?? '',
                ':keywords'          => $data['keywords'] ?? '',
                ':meaning'           => $data['meaning'] ?? '',
                ':advice'            => $data['advice'] ?? '',
                ':keywords_reversed' => $data['keywords_reversed'] ?? '',
                ':meaning_reversed'  => $data['meaning_reversed'] ?? '',
                ':advice_reversed'   => $data['advice_reversed'] ?? '',
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed: special_cards.deck_id, special_cards.card_id')) {
                return null;
            }
            throw $e;
        }

        return $this->retrieve($deck_id, $card_id);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $deck_id, int $card_id, array $data): ?SpecialCard
    {
        $allowed = [
            'name', 'keywords', 'meaning', 'advice',
            'keywords_reversed', 'meaning_reversed', 'advice_reversed',
        ];

        if (!$this->applyUpdate('special_cards', $data, $allowed, ['deck_id' => $deck_id, 'card_id' => $card_id])) {
            return null;
        }

        return $this->retrieve($deck_id, $card_id);
    }

    public function delete(int $deck_id, int $card_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM special_cards WHERE deck_id = :deck_id AND card_id = :card_id");

        return $stmt->execute([':deck_id' => $deck_id, ':card_id' => $card_id]);
    }
}
