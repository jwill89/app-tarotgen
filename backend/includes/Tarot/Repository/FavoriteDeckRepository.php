<?php

namespace Tarot\Repository;

use Tarot\Data\FavoriteDeckData;

readonly class FavoriteDeckRepository
{
    public function __construct(private FavoriteDeckData $data) {}

    /** @return int[] */
    public function listByUser(int $userId): array
    {
        return $this->data->listByUser($userId);
    }

    public function add(int $userId, int $deckId): bool
    {
        return $this->data->add($userId, $deckId);
    }

    public function remove(int $userId, int $deckId): bool
    {
        return $this->data->remove($userId, $deckId);
    }
}
