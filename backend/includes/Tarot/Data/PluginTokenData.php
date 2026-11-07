<?php

namespace Tarot\Data;

use Tarot\Structure\PluginToken;
use Tarot\Utility\Clock;

/**
 * Storage for plugin personal access tokens: long-lived, revocable, multi-use.
 * Only the SHA-256 hash of a token is stored; the raw value is returned to the
 * plugin exactly once at issue time.
 */
class PluginTokenData extends AbstractData
{
    /** Exactly the properties PluginToken declares: never token_hash. */
    private const string SELECT_COLS = 'id, user_id, label, scope, created_at, last_used_at, expires_at, revoked_at';

    public function store(int $userId, string $tokenHash, string $label, string $scope, ?string $expiresAt): int
    {
        $sql = 'INSERT INTO plugin_tokens (user_id, token_hash, label, scope, expires_at, created_at)
                VALUES (:uid, :hash, :label, :scope, :expires, :now)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':uid'     => $userId,
            ':hash'    => $tokenHash,
            ':label'   => $label,
            ':scope'   => $scope,
            ':expires' => $expiresAt,
            ':now'     => Clock::now(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The active token matching the hash, or null. "Active" = not revoked and
     * not past its (optional) expiry.
     */
    public function findActive(string $tokenHash): ?PluginToken
    {
        return $this->fetchOne(
            'SELECT ' . self::SELECT_COLS . ' FROM plugin_tokens
             WHERE token_hash = :hash AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > :now)
             LIMIT 1',
            [':hash' => $tokenHash, ':now' => Clock::now()],
            PluginToken::class
        );
    }

    public function touchLastUsed(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE plugin_tokens SET last_used_at = :now WHERE id = :id');
        $stmt->execute([':now' => Clock::now(), ':id' => $id]);
    }

    /**
     * A user's tokens, newest first (for the "Connected Apps" listing).
     *
     * @return list<PluginToken>
     */
    public function listForUser(int $userId): array
    {
        return $this->fetchAllAs(
            'SELECT ' . self::SELECT_COLS . ' FROM plugin_tokens WHERE user_id = :uid ORDER BY created_at DESC, id DESC',
            [':uid' => $userId],
            PluginToken::class
        );
    }

    /** Revoke one of a user's tokens. True only when a live token was actually revoked. */
    public function revoke(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE plugin_tokens SET revoked_at = :now
             WHERE id = :id AND user_id = :uid AND revoked_at IS NULL'
        );
        $stmt->execute([':now' => Clock::now(), ':id' => $id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
