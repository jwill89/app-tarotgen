<?php

namespace Tarot\Data;

use Tarot\Structure\CardCount;

/**
 * Card frequency over one user's readings, counted in SQL by walking each
 * reading's `draw` array with json_each. Both queries take the same filters: a
 * deck (null for every deck) and a time (null for all time; otherwise readings
 * made at or after it, "Y-m-d H:i:s" UTC like reading_time).
 *
 * A reading whose reading_info isn't valid JSON is left out rather than failing
 * the whole count: `valid` is MATERIALIZED so that check has run before any JSON
 * function sees the text.
 */
class CardFrequencyData extends AbstractData
{
    /** How many of the user's readings match the filters. */
    public function countReadings(int $userId, ?int $deckId, ?string $since): int
    {
        [$mine, $params] = $this->mine($userId, $deckId, $since);
        $stmt = $this->db->prepare("{$mine} SELECT COUNT(*) FROM mine");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every card drawn in the matching readings, most often first. Without a
     * deck a card is its name, across decks; with one, the deck's card id. A
     * card drawn without a name (an extra card its deck hadn't named) is its
     * deck's card either way.
     *
     * @return list<CardCount>
     */
    public function cardCounts(int $userId, ?int $deckId, ?string $since): array
    {
        [$mine, $params] = $this->mine($userId, $deckId, $since);

        // Across decks, named cards group by name and unnamed ones by deck + id;
        // within one deck every card groups by id, under the name its readings
        // recorded for it (an extra card may have been named since). `drawn`'s
        // columns are named apart from the output ones, so GROUP BY can't
        // mistake one for the other.
        $columns = $deckId === null
            ? "COALESCE(name, 'Card #' || cid) AS card_name,
               CASE WHEN name IS NULL THEN cid END AS card_id,
               CASE WHEN name IS NULL THEN did END AS deck_id"
            : "COALESCE(MAX(name), 'Card #' || cid) AS card_name, cid AS card_id, MAX(did) AS deck_id";
        $group = $deckId === null
            ? 'name, CASE WHEN name IS NULL THEN did END, CASE WHEN name IS NULL THEN cid END'
            : 'cid';

        return $this->fetchAllAs(
            "{$mine},
             drawn AS (
                 SELECT mine.deck_id AS did,
                        CAST(COALESCE(json_extract(card.value, '$.card_id'), 0) AS INTEGER) AS cid,
                        NULLIF(json_extract(card.value, '$.card_name'), '') AS name,
                        COALESCE(json_extract(card.value, '$.reversed'), 0) <> 0 AS rev
                 FROM mine, json_each(mine.reading_info, '$.draw') AS card
                 WHERE card.type = 'object'
             ),
             counted AS (
                 SELECT {$columns},
                        COUNT(*) AS count,
                        COUNT(*) - SUM(rev) AS upright,
                        SUM(rev) AS reversed
                 FROM drawn
                 GROUP BY {$group}
             )
             SELECT card_name, card_id, deck_id, count, upright, reversed
             FROM counted
             ORDER BY count DESC, card_name COLLATE NOCASE, deck_id, card_id",
            $params,
            CardCount::class
        );
    }

    /**
     * The CTEs both queries start from: `mine`, the user's matching readings
     * (reading_info + deck_id).
     *
     * @return array{string, array<string,int|string>}
     */
    private function mine(int $userId, ?int $deckId, ?string $since): array
    {
        $params = [':uid' => $userId];

        $window = '';
        if ($since !== null) {
            $window = ' AND reading_time >= :since';
            $params[':since'] = $since;
        }

        $deck = '';
        if ($deckId !== null) {
            $deck = ' WHERE deck_id = :deck';
            $params[':deck'] = $deckId;
        }

        return [
            "WITH valid AS MATERIALIZED (
                 SELECT reading_info FROM readings
                 WHERE user_id = :uid AND json_valid(reading_info){$window}
             ),
             mine AS (
                 SELECT reading_info, deck_id FROM (
                     SELECT reading_info,
                            CAST(COALESCE(json_extract(reading_info, '$.deck_id'), 0) AS INTEGER) AS deck_id
                     FROM valid
                 ){$deck}
             )",
            $params,
        ];
    }
}
