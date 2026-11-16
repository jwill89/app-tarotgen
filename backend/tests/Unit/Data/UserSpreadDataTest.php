<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Tarot\Data\NormalizesPositions;
use Tarot\Data\UserSpreadData;
use Tarot\Structure\UserSpread;

/**
 * CRUD + ownership scoping for a user's personal spreads, against an in-memory
 * SQLite database.
 */
#[CoversClass(UserSpreadData::class)]
#[CoversTrait(NormalizesPositions::class)]
#[CoversClass(UserSpread::class)]
final class UserSpreadDataTest extends TestCase
{
    private PDO $pdo;
    private UserSpreadData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE user_spreads (
                user_spread_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                card_count INTEGER NOT NULL DEFAULT 1,
                positions TEXT NOT NULL DEFAULT '[]',
                created_at DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                updated_at DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            )"
        );
        $this->data = new UserSpreadData($this->pdo);
    }

    /** @return list<array{order: int, title: string, x: int, y: int, rotation: int}> */
    private function samplePositions(int $n = 2): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['order' => $i, 'title' => "P$i", 'x' => 50, 'y' => 50, 'rotation' => 0];
        }
        return $out;
    }

    /**
     * Store a spread the test builds on, failing the test if the insert doesn't.
     *
     * @param array<string, mixed> $fields
     */
    private function storeSpread(int $userId, array $fields): UserSpread
    {
        $spread = $this->data->store($userId, $fields);
        $this->assertNotNull($spread);

        return $spread;
    }

    public function testStoreAndRetrieveRoundTrips(): void
    {
        $created = $this->data->store(1, [
            'name'        => 'Three Card',
            'description' => 'past/present/future',
            'positions'   => $this->samplePositions(3),
        ]);

        $this->assertInstanceOf(UserSpread::class, $created);
        $this->assertSame('Three Card', $created->name);
        $this->assertSame(1, $created->user_id);
        // card_count is always the position count.
        $this->assertSame(3, $created->card_count);
        $this->assertCount(3, $created->positions);
    }

    public function testRetrieveIsScopedToTheOwningUser(): void
    {
        $mine = $this->storeSpread(1, ['name' => 'Mine', 'positions' => $this->samplePositions()]);
        $this->data->store(2, ['name' => 'Theirs', 'positions' => $this->samplePositions()]);

        $listForUser1 = $this->data->retrieve(1);
        $this->assertCount(1, $listForUser1);
        $this->assertSame('Mine', $listForUser1[0]->name);

        // Scoped fetch by id returns nothing for the wrong user.
        $this->assertCount(0, $this->data->retrieve(2, $mine->user_spread_id));
        $this->assertCount(1, $this->data->retrieve(1, $mine->user_spread_id));
    }

    public function testUpdateRecountsCardsWhenPositionsChange(): void
    {
        $created = $this->storeSpread(1, ['name' => 'Start', 'positions' => $this->samplePositions(2)]);

        $updated = $this->data->update(1, $created->user_spread_id, [
            'name'      => 'Grown',
            'positions' => $this->samplePositions(5),
        ]);

        $this->assertNotNull($updated);
        $this->assertSame('Grown', $updated->name);
        $this->assertSame(5, $updated->card_count); // recomputed from positions
        $this->assertCount(5, $updated->positions);
    }

    public function testUpdateCannotTouchAnotherUsersSpread(): void
    {
        $created = $this->storeSpread(1, ['name' => 'Mine', 'positions' => $this->samplePositions()]);

        // User 2 attempts to rename user 1's spread → no matching row, no change.
        $result = $this->data->update(2, $created->user_spread_id, ['name' => 'Hijacked']);
        $this->assertNull($result);
        $this->assertSame(['Mine'], array_map(static fn(UserSpread $s): string => $s->name, $this->data->retrieve(1, $created->user_spread_id)));
    }

    public function testUpdateWithNoFieldsReturnsNull(): void
    {
        $created = $this->storeSpread(1, ['name' => 'Mine', 'positions' => $this->samplePositions()]);
        $this->assertNull($this->data->update(1, $created->user_spread_id, []));
    }

    public function testDeleteIsOwnershipScoped(): void
    {
        $created = $this->storeSpread(1, ['name' => 'Mine', 'positions' => $this->samplePositions()]);
        $id = $created->user_spread_id;

        // Wrong owner can't delete it.
        $this->assertFalse($this->data->delete(2, $id));
        $this->assertSame([$id], array_map(static fn(UserSpread $s): int => $s->user_spread_id, $this->data->retrieve(1, $id)));

        // Correct owner can.
        $this->assertTrue($this->data->delete(1, $id));
        $this->assertSame([], $this->data->retrieve(1, $id));
    }
}
