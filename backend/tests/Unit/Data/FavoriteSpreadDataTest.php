<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\FavoriteSpreadData;

/**
 * Favorite-spread storage: per-user listing, idempotent add, scoped remove, and
 * bulk removal when a spread is deleted. In-memory SQLite.
 */
#[CoversClass(FavoriteSpreadData::class)]
final class FavoriteSpreadDataTest extends TestCase
{
    private PDO $pdo;
    private FavoriteSpreadData $data;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The UNIQUE constraint is what makes INSERT OR IGNORE idempotent.
        $this->pdo->exec(
            "CREATE TABLE user_favorite_spreads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                spread_type TEXT NOT NULL,
                spread_id INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                UNIQUE (user_id, spread_type, spread_id)
            )"
        );
        $this->data = new FavoriteSpreadData($this->pdo);
    }

    public function testAddThenListReturnsTheFavorite(): void
    {
        $this->data->add(1, 'public', 7);
        $list = $this->data->listByUser(1);

        $this->assertCount(1, $list);
        $this->assertSame('public', $list[0]->spread_type);
        $this->assertSame(7, $list[0]->spread_id);
    }

    public function testAddIsIdempotent(): void
    {
        $this->data->add(1, 'personal', 3);
        $this->data->add(1, 'personal', 3); // duplicate — INSERT OR IGNORE
        $this->assertCount(1, $this->data->listByUser(1));
    }

    public function testListIsScopedPerUser(): void
    {
        $this->data->add(1, 'public', 7);
        $this->data->add(2, 'public', 7);
        $this->data->add(2, 'personal', 9);

        $this->assertCount(1, $this->data->listByUser(1));
        $this->assertCount(2, $this->data->listByUser(2));
    }

    public function testRemoveMatchesAllThreeKeysAndIsScoped(): void
    {
        $this->data->add(1, 'public', 7);
        $this->data->add(1, 'personal', 7); // same id, different type — must survive

        $this->data->remove(1, 'public', 7);

        $remaining = $this->data->listByUser(1);
        $this->assertCount(1, $remaining);
        $this->assertSame('personal', $remaining[0]->spread_type);
    }

    public function testRemoveBySpreadClearsItForEveryUser(): void
    {
        $this->data->add(1, 'personal', 5);
        $this->data->add(2, 'personal', 5);
        $this->data->add(3, 'personal', 6); // different spread — untouched

        $this->data->removeBySpread('personal', 5);

        $this->assertCount(0, $this->data->listByUser(1));
        $this->assertCount(0, $this->data->listByUser(2));
        $this->assertCount(1, $this->data->listByUser(3));
    }
}
