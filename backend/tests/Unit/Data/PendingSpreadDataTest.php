<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Tarot\Data\NormalizesPositions;
use Tarot\Data\PendingSpreadData;

#[CoversClass(PendingSpreadData::class)]
#[CoversTrait(NormalizesPositions::class)]
final class PendingSpreadDataTest extends TestCase
{
    private PDO $pdo;
    private PendingSpreadData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE pending_spreads (
                pending_id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                card_count INTEGER NOT NULL DEFAULT 1,
                positions TEXT NOT NULL DEFAULT '[]',
                submitter TEXT NOT NULL DEFAULT '',
                submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER DEFAULT NULL
            )"
        );
        $this->pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, display_name TEXT)');
        $this->pdo->exec("INSERT INTO users (user_id, display_name) VALUES (1, 'Stargazer')");
        $this->data = new PendingSpreadData($this->pdo);
    }

    /**
     * @return array{
     *   name: string,
     *   description: string,
     *   card_count: int,
     *   positions: list<array{order: int, title: string, x: int, y: int, rotation: int}>
     * }
     */
    private function payload(): array
    {
        return ['name' => 'My Spread', 'description' => '', 'card_count' => 1, 'positions' => [
            ['order' => 1, 'title' => '', 'x' => 50, 'y' => 50, 'rotation' => 0],
        ]];
    }

    public function testGuestSubmissionResolvesToGuest(): void
    {
        $created = $this->data->store($this->payload());
        $this->assertNotNull($created);
        $this->assertSame('Guest', $created->submitter);
        $this->assertNull($created->user_id);
    }

    public function testLoggedInSubmissionResolvesToDisplayName(): void
    {
        $created = $this->data->store($this->payload(), 1);
        $this->assertNotNull($created);
        $this->assertSame('Stargazer', $created->submitter);
        $this->assertSame(1, $created->user_id);

        // Renaming the account is reflected (resolved live, not snapshotted).
        $this->pdo->exec("UPDATE users SET display_name = 'New Name' WHERE user_id = 1");
        $reloaded = $this->data->retrieve($created->pending_id);
        $this->assertSame('New Name', $reloaded[0]->submitter);
    }

    public function testLegacyFreeTextSubmitterIsUsedWhenNoAccount(): void
    {
        // A row with no account shows its stored free-text submitter name.
        $this->pdo->exec("INSERT INTO pending_spreads (name, positions, submitter) VALUES ('Old', '[]', 'Old Timer')");
        $rows = $this->data->retrieve();
        $this->assertSame('Old Timer', $rows[0]->submitter);
    }
}
