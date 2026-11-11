<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\SpreadController;
use Tarot\Data\PendingSpreadData;
use Tarot\Data\SpreadData;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Service\SpreadCopyService;

/**
 * The public spreads and the submission form that feeds the review queue: open
 * to guests, limited per visitor, and never published without an admin. A
 * signed-in reader can copy a published spread into their own (My Spreads).
 */
#[CoversClass(SpreadController::class)]
#[CoversClass(SpreadRepository::class)]
#[CoversClass(SpreadData::class)]
#[CoversClass(PendingSpreadRepository::class)]
#[CoversClass(PendingSpreadData::class)]
#[CoversClass(SpreadCopyService::class)]
#[CoversClass(UserSpreadRepository::class)]
final class SpreadsTest extends HttpTestCase
{
    private const array POSITION = ['order' => 1, 'title' => 'Here', 'x' => 50, 'y' => 50, 'rotation' => 0];

    public function testThePublicSpreadsAreListedByName(): void
    {
        $spreads = $this->service(SpreadData::class);
        $spreads->store(['name' => 'Three Card', 'positions' => [self::POSITION, self::POSITION, self::POSITION]]);
        $spreads->store(['name' => 'Celtic Cross', 'positions' => [self::POSITION]]);
        $spreads->store(['name' => 'Crossroads', 'category_id' => 4, 'positions' => [self::POSITION, self::POSITION]]);

        $response = $this->get('/spreads');

        $list = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/spreads', 200, $list);
        $this->assertSame(['Celtic Cross', 'Crossroads', 'Three Card'], array_column($list, 'name'));
        $this->assertSame([1, 2, 3], array_column($list, 'card_count'));
        // Each carries its category, so the library groups them with no second
        // request. Named into none, it's in the first: General.
        $this->assertSame([1, 4, 1], array_column($list, 'category_id'));
        $this->assertSame(['General', 'Decisions', 'General'], array_column($list, 'category_name'));
        $this->assertSame([1, 4, 1], array_column($list, 'category_sort_order'));
        $this->assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
    }

    public function testASingleSpreadOrNotFound(): void
    {
        $spread = $this->service(SpreadData::class)->store(['name' => 'Three Card', 'category_id' => 2, 'positions' => [self::POSITION]]);
        $this->assertNotNull($spread);

        $body = $this->assertJsonResponse(200, $this->get("/spreads/{$spread->spread_id}"));
        $this->assertDocumented('get', '/spreads/{spread_id}', 200, $body);
        $this->assertSame('Three Card', $body['name']);
        $this->assertSame(
            ['category_id' => 2, 'category_name' => 'Love & relationships', 'category_sort_order' => 2],
            array_intersect_key($body, array_flip(['category_id', 'category_name', 'category_sort_order']))
        );

        $missing = $this->get('/spreads/999');
        $this->assertSame(['error' => 'InvalidSpreadID'], $this->assertJsonResponse(404, $missing));
        $this->assertSame('', $missing->getHeaderLine('Cache-Control'));
    }

    public function testAGuestSubmissionJoinsTheQueueButNotTheCatalogue(): void
    {
        $response = $this->post('/spreads', ['name' => ' Crossroads ', 'description' => 'Two paths', 'positions' => [self::POSITION]]);

        $body = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/spreads', 201, $body);
        $this->assertSame(['success' => true], $body);
        $this->assertSame(
            [['name' => 'Crossroads', 'description' => 'Two paths', 'card_count' => 1, 'user_id' => null]],
            $this->rows('SELECT name, description, card_count, user_id FROM pending_spreads')
        );
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/spreads')));
    }

    public function testASignedInSubmissionIsCreditedToTheAccount(): void
    {
        $maker = $this->createUser('maker@example.test');
        $this->signInAs($maker->user_id);

        $this->assertJsonResponse(201, $this->post('/spreads', ['name' => 'Crossroads', 'positions' => [self::POSITION]]));

        $this->assertSame([['user_id' => $maker->user_id]], $this->rows('SELECT user_id FROM pending_spreads'));
    }

    public function testADeletedAccountsLeftoverSessionSubmitsAsAGuest(): void
    {
        $gone = $this->createUser('gone@example.test');
        $this->signInAs($gone->user_id);
        $this->service(UserRepository::class)->delete($gone->user_id);

        // The session's user is gone: the submission is kept with no user, not lost to a 500 from the foreign key.
        $this->assertJsonResponse(201, $this->post('/spreads', ['name' => 'Crossroads', 'positions' => [self::POSITION]]));

        $this->assertSame([['name' => 'Crossroads', 'user_id' => null]], $this->rows('SELECT name, user_id FROM pending_spreads'));
    }

    public function testASubmissionNeedsANameAndAtLeastOnePosition(): void
    {
        $required = ['error' => 'A spread name and at least one card position are required.'];

        foreach ([
            ['positions' => [self::POSITION]],
            ['name' => '   ', 'positions' => [self::POSITION]],
            ['name' => 'Crossroads'],
            ['name' => 'Crossroads', 'positions' => []],
            ['name' => 'Crossroads', 'positions' => 'one'],
        ] as $submission) {
            $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/spreads', $submission)));
        }
        $this->assertSame(0, $this->rowCount('pending_spreads'));
    }

