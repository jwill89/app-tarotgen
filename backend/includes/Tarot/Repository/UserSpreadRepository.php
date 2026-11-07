<?php

namespace Tarot\Repository;

use Tarot\Data\UserSpreadData;
use Tarot\Structure\UserSpread;

readonly class UserSpreadRepository
{
    public function __construct(private UserSpreadData $data) {}

    /**
     * List all of a user's personal spreads.
     *
     * @return list<UserSpread>
     */
    public function listByUser(int $userId): array
    {
        return $this->data->retrieve($userId);
    }

    /**
     * One of a user's spreads, or null when it isn't theirs. There is no
     * unscoped lookup: a personal spread is only ever its owner's to use.
     */
    public function get(int $userId, int $userSpreadId): ?UserSpread
    {
        $results = $this->data->retrieve($userId, $userSpreadId);
        return $results[0] ?? null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(int $userId, array $data): ?UserSpread
    {
        return $this->data->store($userId, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $userId, int $userSpreadId, array $data): ?UserSpread
    {
        return $this->data->update($userId, $userSpreadId, $data);
    }

    public function delete(int $userId, int $userSpreadId): bool
    {
        return $this->data->delete($userId, $userSpreadId);
    }
}
