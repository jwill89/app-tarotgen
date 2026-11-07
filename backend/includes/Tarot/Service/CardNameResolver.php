<?php

namespace Tarot\Service;

use Tarot\Repository\DeckSystemRepository;
use Tarot\Repository\SpecialCardRepository;
use Tarot\Structure\Deck;

/**
 * Resolves card display names for a given deck.
 *
 * Uses the deck system's cards table for standard cards. Cards beyond the
 * system's total_cards count (extras/additional) come from the special_cards
 * table. Resolution is batched to avoid N+1 lookups.
 */
readonly class CardNameResolver
{
    public function __construct(
        private DeckSystemRepository $deckSystems,
        private SpecialCardRepository $specialCards,
    ) {}

    /**
     * Map a list of card IDs to their display names for $deck.
     *
     * @param  int[] $card_ids
     * @return array<int,string> card_id => name (only ids that were found)
     */
    public function resolve(Deck $deck, array $card_ids): array
    {
        $deck_id   = $deck->deck_id;
        $systemId  = $deck->deck_system_id;
        $systemTotal = $deck->getEffectiveTotalCards();

        $standard_ids = [];
        $special_ids  = [];
        foreach ($card_ids as $card_id) {
            if ($card_id > $systemTotal) {
                $special_ids[] = $card_id;
            } else {
                $standard_ids[] = $card_id;
            }
        }

        $names = [];

        if ($standard_ids !== []) {
            foreach ($this->deckSystems->getCardsByIds($systemId, array_values(array_unique($standard_ids))) as $card) {
                $names[$card->card_id] = $card->name;
            }
        }

        if ($special_ids !== []) {
            foreach ($this->specialCards->getMultiple($deck_id, array_values(array_unique($special_ids))) as $card) {
                $names[$card->card_id] = $card->name;
            }
        }

        return $names;
    }
}
