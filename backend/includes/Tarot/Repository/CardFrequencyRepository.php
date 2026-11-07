<?php

namespace Tarot\Repository;

use Tarot\Data\CardFrequencyData;
use Tarot\Structure\CardFrequency;
use Tarot\Utility\Clock;

readonly class CardFrequencyRepository
{
    public function __construct(private CardFrequencyData $data) {}

    /**
     * How often each card turned up in the user's own readings: within one deck
     * (null for every deck) and the last $days days (null for all time).
     */
    public function forUser(int $userId, ?int $deckId = null, ?int $days = null): CardFrequency
    {
        $since = $days === null ? null : Clock::in(-$days * 86400);

        return new CardFrequency([
            'readings' => $this->data->countReadings($userId, $deckId, $since),
            'deck_id'  => $deckId,
            'days'     => $days,
            'cards'    => $this->data->cardCounts($userId, $deckId, $since),
        ]);
    }
}
