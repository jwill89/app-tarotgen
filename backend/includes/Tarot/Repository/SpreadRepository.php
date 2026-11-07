<?php

namespace Tarot\Repository;

use Tarot\Data\SpreadData;
use Tarot\Structure\Spread;

readonly class SpreadRepository
{
    public function __construct(private SpreadData $data) {}

    /**
     * @return list<Spread>|Spread
     */
    public function get(?int $spread_id = null): array|Spread
    {
        $results = $this->data->retrieve($spread_id);

        if ($spread_id !== null && count($results) > 0) {
            return $results[0];
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): ?Spread
    {
        return $this->data->store($data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $spread_id, array $data): ?Spread
    {
        return $this->data->update($spread_id, $data);
    }

    public function delete(int $spread_id): bool
    {
        return $this->data->delete($spread_id);
    }
}
