<?php

namespace Tarot\Repository;

use Tarot\Data\SpreadCategoryData;
use Tarot\Exception\NameTakenException;
use Tarot\Structure\SpreadCategory;

readonly class SpreadCategoryRepository
{
    public function __construct(private SpreadCategoryData $data) {}

    /**
     * Every category, in the list's order.
     *
     * @return list<SpreadCategory>
     */
    public function all(): array
    {
        return $this->data->retrieve();
    }

    public function find(int $categoryId): ?SpreadCategory
    {
        return $this->data->retrieve($categoryId)[0] ?? null;
    }

    /**
     * @throws NameTakenException
     */
    public function create(string $name): ?SpreadCategory
    {
        return $this->data->store($name);
    }

    /**
     * @throws NameTakenException
     */
    public function rename(int $categoryId, string $name): ?SpreadCategory
    {
        return $this->data->rename($categoryId, $name);
    }

    /**
     * @param list<int> $categoryIds Every category id, once, in the new order.
     */
    public function reorder(array $categoryIds): void
    {
        $this->data->reorder($categoryIds);
    }

    public function delete(int $categoryId): bool
    {
        return $this->data->delete($categoryId);
    }
}
