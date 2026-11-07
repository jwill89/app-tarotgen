<?php

namespace Tarot\Service;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Random\RandomException;
use Tarot\Exception\ApiException;
use Tarot\Exception\DailyCardTakenException;
use Tarot\Repository\DailyCardRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Structure\DailyCard;
use Tarot\Utility\Clock;
use Tarot\Utility\Input;

/**
 * The daily card: one card a day per user, drawn as an ordinary one-card reading
 * (ReadingService) and linked to the day it was drawn for.
 *
 * Once drawn, a day stays drawn: deleting its reading leaves the day with no
 * reading rather than free, so no one can keep redrawing until a card suits.
 *
 * A day is the user's local date, which only their browser knows. The server
 * takes it on trust within a day either side of its own UTC date — the span of
 * the world's time zones, UTC−12 to UTC+14 — so no one can draw for a day long
 * past or still to come.
 */
class DailyCardService
{
    /** How far a local date may be from the UTC date: the widest UTC offset, rounded up. */
    private const int MAX_DAYS_FROM_UTC = 1;

    public function __construct(
        private readonly DailyCardRepository $dailyCards,
        private readonly ReadingService $readingService,
        private readonly ReadingRepository $readings,
    ) {}

    /**
     * The user's daily cards in a month ("Y-m").
     *
     * @return list<DailyCard>
     * @throws ApiException When the month isn't one.
     */
    public function month(int $userId, mixed $month): array
    {
        if (!is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new ApiException('month must be a month, as YYYY-MM.', 400);
        }

        return $this->dailyCards->forMonth($userId, $month);
    }

    /**
     * Draw the user's card for their day: one card from the chosen deck, with or
     * without reversals, saved as their reading "Daily card — 30 October 2026".
     *
     * @param array<string,mixed> $params `day` ("Y-m-d", local), `deck_id`, `use_reversals`.
     * @throws ApiException On a bad day or deck.
     * @throws DailyCardTakenException When the day already has its card, even one whose reading was deleted.
     * @throws RandomException
     * @throws JsonException
     */
    public function draw(int $userId, array $params): DailyCard
    {
        $date = $this->drawableDay($params['day'] ?? null);
        $day = $date->format('Y-m-d');

        $deckId = $params['deck_id'] ?? null;
        if (is_string($deckId) && ctype_digit($deckId)) {
            $deckId = (int) $deckId;
        }
        if (!is_int($deckId) || $deckId < 1) {
            throw new ApiException('Choose a deck to draw from.', 400);
        }

        $existing = $this->dailyCards->forDay($userId, $day);
        if ($existing instanceof DailyCard) {
            throw new DailyCardTakenException($existing->reading_id);
        }

        $reading = $this->readingService->generate([
            'deck_id'         => $deckId,
            'number_of_cards' => 1,
            'use_reversals'   => Input::bool($params['use_reversals'] ?? null),
            'reading_name'    => 'Daily card — ' . $date->format('j F Y'),
        ], $userId);

        if (!$this->dailyCards->link($userId, $day, $reading->reading_id)) {
            // Another draw for the same day (a double submit) linked first. Its
            // card is the day's; this reading would only be a stray in My Readings.
            $this->readings->deleteOwned($reading->reading_id, $userId);
            throw new DailyCardTakenException($this->dailyCards->forDay($userId, $day)?->reading_id);
        }

        // A daily card is one card: locked against drawing more, as the calendar
        // shows only that card. Its name, notes and sharing stay editable.
        $this->readings->markFinal($reading->reading_id, $userId);

        return $this->dailyCards->forDay($userId, $day)
            ?? throw new ApiException('Could not save your daily card.', 500);
    }

    /**
     * The local date sent, if it's a real date no more than a day from the
     * server's UTC date.
     *
     * @throws ApiException
     */
    private function drawableDay(mixed $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $day = is_string($value) ? $value : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $utc);
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new ApiException('day must be a date, as YYYY-MM-DD.', 400);
        }

        $today = new DateTimeImmutable(Clock::today(), $utc);
        if (abs($date->getTimestamp() - $today->getTimestamp()) > self::MAX_DAYS_FROM_UTC * 86400) {
            throw new ApiException("A daily card can only be drawn for today. Check your device's date.", 400);
        }

        return $date;
    }
}
