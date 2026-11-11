<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use Routes\Internal\AdminSpreadController;
use Tarot\Data\FavoriteSpreadData;
use Tarot\Data\NormalizesPositions;
use Tarot\Data\PendingSpreadData;
use Tarot\Data\SpreadData;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Structure\Spread;

/**
 * The public spreads as an admin manages them, each in one library category,
 * and the queue of visitor submissions: approving one publishes it (in the
 * category chosen, or the first), rejecting one drops it.
 */
#[CoversClass(AdminSpreadController::class)]
#[CoversClass(SpreadRepository::class)]
#[CoversClass(SpreadData::class)]
#[CoversClass(PendingSpreadRepository::class)]
#[CoversClass(PendingSpreadData::class)]
#[CoversTrait(NormalizesPositions::class)]
final class AdminSpreadsTest extends HttpTestCase
{
    private const array POSITION = ['order' => 1, 'title' => 'Past', 'x' => 25, 'y' => 50, 'rotation' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
    }

    public function testACreatedSpreadIsPublicWithItsPositionsCleanedUp(): void
    {
        $response = $this->post('/admin/spreads', [
            'name' => 'Three Card',
            'description' => 'Past, present, future',
            'positions' => [
                ['order' => 1, 'title' => ' Past ', 'x' => -10, 'y' => 50, 'rotation' => -90, 'description' => ' What led here '],
                ['order' => 2, 'title' => 'Present', 'x' => 50, 'y' => 150, 'rotation' => 450, 'description' => ''],
                'not a position',
            ],
        ]);

        $spread = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/admin/spreads', 201, $spread);
        $this->assertSame(2, $spread['card_count']);
        $this->assertSame([
            ['order' => 1, 'title' => 'Past', 'x' => 0, 'y' => 50, 'rotation' => 270, 'description' => 'What led here'],
            ['order' => 2, 'title' => 'Present', 'x' => 50, 'y' => 100, 'rotation' => 90],
        ], $spread['positions']);
        // Named into no category, so in the first: General.
        $this->assertSame(1, $spread['category_id']);

        $list = $this->assertJsonResponse(200, $this->get('/admin/spreads'));
        $this->assertDocumented('get', '/admin/spreads', 200, $list);
        $this->assertSame(['Three Card'], array_column($list, 'name'));
        $this->assertSame(['Three Card'], array_column($this->assertJsonResponse(200, $this->get('/spreads')), 'name'));
    }

    public function testOneSpreadIsReadForTheEditorWithoutACache(): void
    {
        $this->spread('Celtic Cross', 10);
        $spread = $this->spread('Three Card', 3);

        $response = $this->get("/admin/spreads/{$spread->spread_id}");

        $read = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/admin/spreads/{spread_id}', 200, $read);
        $this->assertSame(['Three Card', 3, 1, 'General'], [$read['name'], $read['card_count'], $read['category_id'], $read['category_name']]);
        // The public GET /spreads/{id} is cached for five minutes; an editor must see the last save.
        $this->assertSame('', $response->getHeaderLine('Cache-Control'));
    }

