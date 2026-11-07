<?php

namespace Tarot\Data;

use Tarot\Structure\DrawKeyCode;
use Tarot\Utility\Clock;
use Throwable;

/**
 * Storage for the plugin's draw keys (`plugin_draw_keys`): what lets an
 * unlinked plugin install draw readings. One row per install, through its life:
 * a one-time code minted once the key page's challenge is passed, then — when
 * the plugin redeems it — the key itself. Only SHA-256 hashes of the code and
 * the key are stored; each raw value is handed out exactly once.
 */
class DrawKeyData extends AbstractData
{
    public function storeCode(string $codeHash, string $codeChallenge, string $codeExpiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_draw_keys (code_hash, code_challenge, code_expires_at, created_at)
             VALUES (:hash, :challenge, :expires, :now)'
        );
        $stmt->execute([
            ':hash'      => $codeHash,
            ':challenge' => $codeChallenge,
            ':expires'   => $codeExpiresAt,
            ':now'       => Clock::now(),
        ]);
    }

    /** The code matching the hash while it can still be redeemed (not yet, and not expired), or null. */
    public function findRedeemableCode(string $codeHash): ?DrawKeyCode
    {
        return $this->fetchOne(
            'SELECT id, code_challenge FROM plugin_draw_keys
             WHERE code_hash = :hash AND key_hash IS NULL AND code_expires_at > :now
             LIMIT 1',
            [':hash' => $codeHash, ':now' => Clock::now()],
            DrawKeyCode::class
        );
    }

    /**
     * Redeem a code: store its key. False when it was redeemed in the meantime,
     * so two exchanges racing for one code can't both get a key.
     */
    public function issueKey(int $id, string $keyHash): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE plugin_draw_keys SET key_hash = :hash, issued_at = :now
             WHERE id = :id AND key_hash IS NULL'
        );
        $stmt->execute([':hash' => $keyHash, ':now' => Clock::now(), ':id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** The id of the live (issued, not revoked) key matching the hash, or null. */
    public function findActiveKeyId(string $keyHash): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM plugin_draw_keys WHERE key_hash = :hash AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute([':hash' => $keyHash]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function touchLastUsed(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE plugin_draw_keys SET last_used_at = :now WHERE id = :id');
        $stmt->execute([':now' => Clock::now(), ':id' => $id]);
    }

    /**
     * The nightly retention run (scripts/retention.php): delete the rows of codes
     * that expired without being redeemed, a key page passed but the plugin never
     * collected. Nothing can redeem them, and they would otherwise pile up. Issued
     * keys, revoked ones included, stay. A dry run deletes and rolls back, so its
     * count is what a real run would delete.
     *
     * @return int Number of rows deleted.
     */
    public function purgeUnredeemedCodes(bool $dryRun): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM plugin_draw_keys WHERE key_hash IS NULL AND code_expires_at <= :now'
            );
            $stmt->execute([':now' => Clock::now()]);
            $deleted = $stmt->rowCount();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($dryRun) {
            $this->db->rollBack();
        } else {
            $this->db->commit();
        }

        return $deleted;
    }
}
