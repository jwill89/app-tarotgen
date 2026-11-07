<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\SpreadCategoryData;
use Tarot\Data\SpreadData;
use Tarot\Exception\NameTakenException;
use Tarot\Structure\SpreadCategory;

/**
 * The spread library's categories on the production schema in memory, which
 * seeds the starting list: kept in order, names unique whatever their case, and
 * the spreads' foreign key standing behind "no deleting a category in use".
 */
#[CoversClass(SpreadCategoryData::class)]
#[CoversClass(SpreadData::class)]
#[CoversClass(SpreadCategory::class)]
final class SpreadCategoryDataTest extends TestCase
{
    private PDO $db;

    private SpreadCategoryData $categories;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->categories = new SpreadCategoryData($this->db);
    }

    public function testTheStartingListIsInOrderWithSpreadCounts(): void
    {
        $spreads = new SpreadData($this->db);
        $spreads->store(['name' => 'One', 'category_id' => 2]);
        $spreads->store(['name' => 'Two', 'category_id' => 2]);

        $list = $this->categories->retrieve();

        $this->assertSame(
            ['General', 'Love & relationships', 'Career', 'Decisions', 'Self-reflection', 'Seasonal'],
            array_map(static fn(SpreadCategory $c): string => $c->name, $list)
        );
        $this->assertSame([0, 2, 0, 0, 0, 0], array_map(static fn(SpreadCategory $c): int => $c->spread_count, $list));
        $this->assertSame('Love & relationships', $this->categories->retrieve(2)[0]->name);
        $this->assertSame([], $this->categories->retrieve(99));
    }

    public function testANewCategoryGoesAtTheEnd(): void
    {
        $created = $this->categories->store('Shadow work');

        $this->assertNotNull($created);
        $this->assertSame(['Shadow work', 7, 0], [$created->name, $created->sort_order, $created->spread_count]);
    }

    public function testANameTakenInAnyCaseIsRefused(): void
    {
        $this->expectException(NameTakenException::class);

        $this->categories->store('general');
    }

    public function testRenamingKeepsNamesUniqueAndMissesAnUnknownCategory(): void
    {
        $this->assertSame('Love', $this->categories->rename(2, 'Love')?->name);
        $this->assertNull($this->categories->rename(99, 'Ghost'));

        $this->expectException(NameTakenException::class);
        $this->categories->rename(2, 'CAREER');
    }

    public function testAnotherDatabaseErrorIsNotMistakenForATakenName(): void
    {
        $this->db->exec('DROP TABLE spread_categories');

        // The database's own error, not a NameTakenException (no PDOException).
        $this->expectException(PDOException::class);
        $this->categories->rename(1, 'Anything');
    }

    public function testReorderingNumbersTheListInTheOrderGiven(): void
    {
        $this->categories->reorder([6, 5, 4, 3, 2, 1]);

        $this->assertSame(
            [[6, 1], [5, 2], [4, 3], [3, 4], [2, 5], [1, 6]],
            array_map(static fn(SpreadCategory $c): array => [$c->category_id, $c->sort_order], $this->categories->retrieve())
        );
        // A spread named into none now goes into the first: Seasonal.
        $this->assertSame(6, new SpreadData($this->db)->store(['name' => 'Solstice'])?->category_id);
    }

    public function testOnlyAnEmptyCategoryCanBeDeleted(): void
    {
        new SpreadData($this->db)->store(['name' => 'Kept', 'category_id' => 3]);

        $this->assertTrue($this->categories->delete(4));
        $this->assertFalse($this->categories->delete(4));

        // The controller checks first; the foreign key stands behind it.
        $this->expectException(PDOException::class);
        $this->categories->delete(3);
    }

    public function testASpreadMovesBetweenCategoriesAndKeepsItsOwnWhenNoneIsNamed(): void
    {
        $spreads = new SpreadData($this->db);
        $spread = $spreads->store(['name' => 'Fork', 'category_id' => '4']);
        $this->assertNotNull($spread);
        $this->assertSame(4, $spread->category_id);

        $this->assertSame(5, $spreads->update($spread->spread_id, ['category_id' => 5])?->category_id);
        $this->assertSame(5, $spreads->update($spread->spread_id, ['name' => 'Renamed', 'category_id' => null])?->category_id);
        $this->assertNull($spreads->update($spread->spread_id, ['category_id' => 0]));
    }
}
