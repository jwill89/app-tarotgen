<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\DailyCardData;
use Tarot\Structure\DailyCard;

/**
 * The daily_cards table on the committed schema: one card per user per day,
 * read back with its reading's first card, still drawn (with no card) once its
 * reading is deleted, and gone with its account.
 */
#[CoversClass(DailyCardData::class)]
#[CoversClass(DailyCard::class)]
final class DailyCardDataTest extends TestCase
{
    private PDO $db;

    private DailyCardData $data;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $this->db->exec("INSERT INTO users (user_id, email, password_hash, display_name, is_active) VALUES (1, 'a@example.test', 'x', 'A', 1), (2, 'b@example.test', 'x', 'B', 1)");
        $this->db->exec("INSERT INTO decks (deck_id, name) VALUES (3, 'Moonlit Deck')");
        $this->data = new DailyCardData($this->db);
    }

    /** @param list<array<string,mixed>> $draw */
    private function reading(string $id, int $userId, array $draw, int $deckId = 3): void
    {
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, user_id) VALUES (?, ?, ?)')
            ->execute([$id, json_encode(['deck_id' => $deckId, 'origin' => 'generated', 'draw' => $draw], JSON_THROW_ON_ERROR), $userId]);
    }

    public function testADayReadsBackItsReadingsFirstCard(): void
    {
        // More cards drawn into the reading later don't change the day's card.
        $this->reading('r1', 1, [
            ['card_id' => 17, 'reversed' => true, 'card_name' => 'The Star'],
            ['card_id' => 2, 'reversed' => false, 'card_name' => 'The High Priestess'],
        ]);
        $this->assertTrue($this->data->link(1, '2026-10-30', 'r1'));

        $card = $this->data->retrieve(1, '2026-10-30');

        $this->assertEquals(new DailyCard([
            'day' => '2026-10-30', 'reading_id' => 'r1', 'deck_id' => 3, 'deck_name' => 'Moonlit Deck',
            'card_id' => 17, 'card_name' => 'The Star', 'reversed' => true,
        ]), $card);
        $this->assertNull($this->data->retrieve(1, '2026-10-29'));
        $this->assertNull($this->data->retrieve(2, '2026-10-30'));
    }

    public function testAnUnnamedCardAndAGoneDeckGetLabels(): void
    {
        $this->reading('r1', 1, [['card_id' => 79, 'reversed' => false]], deckId: 99);
        $this->data->link(1, '2026-10-30', 'r1');

        $card = $this->data->retrieve(1, '2026-10-30');

        $this->assertNotNull($card);
        $this->assertSame(['Deck #99', 'Card #79', false], [$card->deck_name, $card->card_name, $card->reversed]);
    }

    public function testADayKeepsItsFirstCard(): void
    {
        $this->reading('first', 1, [['card_id' => 1, 'reversed' => false, 'card_name' => 'The Fool']]);
        $this->reading('second', 1, [['card_id' => 2, 'reversed' => false, 'card_name' => 'The Magician']]);

        $this->assertTrue($this->data->link(1, '2026-10-30', 'first'));
        $this->assertFalse($this->data->link(1, '2026-10-30', 'second'));

        $this->assertSame('first', $this->data->retrieve(1, '2026-10-30')?->reading_id);
        // Another account's same day is its own.
        $this->reading('theirs', 2, [['card_id' => 3, 'reversed' => false, 'card_name' => 'The Empress']]);
        $this->assertTrue($this->data->link(2, '2026-10-30', 'theirs'));
    }

    public function testAMonthListsOnlyItsDaysInOrder(): void
    {
        foreach (['2026-09-30', '2026-10-31', '2026-10-01', '2026-11-01'] as $n => $day) {
            $this->reading("r{$n}", 1, [['card_id' => $n + 1, 'reversed' => false, 'card_name' => "Card {$n}"]]);
            $this->data->link(1, $day, "r{$n}");
        }
        $this->reading('theirs', 2, [['card_id' => 9, 'reversed' => false, 'card_name' => 'Theirs']]);
        $this->data->link(2, '2026-10-15', 'theirs');

        $days = array_map(static fn(DailyCard $c): string => $c->day, $this->data->listBetween(1, '2026-10-01', '2026-11-01'));

        $this->assertSame(['2026-10-01', '2026-10-31'], $days);
    }

    public function testDeletingTheReadingKeepsTheDayDrawnWithNoCard(): void
    {
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->reading('r1', 1, [['card_id' => 1, 'reversed' => false, 'card_name' => 'The Fool']]);
        $this->reading('r2', 1, [['card_id' => 2, 'reversed' => true, 'card_name' => 'The Magician']]);
        $this->data->link(1, '2026-10-29', 'r1');
        $this->data->link(1, '2026-10-30', 'r2');

        $this->db->exec("DELETE FROM readings WHERE reading_id IN ('r1', 'r2')");

        // Nothing of the card is kept: it went with the reading.
        $this->assertEquals(new DailyCard(['day' => '2026-10-30']), $this->data->retrieve(1, '2026-10-30'));
        $this->assertSame(['2026-10-29', '2026-10-30'], array_map(static fn(DailyCard $c): string => $c->day, $this->data->listBetween(1, '2026-10-01', '2026-11-01')));
        // The day takes no new card. Many days may be left without a reading (the unique index allows NULLs).
        $this->reading('r3', 1, [['card_id' => 3, 'reversed' => false, 'card_name' => 'The Empress']]);
        $this->assertFalse($this->data->link(1, '2026-10-30', 'r3'));
        $this->assertTrue($this->data->link(1, '2026-10-31', 'r3'));
    }

    public function testDeletingTheAccountTakesItsDays(): void
    {
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->reading('r1', 1, [['card_id' => 1, 'reversed' => false, 'card_name' => 'The Fool']]);
        $this->reading('r2', 2, [['card_id' => 1, 'reversed' => false, 'card_name' => 'The Fool']]);
        $this->reading('r3', 2, [['card_id' => 2, 'reversed' => false, 'card_name' => 'The Magician']]);
        $this->data->link(1, '2026-10-30', 'r1');
        $this->data->link(2, '2026-10-29', 'r2');
        $this->data->link(2, '2026-10-30', 'r3');
        $this->db->exec("DELETE FROM readings WHERE reading_id = 'r2'");

        $this->db->exec('DELETE FROM users WHERE user_id = 2');

        $rows = $this->db->prepare('SELECT user_id, day, reading_id FROM daily_cards');
        $rows->execute();
        $this->assertSame([['user_id' => 1, 'day' => '2026-10-30', 'reading_id' => 'r1']], $rows->fetchAll(PDO::FETCH_ASSOC));
    }
}
