<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\FavoriteDeckData;

/**
 * A user's favourite decks on the production schema in memory: deck ids in the
 * order they were picked, an idempotent add, and removal scoped to one user.
 */
#[CoversClass(FavoriteDeckData::class)]
final class FavoriteDeckDataTest extends TestCase
{
    private PDO $db;

    private FavoriteDeckData $favorites;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec(
            "INSERT INTO users (user_id, email, password_hash, display_name)
             VALUES (1, 'one@example.test', '', 'One'), (2, 'two@example.test', '', 'Two')"
        );
        $this->db->exec("INSERT INTO decks (deck_id, name) VALUES (1, 'Thoth'), (2, 'Marseille'), (3, 'Wild Unknown')");

        $this->favorites = new FavoriteDeckData($this->db);
    }

    public function testFavoritesAreListedAsDeckIdsInTheOrderTheyWerePicked(): void
    {
        $this->favorites->add(1, 3);
        $this->favorites->add(1, 1);
        // Both land in the same second; spell out which came first.
        $this->db->exec("UPDATE user_favorite_decks SET created_at = '2026-01-01 00:00:00' WHERE deck_id = 3");
        $this->db->exec("UPDATE user_favorite_decks SET created_at = '2026-01-02 00:00:00' WHERE deck_id = 1");

        $this->assertSame([3, 1], $this->favorites->listByUser(1));
    }

    public function testAddingAFavoriteTwiceKeepsOne(): void
    {
        $this->assertTrue($this->favorites->add(1, 2));
        $this->assertTrue($this->favorites->add(1, 2));

        $this->assertSame([2], $this->favorites->listByUser(1));
    }

    public function testEachUserHasTheirOwnFavorites(): void
    {
        $this->favorites->add(1, 1);
        $this->favorites->add(2, 2);

        $this->assertSame([1], $this->favorites->listByUser(1));
        $this->assertSame([2], $this->favorites->listByUser(2));
        $this->assertSame([], $this->favorites->listByUser(3));
    }

    public function testRemovingTakesTheDeckOffOnlyThatUsersList(): void
    {
        $this->favorites->add(1, 2);
        $this->favorites->add(2, 2);

        $this->assertTrue($this->favorites->remove(1, 2));
        $this->assertTrue($this->favorites->remove(1, 3)); // never a favourite: nothing to do

        $this->assertSame([], $this->favorites->listByUser(1));
        $this->assertSame([2], $this->favorites->listByUser(2));
    }
}
