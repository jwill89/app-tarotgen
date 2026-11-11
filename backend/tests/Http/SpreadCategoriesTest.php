<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminSpreadCategoryController;
use Tarot\Data\SpreadCategoryData;
use Tarot\Data\SpreadData;
use Tarot\Repository\SpreadCategoryRepository;
use Tarot\Structure\SpreadCategory;

/**
 * The spread library's categories: admins keep the list — add, rename, reorder,
 * and remove one that no spread is in (never the last). Everyone else reads a
 * spread's category on the spread itself (SpreadsTest).
 */
#[CoversClass(AdminSpreadCategoryController::class)]
#[CoversClass(SpreadCategoryRepository::class)]
#[CoversClass(SpreadCategoryData::class)]
#[CoversClass(SpreadCategory::class)]
final class SpreadCategoriesTest extends HttpTestCase
{
    /** db/schema.sql's starting list, as the migration seeds it. */
    private const array STARTING = ['General', 'Love & relationships', 'Career', 'Decisions', 'Self-reflection', 'Seasonal'];

    public function testAnAdminSeesEveryCategoryInOrderWithItsSpreadCount(): void
    {
        $this->signInAsAdmin();
        $this->spreadIn(3);
        $this->spreadIn(3);
        $this->spreadIn(1);

        $response = $this->get('/admin/spread-categories');

        $list = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/admin/spread-categories', 200, $list);
        // The empty ones too: they're the admin's to fill or remove.
        $this->assertSame(self::STARTING, array_column($list, 'name'));
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($list, 'sort_order'));
        $this->assertSame([1, 0, 2, 0, 0, 0], array_column($list, 'spread_count'));
        $this->assertSame('', $response->getHeaderLine('Cache-Control'));
    }

    public function testThereIsNoPublicListOfCategories(): void
    {
        // A spread carries its category's name and place instead (GET /spreads).
        $this->assertJsonResponse(404, $this->get('/spread-categories'));
    }

    public function testAnAdminAddsACategoryAtTheEnd(): void
    {
        $this->signInAsAdmin();

        $created = $this->assertJsonResponse(201, $this->post('/admin/spread-categories', ['name' => '  Shadow work  ']));

        $this->assertDocumented('post', '/admin/spread-categories', 201, $created);
        $this->assertSame(['name' => 'Shadow work', 'sort_order' => 7, 'spread_count' => 0], array_diff_key($created, ['category_id' => 0]));

        $list = $this->assertJsonResponse(200, $this->get('/admin/spread-categories'));
        $this->assertDocumented('get', '/admin/spread-categories', 200, $list);
        $this->assertSame([...self::STARTING, 'Shadow work'], array_column($list, 'name'));
    }

    public function testANameIsRequiredAndCutTo60Characters(): void
    {
        $this->signInAsAdmin();
        $required = ['error' => 'A category needs a name.'];

        foreach ([[], ['name' => ''], ['name' => '   '], ['name' => ['Career']], ['name' => null]] as $body) {
            $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/admin/spread-categories', $body)), json_encode($body, JSON_THROW_ON_ERROR));
        }
        $this->assertSame(6, $this->rowCount('spread_categories'));

        $long = $this->assertJsonResponse(201, $this->post('/admin/spread-categories', ['name' => str_repeat('é', 70)]));
        $this->assertSame(str_repeat('é', 60), $long['name']);
    }

    public function testTwoCategoriesCannotShareANameWhateverItsCase(): void
    {
        $this->signInAsAdmin();
        $taken = ['error' => 'A spread category with that name already exists.'];

        $this->assertSame($taken, $this->assertJsonResponse(409, $this->post('/admin/spread-categories', ['name' => 'CAREER'])));
        $this->assertSame($taken, $this->assertJsonResponse(409, $this->request('PATCH', '/admin/spread-categories/1', ['name' => 'seasonal'])));

        $this->assertSame(self::STARTING, array_column($this->rows('SELECT name FROM spread_categories ORDER BY sort_order'), 'name'));
    }

    public function testAnAdminRenamesACategory(): void
    {
        $this->signInAsAdmin();
        $spreadId = $this->spreadIn(2);

        $renamed = $this->assertJsonResponse(200, $this->request('PATCH', '/admin/spread-categories/2', ['name' => ' Love ']));

        $this->assertDocumented('patch', '/admin/spread-categories/{category_id}', 200, $renamed);
        $this->assertSame(['category_id' => 2, 'name' => 'Love', 'sort_order' => 2, 'spread_count' => 1], $renamed);
        // Its own name in another case is no clash.
        $this->assertSame('LOVE', $this->assertJsonResponse(200, $this->request('PATCH', '/admin/spread-categories/2', ['name' => 'LOVE']))['name']);
        // Its spreads carry the new name, listed and alone.
        $this->assertSame(['LOVE'], array_column($this->assertJsonResponse(200, $this->get('/spreads')), 'category_name'));
        $this->assertSame('LOVE', $this->assertJsonResponse(200, $this->get("/spreads/{$spreadId}"))['category_name']);
    }

    public function testRenamingNeedsANameAndACategory(): void
    {
        $this->signInAsAdmin();

        $this->assertSame(['error' => 'A category needs a name.'], $this->assertJsonResponse(400, $this->request('PATCH', '/admin/spread-categories/2', ['name' => ' '])));
        $this->assertSame(['error' => 'Category not found.'], $this->assertJsonResponse(404, $this->request('PATCH', '/admin/spread-categories/99', ['name' => 'Ghost'])));

        $this->assertSame([['name' => 'Love & relationships']], $this->rows('SELECT name FROM spread_categories WHERE category_id = 2'));
    }

    public function testAnAdminReordersTheListAndTheFirstBecomesTheDefault(): void
    {
        $this->signInAsAdmin();

        $reordered = $this->assertJsonResponse(200, $this->request('PUT', '/admin/spread-categories/order', [
            'category_ids' => [4, 1, 2, 3, '5', 6],
        ]));

        $this->assertDocumented('put', '/admin/spread-categories/order', 200, $reordered);
        $this->assertSame(['Decisions', 'General', 'Love & relationships', 'Career', 'Self-reflection', 'Seasonal'], array_column($reordered, 'name'));
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($reordered, 'sort_order'));

        // A spread named into no category goes into the first, which after the reorder is Decisions.
        $spread = $this->assertJsonResponse(201, $this->post('/admin/spreads', ['name' => 'Fork in the road']));
        $this->assertSame(['category_id' => 4, 'category_name' => 'Decisions', 'category_sort_order' => 1], array_intersect_key($spread, array_flip(['category_id', 'category_name', 'category_sort_order'])));
    }

    public function testAReorderMustNameEveryCategoryOnce(): void
    {
        $this->signInAsAdmin();
        $refused = ['error' => 'Send every category id once, in the new order.'];

        foreach ([
            [],
            ['category_ids' => [1, 2, 3, 4, 5]],
            ['category_ids' => [1, 2, 3, 4, 5, 5]],
            ['category_ids' => [1, 2, 3, 4, 5, 6, 7]],
            ['category_ids' => [1, 2, 3, 4, 5, 'six']],
            ['category_ids' => [1, 2, 3, 4, 5, 6.5]],
            ['category_ids' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'f' => 6]],
            ['category_ids' => '6,5,4,3,2,1'],
        ] as $body) {
            $this->assertSame($refused, $this->assertJsonResponse(400, $this->request('PUT', '/admin/spread-categories/order', $body)), json_encode($body, JSON_THROW_ON_ERROR));
        }

        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($this->rows('SELECT sort_order FROM spread_categories ORDER BY category_id'), 'sort_order'));
    }

    public function testAnEmptyCategoryCanBeRemoved(): void
    {
        $this->signInAsAdmin();

        $response = $this->request('DELETE', '/admin/spread-categories/6');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame(array_slice(self::STARTING, 0, 5), array_column($this->rows('SELECT name FROM spread_categories ORDER BY sort_order'), 'name'));
        $this->assertSame(['error' => 'Category not found.'], $this->assertJsonResponse(404, $this->request('DELETE', '/admin/spread-categories/6')));
    }

    /**
     * Refused rather than moving its spreads to General: a bulk move can't be
     * undone, and where each spread belongs instead is the admin's call.
     */
    public function testACategoryWithSpreadsCannotBeRemoved(): void
    {
        $this->signInAsAdmin();
        $this->spreadIn(3);

        $this->assertSame(
            ['error' => "\u{201C}Career\u{201D} still holds 1 spread. Move it to another category first."],
            $this->assertJsonResponse(409, $this->request('DELETE', '/admin/spread-categories/3'))
        );

        $this->spreadIn(3);
        $this->assertSame(
            ['error' => "\u{201C}Career\u{201D} still holds 2 spreads. Move them to another category first."],
            $this->assertJsonResponse(409, $this->request('DELETE', '/admin/spread-categories/3'))
        );
        $this->assertSame(6, $this->rowCount('spread_categories'));
    }

    public function testTheLastCategoryStays(): void
    {
        $this->signInAsAdmin();
        foreach ([2, 3, 4, 5, 6] as $id) {
            $this->assertSame(204, $this->request('DELETE', "/admin/spread-categories/{$id}")->getStatusCode());
        }

        $this->assertSame(
            ['error' => 'Keep at least one category: a spread with none chosen goes into the first.'],
            $this->assertJsonResponse(409, $this->request('DELETE', '/admin/spread-categories/1'))
        );
        $this->assertSame([['name' => 'General']], $this->rows('SELECT name FROM spread_categories'));
    }

    public function testKeepingTheListNeedsAnAdmin(): void
    {
        $calls = [
            ['GET', '/admin/spread-categories', null],
            ['POST', '/admin/spread-categories', ['name' => 'Mine']],
            ['PATCH', '/admin/spread-categories/1', ['name' => 'Mine']],
            ['PUT', '/admin/spread-categories/order', ['category_ids' => [6, 5, 4, 3, 2, 1]]],
            ['DELETE', '/admin/spread-categories/6', null],
        ];

        foreach (['a guest' => null, 'a reader' => 'reader@example.test'] as $who => $email) {
            if ($email !== null) {
                $this->signInAs($this->createUser($email)->user_id);
            }
            foreach ($calls as [$method, $path, $body]) {
                $this->assertSame(['error' => 'Unauthorized'], $this->assertJsonResponse(401, $this->request($method, $path, $body)), "{$who}: {$method} {$path}");
            }
        }

        $this->assertSame(self::STARTING, array_column($this->rows('SELECT name FROM spread_categories ORDER BY sort_order'), 'name'));
    }

    private function signInAsAdmin(): void
    {
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
    }

    /** The new spread's id. */
    private function spreadIn(int $categoryId): int
    {
        $spread = $this->service(SpreadData::class)->store([
            'name' => 'A spread', 'category_id' => $categoryId, 'positions' => [['order' => 1, 'title' => 'Here']],
        ]);
        $this->assertNotNull($spread);

        return $spread->spread_id;
    }
}
