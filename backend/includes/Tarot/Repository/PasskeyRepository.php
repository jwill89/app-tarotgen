<?php

namespace Tarot\Repository;

use Tarot\Data\PasskeyData;
use Tarot\Structure\Passkey;
use Tarot\Structure\PasskeyCredential;

readonly class PasskeyRepository
{
    public function __construct(private PasskeyData $data) {}

    /** @return list<Passkey> */
    public function getByUser(int $userId): array
    {
        return $this->data->getByUser($userId);
    }

    public function findByCredentialId(string $credentialId): ?PasskeyCredential
    {
        return $this->data->findByCredentialId($credentialId);
    }

    /** @return string[] */
    public function getCredentialIds(int $userId): array
    {
        return $this->data->getCredentialIds($userId);
    }

    public function create(int $userId, string $credentialId, string $publicKeyPem, string $name, int $signCount): int
    {
        return $this->data->create($userId, $credentialId, $publicKeyPem, $name, $signCount);
    }

    public function updateSignCount(int $passkeyId, int $signCount): void
    {
        $this->data->updateSignCount($passkeyId, $signCount);
    }

    public function rename(int $passkeyId, int $userId, string $name): bool
    {
        return $this->data->rename($passkeyId, $userId, $name);
    }

    public function delete(int $passkeyId, int $userId): bool
    {
        return $this->data->delete($passkeyId, $userId);
    }

    public function countByUser(int $userId): int
    {
        return $this->data->countByUser($userId);
    }
}
