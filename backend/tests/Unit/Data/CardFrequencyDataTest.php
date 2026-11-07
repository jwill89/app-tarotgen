<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\CardFrequencyData;
use Tarot\Structure\CardCount;

/**
 * Counting cards over a user's readings on the committed schema: how cards
 * group with and without a deck, the filters, and the readings it skips.
 */
#[CoversClass(CardFrequencyData::class)]
#[CoversClass(CardCount::class)]
final class CardFrequencyDataTest extends TestCase
{
    private PDO $db;

    private CardFrequencyData $data;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $this->db->exec("INSERT INTO users (user_id, email, password_hash, display_name, is_active) VALUES (1, 'a@example.test', 'x', 'A', 1), (2, 'b@example.test', 'x', 'B', 1)");
        $this->data = new CardFrequencyData($this->db);
    }

    /** @param list<mixed> $draw */
    private function reading(string $id, ?int $userId, int $deckId, array $draw, string $time = '2026-10-01 12:00:00'): void
    {
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, reading_time, user_id) VALUES (?, ?, ?, ?)')
            ->execute([$id, json_encode(['deck_id' => $deckId, 'draw' => $draw], JSON_THROW_ON_ERROR), $time, $userId]);
    }

    /** @return array<string,mixed> */
    private static function card(int $id, ?string $name, bool $reversed = false): array
    {
        return $name === null
            ? ['card_id' => $id, 'reversed' => $reversed]
            : ['card_id' => $id, 'reversed' => $reversed, 'card_name' => $name];
    }

    /**
     * @param list<CardCount> $counts
     * @return list<array<string,mixed>>
     */
    private static function rows(array $counts): array
    {
        return array_map(static fn(CardCount $c): array => $c->jsonSerialize(), $counts);
    }

    public function testAcrossDecksACardIsItsName(): void
    {
        $this->reading('a', 1, 1, [self::card(16, 'The Tower'), self::card(17, 'The Star', true)]);
        $this->reading('b', 1, 2, [self::card(16, 'The Tower', true), self::card(79, null)]);
        $this->reading('c', 1, 3, [self::card(79, null), self::card(1, 'the fool')]);
        // Not this user's: another account's, and a guest's.
        $this->reading('d', 2, 1, [self::card(16, 'The Tower')]);
        $this->reading('e', null, 1, [self::card(16, 'The Tower')]);

        $this->assertSame([
            ['card_name' => 'The Tower', 'card_id' => null, 'deck_id' => null, 'count' => 2, 'upright' => 1, 'reversed' => 1],
            // An unnamed card is its deck's own, so the two Card #79s stay apart.
            ['card_name' => 'Card #79', 'card_id' => 79, 'deck_id' => 2, 'count' => 1, 'upright' => 1, 'reversed' => 0],
            ['card_name' => 'Card #79', 'card_id' => 79, 'deck_id' => 3, 'count' => 1, 'upright' => 1, 'reversed' => 0],
            ['card_name' => 'the fool', 'card_id' => null, 'deck_id' => null, 'count' => 1, 'upright' => 1, 'reversed' => 0],
            ['card_name' => 'The Star', 'card_id' => null, 'deck_id' => null, 'count' => 1, 'upright' => 0, 'reversed' => 1],
        ], self::rows($this->data->cardCounts(1, null, null)));
        $this->assertSame(3, $this->data->countReadings(1, null, null));
    }

    public function testWithinADeckACardIsItsId(): void
    {
        // Card 79 was drawn before the deck named it and after.
        $this->reading('a', 1, 2, [self::card(79, null), self::card(16, 'The Tower')]);
        $this->reading('b', 1, 2, [self::card(79, 'The Wish', true)]);
        $this->reading('c', 1, 1, [self::card(16, 'The Tower')]);

        $this->assertSame([
            ['card_name' => 'The Wish', 'card_id' => 79, 'deck_id' => 2, 'count' => 2, 'upright' => 1, 'reversed' => 1],
            ['card_name' => 'The Tower', 'card_id' => 16, 'deck_id' => 2, 'count' => 1, 'upright' => 1, 'reversed' => 0],
        ], self::rows($this->data->cardCounts(1, 2, null)));
        $this->assertSame(2, $this->data->countReadings(1, 2, null));
        $this->assertSame([], $this->data->cardCounts(1, 5, null));
        $this->assertSame(0, $this->data->countReadings(1, 5, null));
    }

    public function testAWindowCountsReadingsFromItsStart(): void
    {
        $this->reading('old', 1, 1, [self::card(1, 'The Fool')], '2026-09-30 23:59:59');
        $this->reading('new', 1, 1, [self::card(2, 'The Magician')], '2026-10-01 00:00:00');

        $names = array_map(static fn(CardCount $c): string => $c->card_name, $this->data->cardCounts(1, null, '2026-10-01 00:00:00'));

        $this->assertSame(['The Magician'], $names);
        $this->assertSame(1, $this->data->countReadings(1, 1, '2026-10-01 00:00:00'));
    }

    public function testAReadingItCannotReadIsLeftOut(): void
    {
        $this->reading('good', 1, 1, [self::card(1, 'The Fool'), 'not a card', self::card(2, '')]);
        $this->db->exec("INSERT INTO readings (reading_id, reading_info, user_id) VALUES ('broken', '{\"deck_id\": 1, \"draw\": [', 1)");
        $this->db->exec("INSERT INTO readings (reading_id, reading_info, user_id) VALUES ('nodraw', '{\"deck_id\": 1}', 1)");

        // A blank name is no name: card 2 is labelled by its number.
        $this->assertSame(['Card #2', 'The Fool'], array_map(static fn(CardCount $c): string => $c->card_name, $this->data->cardCounts(1, null, null)));
        $this->assertSame(['Card #2', 'The Fool'], array_map(static fn(CardCount $c): string => $c->card_name, $this->data->cardCounts(1, 1, null)));
        $this->assertSame(2, $this->data->countReadings(1, null, null));
    }
}
