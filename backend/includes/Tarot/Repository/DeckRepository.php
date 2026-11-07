<?php

namespace Tarot\Repository;

use Tarot\Data\DeckData;
use Tarot\Structure\Deck;

readonly class DeckRepository
{
    public function __construct(private DeckData $data) {}

    /**
     * @return list<Deck>|Deck
     */
    public function get(?int $deck_id = null): array|Deck
    {
        $results = $this->data->retrieve($deck_id);

        if ($deck_id !== null && count($results) > 0) {
            return $results[0];
        }

        return $results;
    }

    /**
     * Every deck, regardless of approval/usable state (admin-wide operations
     * such as thumbnail generation and reading deck-name resolution).
     *
     * @return list<Deck>
     */
    public function getAll(): array
    {
        return $this->data->retrieve();
    }

    /**
     * Decks marked usable — shown in public deck selectors.
     *
     * @return list<Deck>
     */
    public function getUsable(): array
    {
        return $this->data->retrieveUsable();
    }

    /**
     * Decks that are approved (admin-approved list).
     *
     * @return list<Deck>
     */
    public function getApproved(): array
    {
        return $this->data->retrieveApproved();
    }

    /**
     * Decks that are not yet approved (pending submissions).
     *
     * @return list<Deck>
     */
    public function getPending(): array
    {
        return $this->data->retrievePending();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): ?Deck
    {
        return $this->data->store($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $deck_id, array $data): ?Deck
    {
        return $this->data->update($deck_id, $data);
    }

    public function delete(int $deck_id): bool
    {
        return $this->data->delete($deck_id);
    }
}
