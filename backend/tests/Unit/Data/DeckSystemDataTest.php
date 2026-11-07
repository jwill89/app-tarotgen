<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\DeckSystemData;
use Tarot\Exception\NameTakenException;
use Tarot\Structure\DeckSystem;
use Tarot\Structure\DeckSystemCard;

/**
 * CRUD for deck systems and their per-system card definitions, including the
 * ON DELETE CASCADE from deck_systems → deck_system_cards. In-memory SQLite.
 */
#[CoversClass(DeckSystemData::class)]
#[CoversClass(DeckSystem::class)]
#[CoversClass(DeckSystemCard::class)]
final class DeckSystemDataTest extends TestCase
{
    private PDO $pdo;
    private DeckSystemData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The real Connection enables this; needed for the cascade test.
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        // Mirror db/migrate_deck_systems.php.
        $this->pdo->exec(
            "CREATE TABLE deck_systems (
                deck_system_id INTEGER PRIMARY KEY AUTOINCREMENT,
                name           TEXT NOT NULL UNIQUE,
                short_name     TEXT NOT NULL UNIQUE,
                total_cards    INTEGER NOT NULL DEFAULT 78,
                approved       INTEGER NOT NULL DEFAULT 1,
                submitted_by   INTEGER DEFAULT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE deck_system_cards (
                deck_system_id    INTEGER NOT NULL,
                card_id           INTEGER NOT NULL,
                name              TEXT NOT NULL DEFAULT '',
                keywords          TEXT DEFAULT NULL,
                meaning           TEXT DEFAULT NULL,
                advice            TEXT DEFAULT NULL,
                reversed_keywords TEXT DEFAULT NULL,
                reversed_meaning  TEXT DEFAULT NULL,
                reversed_advice   TEXT DEFAULT NULL,
                PRIMARY KEY (deck_system_id, card_id),
                FOREIGN KEY (deck_system_id) REFERENCES deck_systems(deck_system_id) ON DELETE CASCADE
            )"
        );

