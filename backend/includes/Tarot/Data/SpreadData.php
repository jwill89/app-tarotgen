<?php

namespace Tarot\Data;

use JsonException;
use Tarot\Structure\Spread;

class SpreadData extends AbstractData
{
    use NormalizesPositions;

    /**
     * Exactly the properties Spread declares (AUDIT §2.4), its category's name and
     * place among them, so a rename or reorder reaches every spread at once. The
     * foreign key keeps every spread in a category, so the join drops none.
     */
    private const string SELECT = 'SELECT s.spread_id, s.name, s.description, s.card_count, s.category_id, s.positions,
            c.name AS category_name, c.sort_order AS category_sort_order
        FROM spreads s
        JOIN spread_categories c ON c.category_id = s.category_id';

    /**
     * @return list<Spread>
     */
    public function retrieve(?int $spread_id = null): array
    {
        $where = $spread_id !== null ? ' WHERE s.spread_id = :spread_id' : '';

        return $this->fetchAllAs(
            self::SELECT . $where . ' ORDER BY s.name',
            $spread_id !== null ? [':spread_id' => $spread_id] : [],
            Spread::class
        );
    }

    /**
     * A spread named into no category goes into the first one in the list
     * (General, as seeded): every public spread is in exactly one.
     *
     * @param array<string,mixed> $data
     * @throws JsonException
     */
    public function store(array $data): ?Spread
    {
        $positions = $this->normalizePositions($data['positions'] ?? []);

        $sql = "INSERT INTO spreads (name, description, card_count, positions, category_id)
                VALUES (:name, :description, :card_count, :positions, COALESCE(:category_id, (
                    SELECT category_id FROM spread_categories ORDER BY sort_order, category_id LIMIT 1
                )))";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name'        => $data['name'] ?? '',
            ':description' => $data['description'] ?? '',
            ':card_count'  => (int) ($data['card_count'] ?? count($positions)),
            ':positions'   => json_encode($positions, JSON_THROW_ON_ERROR),
            ':category_id' => self::categoryId($data),
        ]);

        $id = (int) $this->db->lastInsertId();
        $result = $this->retrieve($id);
        return $result[0] ?? null;
    }

    /**
     * @param array<string,mixed> $data
     * @throws JsonException
     */
    public function update(int $spread_id, array $data): ?Spread
    {
        $fields = [];
        $params = [':spread_id' => $spread_id];

        if (array_key_exists('name', $data)) {
            $fields[] = 'name = :name';
            $params[':name'] = (string) $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = :description';
            $params[':description'] = (string) $data['description'];
        }

        if (array_key_exists('positions', $data)) {
            $positions = $this->normalizePositions($data['positions']);
            $fields[] = 'positions = :positions';
            $params[':positions'] = json_encode($positions, JSON_THROW_ON_ERROR);

            // Keep card_count in sync with positions unless explicitly provided.
            if (!array_key_exists('card_count', $data)) {
                $fields[] = 'card_count = :card_count';
                $params[':card_count'] = count($positions);
            }
        }

        if (array_key_exists('card_count', $data)) {
            $fields[] = 'card_count = :card_count';
            $params[':card_count'] = (int) $data['card_count'];
        }

        $categoryId = self::categoryId($data);
        if ($categoryId !== null) {
            $fields[] = 'category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }

        if ($fields === []) {
            return null;
        }

        $sql = "UPDATE spreads SET " . implode(', ', $fields) . " WHERE spread_id = :spread_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $this->retrieve($spread_id);
        return $result[0] ?? null;
    }

    /**
     * The category asked for, or null for none (the caller has checked it exists).
     *
     * @param array<string,mixed> $data
     */
    private static function categoryId(array $data): ?int
    {
        $categoryId = $data['category_id'] ?? null;

        return is_numeric($categoryId) && (int) $categoryId > 0 ? (int) $categoryId : null;
    }

    public function delete(int $spread_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM spreads WHERE spread_id = :spread_id");
        return $stmt->execute([':spread_id' => $spread_id]);
    }
}
