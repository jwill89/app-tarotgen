<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\DeckData;
use Tarot\Structure\Deck;

/**
 * Focuses on the card-aspect handling (its clamp) and the update() allow-list,
 * against an in-memory SQLite database.
 */
#[CoversClass(DeckData::class)]
#[CoversClass(Deck::class)]
final class DeckDataTest extends TestCase
{
    private PDO $pdo;
    private DeckData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Mirror the columns DeckData reads/writes, the card-aspect ones included.
        $this->pdo->exec(
            'CREATE TABLE deck_systems (
                deck_system_id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT \'\',
                short_name TEXT NOT NULL DEFAULT \'\',
                total_cards INTEGER NOT NULL DEFAULT 78,
                approved INTEGER NOT NULL DEFAULT 1,
                submitted_by INTEGER DEFAULT NULL
            )'
        );
        $this->pdo->exec("INSERT INTO deck_systems (name, short_name, total_cards) VALUES ('RWS', 'RWS', 78)");

        $this->pdo->exec(
            'CREATE TABLE decks (
                deck_id INTEGER PRIMARY KEY AUTOINCREMENT,
                deck_system_id INTEGER NOT NULL DEFAULT 1,
                name TEXT NOT NULL DEFAULT \'\',
                artist TEXT NOT NULL DEFAULT \'\',
                purchase_url TEXT NOT NULL DEFAULT \'\',
                additional_cards INTEGER NOT NULL DEFAULT 0,
                card_aspect_w REAL NOT NULL DEFAULT 5,
                card_aspect_h REAL NOT NULL DEFAULT 8.6,
                approved INTEGER NOT NULL DEFAULT 1,
                usable INTEGER NOT NULL DEFAULT 0,
                submitted_by INTEGER DEFAULT NULL
            )'
        );
        $this->data = new DeckData($this->pdo);
    }

    /**
     * Store a deck the test builds on, failing the test if the insert doesn't.
     *
     * @param array<string, mixed> $fields
     */
    private function storeDeck(array $fields): Deck
    {
        $deck = $this->data->store($fields);
        $this->assertNotNull($deck);

        return $deck;
    }

    public function testStorePreservesRealMillimetreAspectWithoutTruncating(): void
    {
        // A real card's mm height (151.5) must round-trip intact: a clamp ceiling
        // of 100 would truncate it and corrupt the ratio.
        $deck = $this->data->store(['name' => 'Tall Deck', 'card_aspect_w' => 70.5, 'card_aspect_h' => 151.5]);

        $this->assertInstanceOf(Deck::class, $deck);
        $this->assertSame(70.5, $deck->card_aspect_w);
        $this->assertSame(151.5, $deck->card_aspect_h);
    }

    public function testStoreDefaultsAspectWhenOmitted(): void
    {
        $deck = $this->data->store(['name' => 'Default Deck']);

        $this->assertInstanceOf(Deck::class, $deck);
        $this->assertSame(5.0, $deck->card_aspect_w);
        $this->assertSame(8.6, $deck->card_aspect_h);
    }

    public function testUpdateClampsAspectToAPositiveSaneRange(): void
    {
        $deck = $this->storeDeck(['name' => 'Deck']);
        $id = $deck->deck_id;

        // Non-positive floors to 0.1 (never 0 or negative → no "/ 0" ratio).
        $floored = $this->data->update($id, ['card_aspect_w' => 0, 'card_aspect_h' => -3]);
        $this->assertNotNull($floored);
        $this->assertSame(0.1, $floored->card_aspect_w);
        $this->assertSame(0.1, $floored->card_aspect_h);

        // Absurdly large values cap at the generous ceiling rather than overflow.
        $capped = $this->data->update($id, ['card_aspect_w' => 999999, 'card_aspect_h' => 1e9]);
        $this->assertNotNull($capped);
        $this->assertSame(100000.0, $capped->card_aspect_w);
        $this->assertSame(100000.0, $capped->card_aspect_h);

        // A realistic mm value in the middle is preserved exactly.
        $mm = $this->data->update($id, ['card_aspect_h' => 120]);
        $this->assertNotNull($mm);
        $this->assertSame(120.0, $mm->card_aspect_h);
    }

    public function testUpdateOnlyWritesAllowListedColumns(): void
    {
        $deck = $this->storeDeck(['name' => 'Original', 'additional_cards' => 0]);
        $id = $deck->deck_id;

        // 'name' and 'additional_cards' are allowed; 'submitted_by' and an unknown
        // column are not and must be silently ignored (not error).
        $updated = $this->data->update($id, [
            'name'             => 'Renamed',
            'additional_cards' => 5,
            'submitted_by'     => 999,     // not in the allow-list
            'evil_column'      => 'DROP',  // unknown
        ]);

        $this->assertInstanceOf(Deck::class, $updated);
        $this->assertSame('Renamed', $updated->name);
        $this->assertSame(5, $updated->additional_cards);
        // submitted_by stays NULL (was never set, and update can't touch it).
        $stmt = $this->pdo->prepare('SELECT submitted_by FROM decks WHERE deck_id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertNull($row['submitted_by']);
    }

    public function testColumnsDeckDoesNotDeclareAreNotHydrated(): void
    {
        // A column the Structure doesn't know (e.g. a stray is_thoth in an older
        // database) must not become a dynamic property: PHP deprecates those (which
        // phpunit.xml fails on), and PHP 9 makes them a fatal error.
        $this->pdo->exec('ALTER TABLE decks ADD COLUMN is_thoth INTEGER NOT NULL DEFAULT 0');
        $this->data->store(['name' => 'Deck']);

        $decks = $this->data->retrieve();

        $this->assertCount(1, $decks);
        $this->assertSame('RWS', $decks[0]->system_short_name);
        $this->assertFalse(property_exists($decks[0], 'is_thoth'));
    }

    public function testUpdateReturnsNullWhenNoAllowedFieldsProvided(): void
    {
        $deck = $this->storeDeck(['name' => 'Deck']);
        $result = $this->data->update($deck->deck_id, ['evil_column' => 'x']);
        $this->assertNull($result);
    }
}
