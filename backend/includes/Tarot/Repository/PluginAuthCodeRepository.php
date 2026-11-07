<?php

namespace Tarot\Repository;

use Tarot\Data\PluginAuthCodeData;
use Tarot\Structure\PluginAuthCode;

readonly class PluginAuthCodeRepository
{
    public function __construct(private PluginAuthCodeData $data) {}

    public function store(int $userId, string $codeHash, string $codeChallenge, string $scope, string $expiresAt): bool
    {
        return $this->data->store($userId, $codeHash, $codeChallenge, $scope, $expiresAt);
    }

    public function findActive(string $codeHash): ?PluginAuthCode
    {
        return $this->data->findActive($codeHash);
    }

    public function markUsed(int $id): bool
    {
        return $this->data->markUsed($id);
    }
}
