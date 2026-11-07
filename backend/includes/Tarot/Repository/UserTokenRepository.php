<?php

namespace Tarot\Repository;

use Tarot\Data\UserTokenData;
use Tarot\Structure\UserToken;

readonly class UserTokenRepository
{
    public function __construct(private UserTokenData $data) {}

    public function store(int $userId, string $type, string $tokenHash, string $expiresAt): bool
    {
        return $this->data->store($userId, $type, $tokenHash, $expiresAt);
    }

    public function findValid(string $tokenHash, string $type): ?UserToken
    {
        return $this->data->findValid($tokenHash, $type);
    }

    public function markUsed(int $tokenId): bool
    {
        return $this->data->markUsed($tokenId);
    }

    public function deleteForUserType(int $userId, string $type): bool
    {
        return $this->data->deleteForUserType($userId, $type);
    }
}
