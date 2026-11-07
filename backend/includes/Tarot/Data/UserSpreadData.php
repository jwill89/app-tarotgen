<?php

namespace Tarot\Data;

use JsonException;
use Tarot\Structure\UserSpread;

class UserSpreadData extends AbstractData
{
    use NormalizesPositions;

    /** Exactly the properties UserSpread declares (AUDIT §2.4). */
    private const string SELECT_COLS = 'user_spread_id, user_id, name, description, card_count, positions, '
        . 'created_at, updated_at';

    /**
     * Retrieve all spreads for a user, or a single one by ID (scoped to user).
     *
     * @return list<UserSpread>
     */
    public function retrieve(int $userId, ?int $userSpreadId = null): array
    {
        $query = "SELECT " . self::SELECT_COLS . " FROM user_spreads WHERE user_id = :user_id";
        $params = [':user_id' => $userId];

        if ($userSpreadId !== null) {
            $query .= " AND user_spread_id = :user_spread_id";
            $params[':user_spread_id'] = $userSpreadId;
        }

        return $this->fetchAllAs($query . ' ORDER BY created_at DESC', $params, UserSpread::class);
    }

    /**
     * A personal spread's card_count is always its number of positions: a reading
     * from it draws card_count cards, so a client's own count isn't taken.
     *
     * @param array<string,mixed> $data
     * @throws JsonException
     */
    public function store(int $userId, array $data): ?UserSpread
    {
        $positions = $this->normalizePositions($data['positions'] ?? []);

        $sql = "INSERT INTO user_spreads (user_id, name, description, card_count, positions)
                VALUES (:user_id, :name, :description, :card_count, :positions)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'     => $userId,
            ':name'        => mb_substr(trim((string) ($data['name'] ?? '')), 0, 200),
            ':description' => (string) ($data['description'] ?? ''),
            ':card_count'  => count($positions),
            ':positions'   => json_encode($positions, JSON_THROW_ON_ERROR),
        ]);

        $id = (int) $this->db->lastInsertId();
        $result = $this->retrieve($userId, $id);
        return $result[0] ?? null;
    }

    /**
     * New positions bring their count along as card_count (see store()).
     *
     * @param array<string,mixed> $data
     * @throws JsonException
     */
    public function update(int $userId, int $userSpreadId, array $data): ?UserSpread
    {
        $fields = [];
        $params = [':user_spread_id' => $userSpreadId, ':user_id' => $userId];

        if (array_key_exists('name', $data)) {
            $fields[] = 'name = :name';
            $params[':name'] = mb_substr(trim((string) $data['name']), 0, 200);
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = :description';
            $params[':description'] = (string) $data['description'];
        }

        if (array_key_exists('positions', $data)) {
            $positions = $this->normalizePositions($data['positions']);
            $fields[] = 'positions = :positions';
            $params[':positions'] = json_encode($positions, JSON_THROW_ON_ERROR);
            $fields[] = 'card_count = :card_count';
            $params[':card_count'] = count($positions);
        }

        if ($fields === []) {
            return null;
        }

        $fields[] = "updated_at = CURRENT_TIMESTAMP";

        $sql = "UPDATE user_spreads SET " . implode(', ', $fields)
             . " WHERE user_spread_id = :user_spread_id AND user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $this->retrieve($userId, $userSpreadId);
        return $result[0] ?? null;
    }

    /**
     * Delete one of a user's spreads. True only when a row was removed: the
     * caller then drops every favourite of it, so a false "deleted" for someone
     * else's spread would wipe its owner's favourites.
     */
    public function delete(int $userId, int $userSpreadId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM user_spreads WHERE user_spread_id = :user_spread_id AND user_id = :user_id"
        );
        $stmt->execute([':user_spread_id' => $userSpreadId, ':user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
