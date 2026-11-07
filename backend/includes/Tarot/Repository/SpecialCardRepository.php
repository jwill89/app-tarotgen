<?php

namespace Tarot\Repository;

use Tarot\Data\SpecialCardData;
use Tarot\Structure\SpecialCard;

readonly class SpecialCardRepository
{
    public function __construct(private SpecialCardData $data) {}

    /**
     * @param list<int> $card_ids
     * @return list<SpecialCard>
     */
    public function getMultiple(int $deck_id, array $card_ids): array
    {
        return $this->data->retrieveMultiple($deck_id, $card_ids);
    }

    /**
     * @return list<SpecialCard>
     */
    public function getAll(?int $deck_id = null): array
    {
        return $this->data->retrieveAll($deck_id);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): ?SpecialCard
    {
        return $this->data->store($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $deck_id, int $card_id, array $data): ?SpecialCard
    {
        return $this->data->update($deck_id, $card_id, $data);
    }

    public function delete(int $deck_id, int $card_id): bool
    {
        return $this->data->delete($deck_id, $card_id);
    }
}