    public function testAVisitorCanSubmitFiveSpreadsAnHour(): void
    {
        // Refused submissions don't use up the allowance.
        $this->assertJsonResponse(400, $this->post('/spreads', ['name' => 'Empty']));
        for ($i = 1; $i <= 5; $i++) {
            $this->assertJsonResponse(201, $this->post('/spreads', ['name' => "Spread {$i}", 'positions' => [self::POSITION]]));
        }

        $this->assertSame(
            ['error' => 'You have submitted too many spreads recently. Please try again later.'],
            $this->assertJsonResponse(429, $this->post('/spreads', ['name' => 'Spread 6', 'positions' => [self::POSITION]]))
        );
        $this->assertSame(5, $this->rowCount('pending_spreads'));
    }

    public function testASubmissionsPositionDescriptionsAreTrimmedAndCapped(): void
    {
        $this->assertJsonResponse(201, $this->post('/spreads', ['name' => 'Crossroads', 'positions' => [
            ['order' => 1, 'title' => 'Here', 'description' => '  What stands in your way  '],
            ['order' => 2, 'title' => 'There', 'description' => str_repeat('a', 300)],
            ['order' => 3, 'title' => 'Blank', 'description' => '   '],
            ['order' => 4, 'title' => 'Odd', 'description' => ['not', 'text']],
        ]]));

        $stored = $this->rows('SELECT positions FROM pending_spreads')[0]['positions'];
        $this->assertIsString($stored);
        $positions = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($positions);
        $this->assertSame('What stands in your way', $positions[0]['description']);
        $this->assertSame(str_repeat('a', 280), $positions[1]['description']);
        // None stored for a blank or non-text description: a position without one.
        $this->assertArrayNotHasKey('description', $positions[2]);
        $this->assertArrayNotHasKey('description', $positions[3]);
    }

    public function testASignedInReaderCopiesASpreadIntoTheirOwn(): void
    {
        $reader = $this->createUser('reader@example.test');
        $spread = $this->service(SpreadData::class)->store([
            'name' => 'Celtic Cross', 'description' => 'The classic ten', 'category_id' => 3,
            'positions' => [
                ['order' => 1, 'title' => 'Heart', 'x' => 40, 'y' => 50, 'description' => 'The matter at hand'],
                ['order' => 2, 'title' => 'Crossing', 'x' => 40, 'y' => 50, 'rotation' => 90],
            ],
        ]);
        $this->assertNotNull($spread);
        $this->signInAs($reader->user_id);

        $copy = $this->assertJsonResponse(201, $this->post("/spreads/{$spread->spread_id}/copy"));

        $this->assertDocumented('post', '/spreads/{spread_id}/copy', 201, $copy);
        $this->assertSame($reader->user_id, $copy['user_id']);
        $this->assertSame(['Celtic Cross (copy)', 'The classic ten', 2], [$copy['name'], $copy['description'], $copy['card_count']]);
        $this->assertSame($spread->positions, $copy['positions']);
        $this->assertSame('The matter at hand', $copy['positions'][0]['description']);
        $this->assertSame([['user_spread_id' => $copy['user_spread_id'], 'name' => 'Celtic Cross (copy)']], $this->rows('SELECT user_spread_id, name FROM user_spreads'));

        // Copying again names the next one apart, whatever case the first copy's name is in.
        $this->db->exec("UPDATE user_spreads SET name = 'CELTIC CROSS (COPY)'");
        $again = $this->assertJsonResponse(201, $this->post("/spreads/{$spread->spread_id}/copy/"));
        $this->assertSame('Celtic Cross (copy 2)', $again['name']);
        // The library's own is untouched.
        $this->assertSame([['name' => 'Celtic Cross', 'category_id' => 3]], $this->rows('SELECT name, category_id FROM spreads'));
    }

    public function testAGuestCannotCopyASpread(): void
    {
        $spread = $this->service(SpreadData::class)->store(['name' => 'Three Card', 'positions' => [self::POSITION]]);
        $this->assertNotNull($spread);

        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->post("/spreads/{$spread->spread_id}/copy")));
        $this->assertSame(0, $this->rowCount('user_spreads'));
    }

    public function testOnlyAPublishedSpreadCanBeCopied(): void
    {
        $pending = $this->service(PendingSpreadData::class)->store(['name' => 'Waiting', 'positions' => [self::POSITION]]);
        $this->assertNotNull($pending);
        $this->signInAs($this->createUser('reader@example.test')->user_id);
        $notFound = ['error' => 'Spread not found.'];

        // A submission still in review is no public spread, by its id or any other.
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->post("/spreads/{$pending->pending_id}/copy")));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->post('/spreads/999/copy')));
        $this->assertSame(0, $this->rowCount('user_spreads'));
    }
}
