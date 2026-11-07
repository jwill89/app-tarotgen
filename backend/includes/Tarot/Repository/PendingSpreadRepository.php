<?php

namespace Tarot\Repository;

use PDO;
use Throwable;
use Tarot\Data\PendingSpreadData;
use Tarot\Structure\PendingSpread;
use Tarot\Structure\Spread;

readonly class PendingSpreadRepository
{
    public function __construct(
        private PendingSpreadData $data,
        private SpreadRepository $spreads,
        private PDO $db,
    ) {}

    /**
     * @return list<PendingSpread>|PendingSpread
     */
    public function get(?int $pending_id = null): array|PendingSpread
    {
        $results = $this->data->retrieve($pending_id);

        if ($pending_id !== null && count($results) > 0) {
            return $results[0];
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data, ?int $userId = null): ?PendingSpread
    {
        return $this->data->store($data, $userId);
    }

    /** Reject a submission: delete it from the queue. */
    public function reject(int $pending_id): bool
    {
        return $this->data->delete($pending_id);
    }

    /**
     * Approve a submission: copy it into the official `spreads` table (which
     * assigns a real spread_id) and remove it from the queue. The copy + delete
     * run in a single transaction so a failure never leaves a half-applied state.
     *
     * @param ?int $categoryId The library category to list it under; null for the
     *                         first category (the caller has checked it exists).
     */
    public function approve(int $pending_id, ?int $categoryId = null): ?Spread
    {
        $results = $this->data->retrieve($pending_id);
        $pending = $results[0] ?? null;

        if ($pending === null) {
            return null;
        }

        try {
            $this->db->beginTransaction();

            $spread = $this->spreads->create([
                'name'        => $pending->name,
                'description' => $pending->description,
                'card_count'  => $pending->card_count,
                'positions'   => $pending->positions,
                'category_id' => $categoryId,
            ]);

            if ($spread === null) {
                $this->db->rollBack();
                return null;
            }

            $this->data->delete($pending_id);
            $this->db->commit();

            return $spread;
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return null;
        }
    }
}
