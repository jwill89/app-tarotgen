<?php

namespace Tarot\Repository;

use Tarot\Data\ReadingData;
use Tarot\Data\ReadingFilter;
use Tarot\Structure\Reading;
use Tarot\Structure\ReadingSpread;
use Tarot\Structure\ReadingSummary;
use Tarot\Structure\ReadingTag;

readonly class ReadingRepository
{
    public function __construct(private ReadingData $data) {}

    public function get(string $reading_id): ?Reading
    {
        return $this->data->retrieve($reading_id);
    }

    /** @return Reading[] */
    public function listByUser(int $userId, ?int $limit = null, int $offset = 0, ReadingFilter $filter = new ReadingFilter()): array
    {
        return $this->data->listByUser($userId, $limit, $offset, $filter);
    }

    public function countByUser(int $userId, ReadingFilter $filter = new ReadingFilter()): int
    {
        return $this->data->countByUser($userId, $filter);
    }

    /**
     * The user's tags with how many readings carry each, alphabetically.
     *
     * @return list<ReadingTag>
     */
    public function tagCounts(int $userId): array
    {
        return $this->data->tagCounts($userId);
    }

    /**
     * The spreads the user's readings were laid out in, by name, with how many
     * readings each, alphabetically.
     *
     * @return list<ReadingSpread>
     */
    public function spreadCounts(int $userId): array
    {
        return $this->data->spreadCounts($userId);
    }

    public function save(Reading $reading, ?string $passwordHash = null): ?Reading
    {
        return $this->data->store($reading, $passwordHash);
    }

    public function verifyPassword(string $reading_id, string $password): bool
    {
        return $this->data->verifyPassword($reading_id, $password);
    }

    /**
     * @param array<string,mixed> $fields
     */
    public function updateMeta(string $reading_id, int $userId, array $fields): ?Reading
    {
        return $this->data->updateMeta($reading_id, $userId, $fields);
    }

    public function updateReadingInfo(string $reading_id, string $readingInfo, ?int $userId = null): ?Reading
    {
        return $this->data->updateReadingInfo($reading_id, $readingInfo, $userId);
    }

    /** Mark a reading as final (one-way lock), scoped to its owner. */
    public function markFinal(string $reading_id, int $userId): ?Reading
    {
        return $this->data->markFinal($reading_id, $userId);
    }

    public function deleteOwned(string $reading_id, int $userId): bool
    {
        return $this->data->deleteForOwner($reading_id, $userId);
    }

    /** Admin: delete any reading. */
    public function delete(string $reading_id): bool
    {
        return $this->data->delete($reading_id);
    }

    /**
     * Admin: paginated list of all readings.
     *
     * @return array{rows: list<ReadingSummary>, total: int}
     */
    public function listAll(int $limit = 50, int $offset = 0): array
    {
        return $this->data->listAll($limit, $offset);
    }

    /**
     * Admin: delete guest readings older than $days days.
     *
     * @return int Number of rows deleted.
     */
    public function cleanGuest(int $days): int
    {
        return $this->data->cleanGuestOlderThan($days);
    }

    /**
     * The nightly retention run: guest readings past their chosen expiry, then
     * those older than $days. A dry run rolls back what it deleted.
     *
     * @return array{expired: int, aged: int}
     */
    public function purgeGuests(int $days, bool $dryRun = false): array
    {
        return $this->data->purgeGuests($days, $dryRun);
    }
}
