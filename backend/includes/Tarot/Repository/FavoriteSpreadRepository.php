<?php

namespace Tarot\Repository;

use Tarot\Data\FavoriteSpreadData;
use Tarot\Structure\FavoriteSpread;

readonly class FavoriteSpreadRepository
{
    public function __construct(private FavoriteSpreadData $data) {}

    /**
     * List all favorite entries for a user.
     *
     * @return list<FavoriteSpread>
     */
    public function listByUser(int $userId): array
    {
        return $this->data->listByUser($userId);
    }

    public function add(int $userId, string $spreadType, int $spreadId): bool
    {
        if (!in_array($spreadType, ['public', 'personal'], true)) {
            return false;
        }
        return $this->data->add($userId, $spreadType, $spreadId);
    }

    public function remove(int $userId, string $spreadType, int $spreadId): bool
    {
        return $this->data->remove($userId, $spreadType, $spreadId);
    }

    /**
     * Clean up favorites when a spread is deleted (called by admin/user spread deletion).
     */
    public function removeBySpread(string $spreadType, int $spreadId): bool
    {
        return $this->data->removeBySpread($spreadType, $spreadId);
    }
}
