<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Service\StatsService;

#[CoversClass(StatsService::class)]
final class StatsServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE decks (deck_id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec(
            "CREATE TABLE readings (
                reading_id TEXT PRIMARY KEY,
                reading_info TEXT NOT NULL,
                reading_time TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->pdo->exec("INSERT INTO decks (deck_id, name) VALUES (1, 'Rider-Waite'), (2, 'Thoth')");
    }

    /** @param array<string, mixed> $info the reading_info payload */
    private function insertReading(string $id, array $info): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO readings (reading_id, reading_info) VALUES (:id, :info)'
        );
        $stmt->execute([':id' => $id, ':info' => json_encode($info, JSON_THROW_ON_ERROR)]);
    }

    public function testOverviewAggregatesTypesDecksAndSpreads(): void
    {
        // Free draw (no spread).
        $this->insertReading('a', ['deck_id' => 1, 'draw' => [['card_id' => 1]]]);
        // Official spread (spread_id > 0).
        $this->insertReading('b', [
            'deck_id' => 1,
            'spread'  => ['spread_id' => 5, 'name' => 'Three Card'],
            'draw'    => [['card_id' => 1], ['card_id' => 2], ['card_id' => 3]],
        ]);
        // Custom reading (spread_id == 0).
        $this->insertReading('c', [
            'deck_id' => 2,
            'spread'  => ['spread_id' => 0, 'name' => 'My Reading'],
            'draw'    => [['card_id' => 1], ['card_id' => 2]],
        ]);

        $overview = new StatsService($this->pdo)->overview();

        $this->assertSame(3, $overview['totals']['readings']);
        $this->assertSame(['freeDraw' => 1, 'spread' => 1, 'custom' => 1], $overview['byType']);

        // Deck 1 used twice, deck 2 once — ordered by count desc.
        $this->assertSame(1, $overview['topDecks'][0]['deck_id']);
        $this->assertSame('Rider-Waite', $overview['topDecks'][0]['name']);
        $this->assertSame(2, $overview['topDecks'][0]['count']);

        // Only the official spread is counted in topSpreads.
        $this->assertSame([['name' => 'Three Card', 'count' => 1]], $overview['topSpreads']);
    }

    public function testCountsReturnsRowCountsPerTable(): void
    {
        // Minimal entity tables for the dashboard summary.
        $this->pdo->exec('CREATE TABLE deck_systems (deck_system_id INTEGER PRIMARY KEY, approved INTEGER NOT NULL DEFAULT 1)');
        $this->pdo->exec('CREATE TABLE special_cards (deck_id INTEGER, card_id INTEGER)');
        $this->pdo->exec('CREATE TABLE spreads (spread_id INTEGER PRIMARY KEY)');
        $this->pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY)');
        $this->pdo->exec('CREATE TABLE contacts (contact_id INTEGER PRIMARY KEY, is_read INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE card_reports (report_id INTEGER PRIMARY KEY, resolved_at TEXT DEFAULT NULL)');
        $this->pdo->exec("INSERT INTO card_reports (resolved_at) VALUES (NULL), (NULL), ('2026-10-20 10:00:00')");
        $this->pdo->exec('CREATE TABLE pending_spreads (pending_id INTEGER PRIMARY KEY)');
        $this->pdo->exec('INSERT INTO pending_spreads (pending_id) VALUES (1), (2), (3)');
        $this->pdo->exec('INSERT INTO deck_systems (deck_system_id, approved) VALUES (1, 1), (2, 0), (3, 1)');
        $this->pdo->exec('INSERT INTO spreads (spread_id) VALUES (1)');
        $this->pdo->exec('INSERT INTO users (user_id) VALUES (1), (2)');

        $counts = new StatsService($this->pdo)->counts();

        $this->assertSame(3, $counts['deckSystems']);
        $this->assertSame(2, $counts['decks']);   // seeded in setUp
        $this->assertSame(0, $counts['specialCards']);
        $this->assertSame(1, $counts['spreads']);
        $this->assertSame(0, $counts['readings']);
        // There is no changelog table to count.
        $this->assertArrayNotHasKey('changelog', $counts);
        $this->assertSame(2, $counts['users']);
        $this->assertSame(0, $counts['unreadContacts']);
        $this->assertSame(2, $counts['openCardReports']);   // the resolved one isn't counted
        $this->assertSame(3, $counts['pendingSpreads']);
        $this->assertSame(1, $counts['pendingDeckSystems']);   // also one of the three deckSystems
    }

    public function testOverviewHandlesNoReadings(): void
    {
        $overview = new StatsService($this->pdo)->overview();

        $this->assertSame(0, $overview['totals']['readings']);
        $this->assertSame(['freeDraw' => 0, 'spread' => 0, 'custom' => 0], $overview['byType']);
        $this->assertSame([], $overview['topDecks']);
        $this->assertSame([], $overview['topSpreads']);
        $this->assertSame([], $overview['daily']);
    }

    public function testTopDecksFallsBackToIdWhenDeckDeleted(): void
    {
        // deck_id 99 has no row in decks.
        $this->insertReading('x', ['deck_id' => 99, 'draw' => [['card_id' => 1]]]);

        $overview = new StatsService($this->pdo)->overview();

        $this->assertSame(99, $overview['topDecks'][0]['deck_id']);
        $this->assertSame('Deck #99', $overview['topDecks'][0]['name']);
    }
}