    public function testReadingASpreadThatIsGoneIsNotFound(): void
    {
        $notFound = ['error' => 'Spread not found'];

        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->get('/admin/spreads/999')));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->get('/admin/spreads/three-card')));
    }

    public function testNewPositionsBringTheCardCountAlongUnlessItIsGiven(): void
    {
        $spread = $this->spread('Three Card', 3);

        $renamed = $this->assertJsonResponse(200, $this->request('PUT', "/admin/spreads/{$spread->spread_id}", [
            'name' => 'One Card', 'positions' => [self::POSITION],
        ]));
        $this->assertDocumented('put', '/admin/spreads/{spread_id}', 200, $renamed);
        $this->assertSame(['One Card', 1], [$renamed['name'], $renamed['card_count']]);

        $explicit = $this->assertJsonResponse(200, $this->request('PUT', "/admin/spreads/{$spread->spread_id}", [
            'positions' => [self::POSITION], 'card_count' => 5,
        ]));
        $this->assertSame(5, $explicit['card_count']);
    }

    public function testAnUpdateWithNothingToChangeOrForNoSpreadIsNotFound(): void
    {
        $spread = $this->spread('Three Card', 3);
        $notFound = ['error' => 'Spread not found or no valid fields'];

        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PUT', "/admin/spreads/{$spread->spread_id}", ['spread_id' => 9])));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PUT', '/admin/spreads/999', ['name' => 'Ghost'])));
        $this->assertSame([['spread_id' => $spread->spread_id, 'name' => 'Three Card']], $this->rows('SELECT spread_id, name FROM spreads'));
    }

    public function testDeletingASpreadDropsTheFavouritesThatPointAtIt(): void
    {
        $doomed = $this->spread('Three Card', 3);
        $kept = $this->spread('Celtic Cross', 10);
        $fan = $this->createUser('fan@example.test');
        $favorites = $this->service(FavoriteSpreadData::class);
        $favorites->add($fan->user_id, 'public', $doomed->spread_id);
        $favorites->add($fan->user_id, 'public', $kept->spread_id);
        // A personal spread that happens to share the id isn't touched.
        $favorites->add($fan->user_id, 'personal', $doomed->spread_id);

        $this->assertSame(204, $this->request('DELETE', "/admin/spreads/{$doomed->spread_id}")->getStatusCode());

        $this->assertSame(['Celtic Cross'], array_column($this->rows('SELECT name FROM spreads'), 'name'));
        $this->assertSame(
            [['spread_type' => 'personal', 'spread_id' => $doomed->spread_id], ['spread_type' => 'public', 'spread_id' => $kept->spread_id]],
            $this->rows('SELECT spread_type, spread_id FROM user_favorite_spreads ORDER BY spread_type')
        );
    }

    public function testApprovingASubmissionPublishesItAndEmptiesItsQueueSlot(): void
    {
        $submitter = $this->createUser('maker@example.test');
        $pending = $this->service(PendingSpreadData::class)->store([
            'name' => 'Crossroads', 'description' => 'Two paths', 'positions' => [self::POSITION, ['order' => 2, 'title' => 'Future']],
        ], $submitter->user_id);
        $this->assertNotNull($pending);
        $queue = $this->assertJsonResponse(200, $this->get('/admin/pending-spreads'));
        $this->assertSame([['Crossroads', 'maker', $submitter->user_id]], array_map(
            static fn(mixed $p): array => is_array($p) ? [$p['name'], $p['submitter'], $p['user_id']] : [],
            $queue
        ));

        $spread = $this->assertJsonResponse(201, $this->post("/admin/pending-spreads/{$pending->pending_id}/approve"));

        $this->assertDocumented('post', '/admin/pending-spreads/{pending_id}/approve', 201, $spread);
        $this->assertSame(['Crossroads', 'Two paths', 2], [$spread['name'], $spread['description'], $spread['card_count']]);
        $this->assertSame(['Past', 'Future'], array_column($spread['positions'], 'title'));
        $this->assertSame(0, $this->rowCount('pending_spreads'));
        $this->assertSame(['Crossroads'], array_column($this->assertJsonResponse(200, $this->get('/spreads')), 'name'));
    }

    public function testApprovingASubmissionThatIsGoneIsNotFound(): void
    {
        $this->assertSame(['error' => 'Pending spread not found'], $this->assertJsonResponse(404, $this->post('/admin/pending-spreads/999/approve')));
        $this->assertSame(0, $this->rowCount('spreads'));
    }

    public function testASpreadNeedsAName(): void
    {
        $required = ['error' => 'Name is required'];

        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/admin/spreads', ['positions' => [self::POSITION]])));
        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/admin/spreads', ['name' => '  ', 'positions' => [self::POSITION]])));
        $this->assertSame(0, $this->rowCount('spreads'));
    }

    public function testARenameCannotBlankTheName(): void
    {
        $spread = $this->spread('Three Card', 3);
        $required = ['error' => 'Name is required'];

        foreach (['', '  ', null] as $blank) {
            $response = $this->request('PUT', "/admin/spreads/{$spread->spread_id}", ['name' => $blank, 'positions' => [self::POSITION]]);
            $this->assertSame($required, $this->assertJsonResponse(400, $response), var_export($blank, true));
        }

        $this->assertSame([['name' => 'Three Card', 'card_count' => 3]], $this->rows('SELECT name, card_count FROM spreads'));
        // Leaving the name out is fine: the rest changes.
        $this->assertSame(1, $this->assertJsonResponse(200, $this->request('PUT', "/admin/spreads/{$spread->spread_id}", ['positions' => [self::POSITION]]))['card_count']);
    }

    public function testRejectingASubmissionDropsItWithoutPublishing(): void
    {
        $pending = $this->service(PendingSpreadData::class)->store(['name' => 'Crossroads', 'positions' => [self::POSITION]]);
        $this->assertNotNull($pending);

        $this->assertSame(204, $this->request('DELETE', "/admin/pending-spreads/{$pending->pending_id}")->getStatusCode());

        $this->assertSame(0, $this->rowCount('pending_spreads'));
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/spreads')));
    }

    public function testSpreadAdministrationNeedsAnAdmin(): void
    {
        $pending = $this->service(PendingSpreadData::class)->store(['name' => 'Crossroads', 'positions' => [self::POSITION]]);
        $this->assertNotNull($pending);
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $this->assertJsonResponse(401, $this->get('/admin/pending-spreads'));
        $this->assertJsonResponse(401, $this->post("/admin/pending-spreads/{$pending->pending_id}/approve"));
        $this->assertJsonResponse(401, $this->post('/admin/spreads', ['name' => 'Mine', 'positions' => [self::POSITION]]));

        $this->assertSame(1, $this->rowCount('pending_spreads'));
        $this->assertSame(0, $this->rowCount('spreads'));
    }

    public function testASpreadIsCreatedInTheCategoryChosenAndMovedBetweenThem(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/admin/spreads', [
            'name' => 'Heart to Heart', 'category_id' => 2, 'positions' => [self::POSITION],
        ]));
        $this->assertSame([2, 'Love & relationships', 2], [$created['category_id'], $created['category_name'], $created['category_sort_order']]);

        $moved = $this->assertJsonResponse(200, $this->request('PUT', "/admin/spreads/{$created['spread_id']}", ['category_id' => '5']));
        $this->assertSame(['Heart to Heart', 5, 'Self-reflection', 5], [$moved['name'], $moved['category_id'], $moved['category_name'], $moved['category_sort_order']]);
        $this->assertSame([['category_id' => 5]], $this->rows('SELECT category_id FROM spreads'));
        $this->assertSame(['Self-reflection'], array_column($this->assertJsonResponse(200, $this->get('/admin/spreads')), 'category_name'));
    }

    public function testAnUnknownCategoryIsRefused(): void
    {
        $spread = $this->spread('Three Card', 3);
        $unknown = ['error' => 'Unknown spread category.'];

        foreach ([99, 0, 'Career', ''] as $categoryId) {
            $label = var_export($categoryId, true);
            $this->assertSame($unknown, $this->assertJsonResponse(400, $this->post('/admin/spreads', ['name' => 'New', 'category_id' => $categoryId])), "create {$label}");
            $this->assertSame($unknown, $this->assertJsonResponse(400, $this->request('PUT', "/admin/spreads/{$spread->spread_id}", ['category_id' => $categoryId])), "update {$label}");
        }

        $this->assertSame([['name' => 'Three Card', 'category_id' => 1]], $this->rows('SELECT name, category_id FROM spreads'));
    }

    public function testApprovingASubmissionPublishesItInTheCategoryChosen(): void
    {
        $pending = $this->service(PendingSpreadData::class)->store(['name' => 'Crossroads', 'positions' => [self::POSITION]]);
        $this->assertNotNull($pending);

        $spread = $this->assertJsonResponse(201, $this->post("/admin/pending-spreads/{$pending->pending_id}/approve", ['category_id' => 4]));

        $this->assertDocumented('post', '/admin/pending-spreads/{pending_id}/approve', 201, $spread);
        $this->assertSame(['Crossroads', 4], [$spread['name'], $spread['category_id']]);
    }

    public function testApprovingIntoAnUnknownCategoryLeavesTheSubmissionQueued(): void
    {
        $pending = $this->service(PendingSpreadData::class)->store(['name' => 'Crossroads', 'positions' => [self::POSITION]]);
        $this->assertNotNull($pending);

        $this->assertSame(
            ['error' => 'Unknown spread category.'],
            $this->assertJsonResponse(400, $this->post("/admin/pending-spreads/{$pending->pending_id}/approve", ['category_id' => 99]))
        );

        $this->assertSame(1, $this->rowCount('pending_spreads'));
        $this->assertSame(0, $this->rowCount('spreads'));
    }

    public function testASubmissionsPositionDescriptionsArePublishedWithIt(): void
    {
        $pending = $this->service(PendingSpreadData::class)->store(['name' => 'Crossroads', 'positions' => [
            ['order' => 1, 'title' => 'Left', 'description' => 'The road you know'],
            ['order' => 2, 'title' => 'Right'],
        ]]);
        $this->assertNotNull($pending);

        $spread = $this->assertJsonResponse(201, $this->post("/admin/pending-spreads/{$pending->pending_id}/approve"));

        $this->assertSame('The road you know', $spread['positions'][0]['description']);
        $this->assertArrayNotHasKey('description', $spread['positions'][1]);
    }

    private function spread(string $name, int $cards): Spread
    {
        $spread = $this->service(SpreadData::class)->store([
            'name' => $name, 'positions' => array_fill(0, $cards, self::POSITION),
        ]);
        $this->assertNotNull($spread);

        return $spread;
    }
}
