<?php

namespace Tarot\Data;

use PDOException;
use Tarot\Exception\NameTakenException;
use Tarot\Structure\SpreadCategory;

/**
 * The admin-kept list of headings the public spread library is grouped under.
 * A public spread's category_id references it (no ON DELETE action), so the
 * database itself refuses to delete a category that still holds spreads.
 */
class SpreadCategoryData extends AbstractData
{
    /** Exactly the properties SpreadCategory declares; spread_count is derived. */
    private const string SELECT = 'SELECT c.category_id, c.name, c.sort_order,
            (SELECT COUNT(*) FROM spreads s WHERE s.category_id = c.category_id) AS spread_count
        FROM spread_categories c';

    /**
     * @return list<SpreadCategory>
     */
    public function retrieve(?int $categoryId = null): array
    {
        $where = $categoryId !== null ? ' WHERE c.category_id = :category_id' : '';

        return $this->fetchAllAs(
            self::SELECT . $where . ' ORDER BY c.sort_order, c.category_id',
            $categoryId !== null ? [':category_id' => $categoryId] : [],
            SpreadCategory::class
        );
    }

    /**
     * Add a category at the end of the list.
     *
     * @throws NameTakenException When another category has that name (case-insensitively).
     */
    public function store(string $name): ?SpreadCategory
    {
        try {
            $this->db->prepare(
                'INSERT INTO spread_categories (name, sort_order)
                 VALUES (:name, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM spread_categories))'
            )->execute([':name' => $name]);
        } catch (PDOException $e) {
            throw self::nameTakenOr($e);
        }

        return $this->retrieve((int) $this->db->lastInsertId())[0] ?? null;
    }

    /**
     * Null when there is no such category.
     *
     * @throws NameTakenException When another category has that name (case-insensitively).
     */
    public function rename(int $categoryId, string $name): ?SpreadCategory
    {
        try {
            $this->db->prepare('UPDATE spread_categories SET name = :name WHERE category_id = :category_id')
                ->execute([':name' => $name, ':category_id' => $categoryId]);
        } catch (PDOException $e) {
            throw self::nameTakenOr($e);
        }

        return $this->retrieve($categoryId)[0] ?? null;
    }

    /**
     * Number the categories 1…n in the order given. The caller passes every
     * category id exactly once; all of it is written, or none.
     *
     * @param list<int> $categoryIds
     */
    public function reorder(array $categoryIds): void
    {
        $stmt = $this->db->prepare('UPDATE spread_categories SET sort_order = :sort_order WHERE category_id = :category_id');

        $this->db->beginTransaction();
        foreach ($categoryIds as $index => $categoryId) {
            $stmt->execute([':sort_order' => $index + 1, ':category_id' => $categoryId]);
        }
        $this->db->commit();
    }

    /**
     * True only when a row was removed. A category that still holds spreads
     * fails the spreads foreign key; the caller checks spread_count first.
     */
    public function delete(int $categoryId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM spread_categories WHERE category_id = :category_id');
        $stmt->execute([':category_id' => $categoryId]);

        return $stmt->rowCount() > 0;
    }

    /** spread_categories.name is UNIQUE COLLATE NOCASE: the one constraint error callers report. */
    private static function nameTakenOr(PDOException $e): PDOException|NameTakenException
    {
        return str_contains($e->getMessage(), 'UNIQUE constraint failed: spread_categories.name')
            ? new NameTakenException('A spread category with that name already exists.', 0, $e)
            : $e;
    }
}
