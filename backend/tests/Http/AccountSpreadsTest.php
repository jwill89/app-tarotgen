<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\AccountController;
use Tarot\Data\UserSpreadData;
use Tarot\Repository\FavoriteSpreadRepository;
use Tarot\Repository\UserSpreadRepository;

/**
 * A user's personal spreads: create, list, edit, delete (taking its favourites
 * with it) and submit a copy for the public catalogue. Another account's spread
 * can't be touched through any of them.
 */
#[CoversClass(AccountController::class)]
#[CoversClass(UserSpreadRepository::class)]
#[CoversClass(UserSpreadData::class)]
#[CoversClass(FavoriteSpreadRepository::class)]
final class AccountSpreadsTest extends HttpTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('reader@example.test')->user_id;
    }

    public function testCreatingASpreadStoresItForItsOwner(): void
    {
        $this->signInAs($this->userId);

        $created = $this->assertJsonResponse(201, $this->post('/account/spreads', [
            'name' => '  Crossroads  ',
            'description' => 'Two paths',
            'positions' => [
                ['order' => 1, 'title' => 'Left', 'x' => -20, 'y' => 50, 'rotation' => 0],
                ['order' => 2, 'title' => 'Right', 'x' => 80, 'y' => 120, 'rotation' => -90],
            ],
        ]));

        $this->assertDocumented('post', '/account/spreads', 201, $created);
        $this->assertSame($this->userId, $created['user_id']);
        $this->assertSame('Crossroads', $created['name']);
        $this->assertSame(2, $created['card_count']);
        $this->assertSame([
            ['order' => 1, 'title' => 'Left', 'x' => 0, 'y' => 50, 'rotation' => 0],
            ['order' => 2, 'title' => 'Right', 'x' => 80, 'y' => 100, 'rotation' => 270],
        ], $created['positions']);

        $list = $this->assertJsonResponse(200, $this->get('/account/spreads'));
        $this->assertDocumented('get', '/account/spreads', 200, $list);
        $this->assertSame([$created['user_spread_id']], array_column($list, 'user_spread_id'));
    }

    /**
     * @return iterable<string,array{array<string,mixed>}>
     */
    public static function incompleteSpreads(): iterable
    {
        $one = [['order' => 1, 'title' => 'Only']];
        yield 'no name' => [['positions' => $one]];
        yield 'a blank name' => [['name' => '   ', 'positions' => $one]];
        yield 'no positions' => [['name' => 'Empty']];
        yield 'an empty position list' => [['name' => 'Empty', 'positions' => []]];
        yield 'positions that are not a list' => [['name' => 'Odd', 'positions' => 'one, two']];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('incompleteSpreads')]
    public function testASpreadNeedsANameAndAtLeastOnePosition(array $body): void
    {
        $this->signInAs($this->userId);

        $this->assertSame(
            ['error' => 'A spread name and at least one card position are required.'],
            $this->assertJsonResponse(400, $this->post('/account/spreads', $body))
        );
        $this->assertSame(0, $this->rowCount('user_spreads'));
    }

    public function testAPositionThatIsNotAnObjectIsRefused(): void
    {
        $id = $this->storeSpread($this->userId, 'Kept');
        $this->signInAs($this->userId);
        $invalid = ['error' => 'Invalid position data.'];

        // A position that isn't an object is refused, not dropped on save,
        // which would leave a spread of no (or fewer) cards.
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/account/spreads', ['name' => 'Numbers', 'positions' => [1, 2]])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/account/spreads', [
            'name' => 'Mixed', 'positions' => [['order' => 1, 'title' => 'Real'], 'not a position'],
        ])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->request('PUT', "/account/spreads/{$id}", ['positions' => [1, 2]])));
        $none = ['error' => 'A spread needs at least one card position.'];
        $this->assertSame($none, $this->assertJsonResponse(400, $this->request('PUT', "/account/spreads/{$id}", ['positions' => []])));
        $this->assertSame($none, $this->assertJsonResponse(400, $this->request('PUT', "/account/spreads/{$id}", ['name' => 'Renamed', 'positions' => 'one, two'])));

        $this->assertSame([['name' => 'Kept', 'card_count' => 1]], $this->rows('SELECT name, card_count FROM user_spreads'));
    }

    public function testTheCardCountIsAlwaysTheNumberOfPositions(): void
    {
        $this->signInAs($this->userId);
        $two = [['order' => 1, 'title' => 'A'], ['order' => 2, 'title' => 'B']];

        // A reading from the spread draws card_count cards, so a client's own count isn't taken.
        $created = $this->assertJsonResponse(201, $this->post('/account/spreads', ['name' => 'Pair', 'positions' => $two, 'card_count' => 7]));
        $this->assertSame(2, $created['card_count']);
        $id = $created['user_spread_id'];
        $this->assertIsInt($id);

        $updated = $this->assertJsonResponse(200, $this->request('PUT', "/account/spreads/{$id}", ['positions' => [$two[0]], 'card_count' => 5]));
        $this->assertSame(1, $updated['card_count']);

        $countOnly = $this->request('PUT', "/account/spreads/{$id}", ['card_count' => 9]);
        $this->assertSame(['error' => 'No changes provided.'], $this->assertJsonResponse(400, $countOnly));
        $this->assertSame([['card_count' => 1]], $this->rows('SELECT card_count FROM user_spreads'));
    }

    public function testAnEditCannotBlankTheName(): void
    {
        $id = $this->storeSpread($this->userId, 'Kept');
        $this->signInAs($this->userId);
        $nameless = ['error' => 'A spread needs a name.'];

        $this->assertSame($nameless, $this->assertJsonResponse(400, $this->request('PUT', "/account/spreads/{$id}", ['name' => '   '])));
        $this->assertSame($nameless, $this->assertJsonResponse(400, $this->request('PUT', "/account/spreads/{$id}", ['name' => '', 'description' => 'New'])));
        $this->assertSame($nameless, $this->assertJsonResponse(400, $this->request('PUT', "/account/spreads/{$id}", ['name' => ['Kept']])));

        $this->assertSame([['name' => 'Kept', 'description' => '']], $this->rows('SELECT name, description FROM user_spreads'));
    }

    public function testAnEditWithNothingToChangeIsRefused(): void
    {
        $id = $this->storeSpread($this->userId, 'Mine');
        $this->signInAs($this->userId);

        $this->assertSame(['error' => 'No changes provided.'], $this->assertJsonResponse(400, $this->request('PUT', "/account/spreads/{$id}", [])));
        $this->assertSame(['error' => 'No changes provided.'], $this->assertJsonResponse(400, $this->request('PUT', "/account/spreads/{$id}", ['user_id' => 99])));
        $this->assertSame([['name' => 'Mine', 'user_id' => $this->userId]], $this->rows('SELECT name, user_id FROM user_spreads'));
    }

    public function testTheListHoldsOnlyYourSpreads(): void
    {
        $mine = $this->storeSpread($this->userId, 'Mine');
        $this->storeSpread($this->createUser('other@example.test')->user_id, 'Theirs');
        $this->signInAs($this->userId);

        $this->assertSame([$mine], array_column($this->assertJsonResponse(200, $this->get('/account/spreads')), 'user_spread_id'));
    }

    public function testEditingASpreadRecountsItsCards(): void
    {
        $id = $this->storeSpread($this->userId, 'Before');
        $this->signInAs($this->userId);

        $updated = $this->assertJsonResponse(200, $this->request('PUT', "/account/spreads/{$id}", [
            'name' => 'After',
            'positions' => [['order' => 1, 'title' => 'A'], ['order' => 2, 'title' => 'B'], ['order' => 3, 'title' => 'C']],
        ]));

        $this->assertDocumented('put', '/account/spreads/{user_spread_id}', 200, $updated);
        $this->assertSame(['After', 3], [$updated['name'], $updated['card_count']]);
        $this->assertSame([['name' => 'After', 'card_count' => 3]], $this->rows('SELECT name, card_count FROM user_spreads'));
    }

    public function testDeletingASpreadAlsoDropsTheFavouritesPointingAtIt(): void
    {
        $id = $this->storeSpread($this->userId, 'Doomed');
        $this->signInAs($this->userId);
        $this->assertJsonResponse(201, $this->post('/account/favorites', ['spread_type' => 'personal', 'spread_id' => $id]));
        // The public spread with the same id is a different spread.
        $this->assertJsonResponse(201, $this->post('/account/favorites', ['spread_type' => 'public', 'spread_id' => $id]));

        $response = $this->request('DELETE', "/account/spreads/{$id}");

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(0, $this->rowCount('user_spreads'));
        $this->assertSame([['spread_type' => 'public']], $this->rows('SELECT spread_type FROM user_favorite_spreads'));
    }

    public function testSubmittingASpreadQueuesACopyForReview(): void
    {
        $id = $this->storeSpread($this->userId, 'Share me', 'For everyone');
        $this->signInAs($this->userId);

        $submitted = $this->assertJsonResponse(201, $this->post("/account/spreads/{$id}/submit"));

        $this->assertDocumented('post', '/account/spreads/{user_spread_id}/submit', 201, $submitted);
        $this->assertSame(['success' => true], $submitted);
        $this->assertSame(
            [['name' => 'Share me', 'description' => 'For everyone', 'card_count' => 1, 'user_id' => $this->userId]],
            $this->rows('SELECT name, description, card_count, user_id FROM pending_spreads')
        );
        $pending = $this->rows('SELECT positions FROM pending_spreads')[0]['positions'];
        $this->assertIsString($pending);
        $this->assertSame([['order' => 1, 'title' => 'Only', 'x' => 50, 'y' => 50, 'rotation' => 0]], json_decode($pending, true));
        // The personal copy stays, still the user's.
        $this->assertSame([['user_spread_id' => $id, 'user_id' => $this->userId]], $this->rows('SELECT user_spread_id, user_id FROM user_spreads'));
    }

    public function testPositionDescriptionsAreKeptTrimmedAndCapped(): void
    {
        $this->signInAs($this->userId);

        $created = $this->assertJsonResponse(201, $this->post('/account/spreads', [
            'name' => 'Crossroads',
            'positions' => [
                ['order' => 1, 'title' => 'Left', 'description' => '  The road you know  '],
                ['order' => 2, 'title' => 'Right', 'description' => ''],
            ],
        ]));
        $this->assertDocumented('post', '/account/spreads', 201, $created);
        $this->assertSame('The road you know', $created['positions'][0]['description']);
        $this->assertArrayNotHasKey('description', $created['positions'][1]);

        $updated = $this->assertJsonResponse(200, $this->request('PUT', "/account/spreads/{$created['user_spread_id']}", [
            'positions' => [['order' => 1, 'title' => 'Left', 'description' => str_repeat('ü', 290)]],
        ]));
        $this->assertDocumented('put', '/account/spreads/{user_spread_id}', 200, $updated);
        $this->assertSame(str_repeat('ü', 280), $updated['positions'][0]['description']);

        // Submitting it for review takes the descriptions along.
        $this->assertJsonResponse(201, $this->post("/account/spreads/{$created['user_spread_id']}/submit"));
        $pending = $this->rows('SELECT positions FROM pending_spreads')[0]['positions'];
        $this->assertIsString($pending);
        $this->assertSame(str_repeat('ü', 280), json_decode($pending, true)[0]['description'] ?? null);
    }

    public function testSomeoneElsesSpreadCannotBeEditedDeletedOrSubmitted(): void
    {
        $owner = $this->createUser('owner@example.test');
        $theirs = $this->storeSpread($owner->user_id, 'Theirs');
        // The owner's own favourite of it must survive a stranger's attempt to delete it.
        $this->service(FavoriteSpreadRepository::class)->add($owner->user_id, 'personal', $theirs);
        $this->signInAs($this->userId);

        $notFound = ['error' => 'Spread not found.'];
        $edit = $this->request('PUT', "/account/spreads/{$theirs}", ['name' => 'Hijacked']);
        $this->assertSame($notFound, $this->assertJsonResponse(404, $edit));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('DELETE', "/account/spreads/{$theirs}")));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->post("/account/spreads/{$theirs}/submit")));

        $this->assertSame([['name' => 'Theirs', 'user_id' => $owner->user_id]], $this->rows('SELECT name, user_id FROM user_spreads'));
        $this->assertSame(1, $this->rowCount('user_favorite_spreads'));
        $this->assertSame(0, $this->rowCount('pending_spreads'));
    }

    public function testAnUnknownSpreadIs404(): void
    {
        $this->signInAs($this->userId);

        $notFound = ['error' => 'Spread not found.'];
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PUT', '/account/spreads/999', ['name' => 'Nothing'])));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('DELETE', '/account/spreads/999')));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->post('/account/spreads/999/submit')));
    }

    private function storeSpread(int $userId, string $name, string $description = ''): int
    {
        $spread = $this->service(UserSpreadRepository::class)->create($userId, [
            'name' => $name, 'description' => $description, 'positions' => [['order' => 1, 'title' => 'Only']],
        ]);
        $this->assertNotNull($spread);
        return $spread->user_spread_id;
    }
}
