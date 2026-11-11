<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\SpecialCardData;
use Tarot\Data\SpreadData;
use Tarot\Data\UserData;
use Tarot\Data\UserSpreadData;

/**
 * Round-trips the explicit-column SELECTs against the committed db/schema.sql —
 * the production schema — instead of the minimal tables the other Data tests
 * build. A column list that drifts from the real schema fails here (SQLite
 * rejects unknown columns at prepare time), and a stray column reaching a
 * FETCH_CLASS Structure fails as a dynamic-property deprecation.
 */
#[CoversClass(DeckData::class)]
#[CoversClass(DeckSystemData::class)]
#[CoversClass(SpecialCardData::class)]
#[CoversClass(SpreadData::class)]
#[CoversClass(UserData::class)]
#[CoversClass(UserSpreadData::class)]
final class ProductionSchemaReadsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
    }

    public function testDeckAndDeckSystemReads(): void
    {
        $systems = new DeckSystemData($this->db);
        $system = $systems->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'approved' => true]);
        $this->assertNotNull($system);
        $systems->storeCard(['deck_system_id' => $system->deck_system_id, 'card_id' => 1, 'name' => 'The Fool']);

        $this->assertCount(1, $systems->retrieve());
        $this->assertCount(1, $systems->retrieve($system->deck_system_id));
        $this->assertCount(1, $systems->retrieveApproved());
        $this->assertCount(0, $systems->retrievePending());
        $this->assertCount(1, $systems->retrieveCards($system->deck_system_id));
        $this->assertCount(1, $systems->retrieveCardsByIds($system->deck_system_id, [1]));

        $decks = new DeckData($this->db);
        $deck = $decks->store(['name' => 'Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->assertSame('RWS', $deck->system_short_name);
        $this->assertCount(1, $decks->retrieve());
        $this->assertCount(1, $decks->retrieveApproved());
        $this->assertCount(0, $decks->retrievePending());
        $this->assertCount(0, $decks->retrieveUsable());

        $special = new SpecialCardData($this->db);
        $this->assertNotNull($special->store(['deck_id' => $deck->deck_id, 'card_id' => 79, 'name' => 'Extra']));
        $this->assertNotNull($special->retrieve($deck->deck_id, 79));
        $this->assertCount(1, $special->retrieveMultiple($deck->deck_id, [79]));
        $this->assertCount(1, $special->retrieveAll());
        $this->assertCount(1, $special->retrieveAll($deck->deck_id));
    }

    public function testUserAndUserSpreadReads(): void
    {
        $users = new UserData($this->db);
        $user = $users->create('reader@example.test', 'Reader', 'not-a-real-hash');
        $this->assertNotNull($user);
        $users->setGoogleId($user->user_id, 'google-123');

        $this->assertCount(1, $users->getAll());
        $this->assertNotNull($users->findByEmail('reader@example.test'));
        $this->assertTrue($users->findByGoogleId('google-123')?->google_linked);

        $spreads = new UserSpreadData($this->db);
        $spread = $spreads->store($user->user_id, ['name' => 'Mine']);
        $this->assertNotNull($spread);
        $this->assertCount(1, $spreads->retrieve($user->user_id));
        $this->assertCount(1, $spreads->retrieve($user->user_id, $spread->user_spread_id));
    }

    public function testSpreadReads(): void
    {
        $spreads = new SpreadData($this->db);
        $spread = $spreads->store(['name' => 'Three Card']);
        $this->assertNotNull($spread);
        $this->assertCount(1, $spreads->retrieve());
        $this->assertCount(1, $spreads->retrieve($spread->spread_id));
    }
}
