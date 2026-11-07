<?php

namespace Tarot\Repository;

use DateTimeImmutable;
use Tarot\Data\DailyCardData;
use Tarot\Structure\DailyCard;

readonly class DailyCardRepository
{
    public function __construct(private DailyCardData $data) {}

    /** The user's card for $day ("Y-m-d"), if they drew one — its reading null if deleted since. */
    public function forDay(int $userId, string $day): ?DailyCard
    {
        return $this->data->retrieve($userId, $day);
    }

    /**
     * The user's cards for $month ("Y-m", already validated), by day.
     *
     * @return list<DailyCard>
     */
    public function forMonth(int $userId, string $month): array
    {
        $first = new DateTimeImmutable("{$month}-01");

        return $this->data->listBetween($userId, $first->format('Y-m-d'), $first->modify('first day of next month')->format('Y-m-d'));
    }

    /** Make $readingId the user's card for $day; false when the day already has one. */
    public function link(int $userId, string $day, string $readingId): bool
    {
        return $this->data->link($userId, $day, $readingId);
    }
}
