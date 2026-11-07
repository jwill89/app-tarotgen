<?php

namespace Tarot\Repository;

use Tarot\Data\DeckSystemData;
use Tarot\Structure\DeckSystem;
use Tarot\Structure\DeckSystemCard;

readonly class DeckSystemRepository
{
    public function __construct(private DeckSystemData $data) {}

    /**
     * @return list<DeckSystem>|DeckSystem
     */
    public function get(?int $id = null): array|DeckSystem
    {
        $results = $this->data->retrieve($id);

        if ($id !== null && count($results) > 0) {
            return $results[0];
        }

        if ($id !== null) {
            return $results; // empty array
        }

        return $results;
    }

    /**
     * @return list<DeckSystem>
     */
    public function getApproved(): array
    {
        return $this->data->retrieveApproved();
    }

    /**
     * @return list<DeckSystem>
     */
    public function getPending(): array
    {
        return $this->data->retrievePending();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): ?DeckSystem
    {
        return $this->data->store($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): ?DeckSystem
    {
        return $this->data->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->data->delete($id);
    }

    // ── Cards ────────────────────────────────────────────────────

    /**
     * @return list<DeckSystemCard>
     */
    public function getCards(int $systemId): array
    {
        return $this->data->retrieveCards($systemId);
    }

    /**
     * @param list<int> $cardIds
     * @return list<DeckSystemCard>
     */
    public function getCardsByIds(int $systemId, array $cardIds): array
    {
        return $this->data->retrieveCardsByIds($systemId, $cardIds);
    }

    /**
     * @param list<array<string,mixed>> $cards
     */
    public function saveCards(int $systemId, array $cards): void
    {
        $this->data->storeCards($systemId, $cards);
    }

    public function deleteCards(int $systemId): bool
    {
        return $this->data->deleteCards($systemId);
    }
}
