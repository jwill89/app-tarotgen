<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\SpecialCardData;
use Tarot\Structure\SpecialCard;

/**
 * A deck's own cards beyond its system's (keyed by deck and card id) on the
 * production schema in memory: lookups that stay within one deck, ordering, and
 * updates limited to the card's texts.
 */
#[CoversClass(SpecialCardData::class)]
final class SpecialCardDataTest extends TestCase
{
    private SpecialCardData $cards;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec("INSERT INTO decks (deck_id, name) VALUES (1, 'Thoth'), (2, 'Marseille')");

        $this->cards = new SpecialCardData($db);
    }

    public function testAStoredCardReadsBackWithEveryText(): void
    {
        $texts = [
            'name' => 'The Sun Card', 'keywords' => 'joy', 'meaning' => 'Warmth.', 'advice' => 'Go outside.',
            'keywords_reversed' => 'delay', 'meaning_reversed' => 'Clouds.', 'advice_reversed' => 'Wait.',
        ];

        $stored = $this->cards->store(['deck_id' => 1, 'card_id' => 78] + $texts);

        $this->assertNotNull($stored);
        $this->assertSame(['deck_id' => 1, 'card_id' => 78] + $texts, $stored->jsonSerialize());
    }

    public function testTextsLeftOutAreStoredEmpty(): void
    {
        $stored = $this->cards->store(['deck_id' => 1, 'card_id' => 78, 'name' => 'Extra']);

        $this->assertNotNull($stored);
        $this->assertSame('', $stored->meaning);
        $this->assertSame('', $stored->advice_reversed);
    }

    public function testAnUnknownCardIsNotFound(): void
    {
        $this->cards->store(['deck_id' => 1, 'card_id' => 78, 'name' => 'Extra']);

        $this->assertNull($this->cards->retrieve(2, 78));
        $this->assertNull($this->cards->retrieve(1, 79));
    }

    public function testSeveralCardsAreFetchedFromOneDeckOnly(): void
    {
        $this->storeCards([[1, 78], [1, 79], [1, 80], [2, 78]]);

        // The query leaves the order open; callers look cards up by id.
        $found = $this->keys($this->cards->retrieveMultiple(1, [80, 78, 81]));
        sort($found);

        $this->assertSame([[1, 78], [1, 80]], $found);
        $this->assertSame([], $this->cards->retrieveMultiple(1, []));
    }

    public function testAllCardsAreListedByDeckThenCardOrForOneDeck(): void
    {
        $this->storeCards([[2, 5], [1, 79], [1, 78]]);

        $this->assertSame([[1, 78], [1, 79], [2, 5]], $this->keys($this->cards->retrieveAll()));
        $this->assertSame([[2, 5]], $this->keys($this->cards->retrieveAll(2)));
    }

    public function testAnUpdateChangesTheTextsButNeverWhichCardItIs(): void
    {
        $this->storeCards([[1, 78]]);

        $updated = $this->cards->update(1, 78, ['meaning' => 'Rewritten.', 'deck_id' => 2, 'card_id' => 0]);

        $this->assertNotNull($updated);
        $this->assertSame([1, 78], [$updated->deck_id, $updated->card_id]);
        $this->assertSame('Rewritten.', $updated->meaning);
        $this->assertSame('Card 78', $updated->name);
    }

    public function testAnUpdateWithNoTextsOrForAMissingCardFindsNoCard(): void
    {
        $this->storeCards([[1, 78]]);

        $this->assertNull($this->cards->update(1, 78, ['deck_id' => 2]));
        $this->assertNull($this->cards->update(1, 79, ['meaning' => 'Ghost.']));
    }

    public function testDeletingRemovesOnlyThatCard(): void
    {
        $this->storeCards([[1, 78], [1, 79], [2, 78]]);

        $this->assertTrue($this->cards->delete(1, 78));

        $this->assertSame([[1, 79], [2, 78]], $this->keys($this->cards->retrieveAll()));
    }

    /** @param list<array{int,int}> $keys deck id, card id */
    private function storeCards(array $keys): void
    {
        foreach ($keys as [$deckId, $cardId]) {
            $this->assertNotNull($this->cards->store(['deck_id' => $deckId, 'card_id' => $cardId, 'name' => "Card {$cardId}"]));
        }
    }

    /**
     * Each card's deck and card id.
     *
     * @param list<SpecialCard> $cards
     * @return list<array{int,int}>
     */
    private function keys(array $cards): array
    {
        return array_map(static fn(SpecialCard $card): array => [$card->deck_id, $card->card_id], $cards);
    }
}
