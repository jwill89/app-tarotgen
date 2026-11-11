<?php

namespace Tarot\Data;

use JsonException;
use Tarot\Structure\PendingSpread;

class PendingSpreadData extends AbstractData
{
    use NormalizesPositions;

    /**
     * @return list<PendingSpread>
     */
    public function retrieve(?int $pending_id = null): array
    {
        // Resolve the submitter label from the linked account's current display
        // name; fall back to the free-text `submitter` column, then to "Guest".
        $query = "SELECT p.pending_id, p.name, p.description, p.card_count, p.positions,
                         p.submitted_at, p.user_id,
                         COALESCE(u.display_name, NULLIF(p.submitter, ''), 'Guest') AS submitter
                  FROM pending_spreads p
                  LEFT JOIN users u ON u.user_id = p.user_id";

        if ($pending_id !== null) {
            $query .= " WHERE p.pending_id = :pending_id";
        }

        // Oldest submissions first so the admin works the queue in order.
        $query .= " ORDER BY p.submitted_at ASC, p.pending_id ASC";

        return $this->fetchAllAs(
            $query,
            $pending_id !== null ? [':pending_id' => $pending_id] : [],
            PendingSpread::class
        );
    }

    /**
     * @throws JsonException
     */
    /**
     * @param array<string,mixed> $data
     */
    public function store(array $data, ?int $userId = null): ?PendingSpread
    {
        $positions = $this->normalizePositions($data['positions'] ?? []);

        // The submitter is the linked account (or "Guest"), not free text, so the
        // `submitter` column is left at its default ''.
        $sql = "INSERT INTO pending_spreads (name, description, card_count, positions, user_id)
                VALUES (:name, :description, :card_count, :positions, :user_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name'        => mb_substr(trim((string) ($data['name'] ?? '')), 0, 200),
            ':description' => (string) ($data['description'] ?? ''),
            ':card_count'  => (int) ($data['card_count'] ?? count($positions)),
            ':positions'   => json_encode($positions, JSON_THROW_ON_ERROR),
            ':user_id'     => $userId,
        ]);

        $id = (int) $this->db->lastInsertId();
        $result = $this->retrieve($id);
        return $result[0] ?? null;
    }

    public function delete(int $pending_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM pending_spreads WHERE pending_id = :pending_id");
        return $stmt->execute([':pending_id' => $pending_id]);
    }
}
