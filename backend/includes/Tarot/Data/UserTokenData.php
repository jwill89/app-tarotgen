<?php

namespace Tarot\Data;

use Tarot\Structure\UserToken;
use Tarot\Utility\Clock;

/**
 * Single-use, hashed tokens for email activation and password resets.
 * Only the SHA-256 hash of a token is stored; the raw token lives only in the
 * email link.
 */
class UserTokenData extends AbstractData
{
    public function store(int $userId, string $type, string $tokenHash, string $expiresAt): bool
    {
        $sql = 'INSERT INTO user_tokens (user_id, type, token_hash, expires_at, created_at)
                VALUES (:uid, :type, :hash, :expires, :now)';

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':uid'     => $userId,
            ':type'    => $type,
            ':hash'    => $tokenHash,
            ':expires' => $expiresAt,
            ':now'     => Clock::now(),
        ]);
    }

    /**
     * Return an unused, unexpired token matching the hash + type, or null.
     */
    public function findValid(string $tokenHash, string $type): ?UserToken
    {
        return $this->fetchOne(
            'SELECT token_id, user_id FROM user_tokens
             WHERE token_hash = :hash AND type = :type
               AND used_at IS NULL AND expires_at > :now
             LIMIT 1',
            [':hash' => $tokenHash, ':type' => $type, ':now' => Clock::now()],
            UserToken::class
        );
    }

    public function markUsed(int $tokenId): bool
    {
        $stmt = $this->db->prepare('UPDATE user_tokens SET used_at = :now WHERE token_id = :id');
        return $stmt->execute([':now' => Clock::now(), ':id' => $tokenId]);
    }

    /** Invalidate any outstanding tokens of a type for a user (e.g. before issuing a new one). */
    public function deleteForUserType(int $userId, string $type): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_tokens WHERE user_id = :uid AND type = :type');
        return $stmt->execute([':uid' => $userId, ':type' => $type]);
    }
}
