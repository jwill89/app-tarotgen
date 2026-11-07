<?php

namespace Tarot\Repository;

use Tarot\Data\DrawKeyData;
use Tarot\Structure\DrawKeyCode;

readonly class DrawKeyRepository
{
    public function __construct(private DrawKeyData $data) {}

    public function storeCode(string $codeHash, string $codeChallenge, string $codeExpiresAt): void
    {
        $this->data->storeCode($codeHash, $codeChallenge, $codeExpiresAt);
    }

    public function findRedeemableCode(string $codeHash): ?DrawKeyCode
    {
        return $this->data->findRedeemableCode($codeHash);
    }

    public function issueKey(int $id, string $keyHash): bool
    {
        return $this->data->issueKey($id, $keyHash);
    }

    public function findActiveKeyId(string $keyHash): ?int
    {
        return $this->data->findActiveKeyId($keyHash);
    }

    public function touchLastUsed(int $id): void
    {
        $this->data->touchLastUsed($id);
    }

    /** Delete codes that expired unredeemed (see {@see DrawKeyData::purgeUnredeemedCodes()}). */
    public function purgeUnredeemedCodes(bool $dryRun = false): int
    {
        return $this->data->purgeUnredeemedCodes($dryRun);
    }
}