        $this->data = new DeckSystemData($this->pdo);
    }

    /** @return array{card_id: int, name: string, keywords: string, meaning: string} */
    private function card(int $id, string $name): array
    {
        return ['card_id' => $id, 'name' => $name, 'keywords' => "kw$id", 'meaning' => "m$id"];
    }

    /**
     * Store a system the test builds on, failing the test if the insert doesn't.
     *
     * @param array<string, mixed> $fields
     */
    private function storeSystem(array $fields): DeckSystem
    {
        $system = $this->data->store($fields);
        $this->assertNotNull($system);

        return $system;
    }

    // ── Systems ──────────────────────────────────────────────────

    public function testStoreAndRetrieveRoundTrips(): void
    {
        $system = $this->data->store([
            'name'         => 'Marseille',
            'short_name'   => 'TdM',
            'total_cards'  => 78,
            'approved'     => true,
            'submitted_by' => 42,
        ]);

        $this->assertInstanceOf(DeckSystem::class, $system);
        $this->assertSame('Marseille', $system->name);
        $this->assertSame('TdM', $system->short_name);
        $this->assertSame(78, $system->total_cards);
        $this->assertTrue($system->approved);
        $this->assertSame(42, $system->submitted_by);
    }

    public function testStoreDefaultsToUnapproved(): void
    {
        // A public submission (no 'approved' key) defaults to pending.
        $system = $this->data->store(['name' => 'Pending Sys', 'short_name' => 'PS']);
        $this->assertNotNull($system);
        $this->assertFalse($system->approved);
        $this->assertNull($system->submitted_by);
    }

    public function testRetrieveAllIsOrderedByName(): void
    {
        $this->data->store(['name' => 'Zeta', 'short_name' => 'Z', 'approved' => true]);
        $this->data->store(['name' => 'Alpha', 'short_name' => 'A', 'approved' => true]);

        $names = array_map(fn(DeckSystem $s) => $s->name, $this->data->retrieve());
        $this->assertSame(['Alpha', 'Zeta'], $names);
    }

    public function testApprovedAndPendingFilters(): void
    {
        $this->data->store(['name' => 'Live', 'short_name' => 'L', 'approved' => true]);
        $this->data->store(['name' => 'Queued', 'short_name' => 'Q', 'approved' => false]);

        $this->assertSame(['Live'], array_map(fn(DeckSystem $s) => $s->name, $this->data->retrieveApproved()));
        $this->assertSame(['Queued'], array_map(fn(DeckSystem $s) => $s->name, $this->data->retrievePending()));
    }

    public function testUpdateOnlyWritesAllowListedColumns(): void
    {
        $system = $this->storeSystem(['name' => 'Orig', 'short_name' => 'O', 'total_cards' => 78]);
        $id = $system->deck_system_id;

        $updated = $this->data->update($id, [
            'name'         => 'Renamed',
            'total_cards'  => 40,
            'approved'     => true,
            'submitted_by' => 999,     // not allow-listed
            'evil'         => 'DROP',  // unknown
        ]);

        $this->assertNotNull($updated);
        $this->assertSame('Renamed', $updated->name);
        $this->assertSame(40, $updated->total_cards);
        $this->assertTrue($updated->approved);
        // submitted_by could not be touched by update.
        $this->assertNull($updated->submitted_by);
    }

    public function testUpdateReturnsNullWhenNoAllowedFields(): void
    {
        $system = $this->storeSystem(['name' => 'X', 'short_name' => 'X']);
        $this->assertNull($this->data->update($system->deck_system_id, ['evil' => 'x']));
    }

    public function testRenamingToAnotherSystemsNameIsReportedAsTaken(): void
    {
        $this->storeSystem(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS']);
        $system = $this->storeSystem(['name' => 'Lenormand', 'short_name' => 'LEN']);

        $this->expectException(NameTakenException::class);
        $this->data->update($system->deck_system_id, ['name' => 'Rider-Waite-Smith']);
    }

    public function testOtherConstraintErrorsOnUpdateAreNotTakenForANameConflict(): void
    {
        $system = $this->storeSystem(['name' => 'Lenormand', 'short_name' => 'LEN']);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageIsOrContains('NOT NULL constraint failed: deck_systems.name');
        $this->data->update($system->deck_system_id, ['name' => null]);
    }

    public function testDeleteRemovesTheSystem(): void
    {
        $system = $this->storeSystem(['name' => 'Doomed', 'short_name' => 'D']);
        $this->assertTrue($this->data->delete($system->deck_system_id));
        $this->assertFalse($this->data->delete($system->deck_system_id)); // already gone
        $this->assertSame([], $this->data->retrieve($system->deck_system_id));
    }

    // ── Cards ────────────────────────────────────────────────────

    public function testStoreCardsAndRetrieveOrderedByCardId(): void
    {
        $sys = $this->storeSystem(['name' => 'Sys', 'short_name' => 'S']);
        $id = $sys->deck_system_id;

        $this->data->storeCards($id, [$this->card(3, 'Three'), $this->card(1, 'One'), $this->card(2, 'Two')]);

        $cards = $this->data->retrieveCards($id);
        $this->assertCount(3, $cards);
        $this->assertSame([1, 2, 3], array_map(fn(DeckSystemCard $c) => $c->card_id, $cards));
        $this->assertSame('One', $cards[0]->name);
        $this->assertSame('kw1', $cards[0]->keywords);
    }

    public function testStoreCardUpsertsOnConflict(): void
    {
        $sys = $this->storeSystem(['name' => 'Sys', 'short_name' => 'S']);
        $id = $sys->deck_system_id;

        $this->data->storeCard(['deck_system_id' => $id, 'card_id' => 1, 'name' => 'Original']);
        $this->data->storeCard(['deck_system_id' => $id, 'card_id' => 1, 'name' => 'Replaced']);

        $cards = $this->data->retrieveCards($id);
        $this->assertCount(1, $cards, 'same (system, card_id) is replaced, not duplicated');
        $this->assertSame('Replaced', $cards[0]->name);
    }

    public function testRetrieveCardsByIdsReturnsSubsetAndEmptyForNoIds(): void
    {
        $sys = $this->storeSystem(['name' => 'Sys', 'short_name' => 'S']);
        $id = $sys->deck_system_id;
        $this->data->storeCards($id, [$this->card(1, 'One'), $this->card(2, 'Two'), $this->card(3, 'Three')]);

        $subset = $this->data->retrieveCardsByIds($id, [1, 3]);
        $this->assertSame([1, 3], array_map(fn(DeckSystemCard $c) => $c->card_id, $subset));

        $this->assertSame([], $this->data->retrieveCardsByIds($id, []));
    }

    public function testDeleteCardsClearsOnlyThatSystem(): void
    {
        $a = $this->storeSystem(['name' => 'A', 'short_name' => 'A']);
        $b = $this->storeSystem(['name' => 'B', 'short_name' => 'B']);
        $this->data->storeCards($a->deck_system_id, [$this->card(1, 'A1')]);
        $this->data->storeCards($b->deck_system_id, [$this->card(1, 'B1')]);

        $this->data->deleteCards($a->deck_system_id);

        $this->assertCount(0, $this->data->retrieveCards($a->deck_system_id));
        $this->assertCount(1, $this->data->retrieveCards($b->deck_system_id));
    }

    public function testDeletingSystemCascadesToItsCards(): void
    {
        $sys = $this->storeSystem(['name' => 'Sys', 'short_name' => 'S']);
        $id = $sys->deck_system_id;
        $this->data->storeCards($id, [$this->card(1, 'One'), $this->card(2, 'Two')]);

        $this->data->delete($id);

        // FK ON DELETE CASCADE should have removed the card rows too.
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM deck_system_cards');
        $count->execute();
        $this->assertSame(0, (int) $count->fetchColumn());
    }
}
