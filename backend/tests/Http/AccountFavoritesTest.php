<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\AccountController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\FavoriteDeckData;
use Tarot\Repository\FavoriteDeckRepository;
use Tarot\Repository\FavoriteSpreadRepository;

/**
 * Favourite spreads and decks: adding (idempotent), listing and removing, each
 * scoped to the signed-in user, and what is refused.
 */
#[CoversClass(AccountController::class)]
#[CoversClass(FavoriteSpreadRepository::class)]
#[CoversClass(FavoriteDeckRepository::class)]
#[CoversClass(FavoriteDeckData::class)]
final class AccountFavoritesTest extends HttpTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('reader@example.test')->user_id;
    }

    public function testFavouriteSpreadsAreListedInTheOrderTheyWereAdded(): void
    {
        $this->signInAs($this->userId);

        $added = $this->assertJsonResponse(201, $this->post('/account/favorites', ['spread_type' => 'public', 'spread_id' => 3]));
        $this->assertDocumented('post', '/account/favorites', 201, $added);
        $this->assertSame(['success' => true], $added);
        $this->assertJsonResponse(201, $this->post('/account/favorites', ['spread_type' => 'personal', 'spread_id' => '5']));
        // Adding one again changes nothing.
        $this->assertJsonResponse(201, $this->post('/account/favorites', ['spread_type' => 'public', 'spread_id' => 3]));

        $list = $this->assertJsonResponse(200, $this->get('/account/favorites'));

        $this->assertDocumented('get', '/account/favorites', 200, $list);
        $this->assertSame([['spread_type' => 'public', 'spread_id' => 3], ['spread_type' => 'personal', 'spread_id' => 5]], $list);
    }

    /**
     * @return iterable<string,array{string,mixed}>
     */
    public static function invalidFavourites(): iterable
    {
        yield 'an unknown type' => ['official', 3];
        yield 'id zero' => ['public', 0];
        yield 'a negative id' => ['personal', -2];
        yield 'a non-numeric id' => ['public', 'three'];
    }

    #[DataProvider('invalidFavourites')]
    public function testAnInvalidFavouriteSpreadIsRefused(string $type, mixed $id): void
    {
        $this->signInAs($this->userId);
        $invalid = ['error' => 'Invalid spread type or ID.'];

        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/account/favorites', ['spread_type' => $type, 'spread_id' => $id])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->request('DELETE', "/account/favorites/{$type}/{$this->pathId($id)}")));
        $this->assertSame(0, $this->rowCount('user_favorite_spreads'));
    }

    public function testRemovingAFavouriteSpreadLeavesEveryoneElsesAlone(): void
    {
        $other = $this->createUser('other@example.test');
        $favorites = $this->service(FavoriteSpreadRepository::class);
        $favorites->add($this->userId, 'public', 3);
        $favorites->add($this->userId, 'personal', 3);
        $favorites->add($other->user_id, 'public', 3);
        $this->signInAs($this->userId);

        $response = $this->request('DELETE', '/account/favorites/public/3');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(
            [['user_id' => $this->userId, 'spread_type' => 'personal'], ['user_id' => $other->user_id, 'spread_type' => 'public']],
            $this->rows('SELECT user_id, spread_type FROM user_favorite_spreads ORDER BY user_id, spread_type')
        );
        // Removing one that isn't there is not an error: the end state is what was asked for.
        $this->assertSame(204, $this->request('DELETE', '/account/favorites/public/3')->getStatusCode());
        $this->assertSame([['spread_type' => 'personal', 'spread_id' => 3]], $this->assertJsonResponse(200, $this->get('/account/favorites')));
    }

    public function testFavouriteDecksCanBeAddedListedAndRemoved(): void
    {
        $first = $this->deck('First Deck');
        $second = $this->deck('Second Deck');
        $other = $this->createUser('other@example.test');
        $this->service(FavoriteDeckRepository::class)->add($other->user_id, $first);
        $this->signInAs($this->userId);

        $added = $this->assertJsonResponse(201, $this->post('/account/favorite-decks', ['deck_id' => $second]));
        $this->assertDocumented('post', '/account/favorite-decks', 201, $added);
        $this->assertSame(['success' => true], $added);
        $this->assertJsonResponse(201, $this->post('/account/favorite-decks', ['deck_id' => $first]));
        $this->assertJsonResponse(201, $this->post('/account/favorite-decks', ['deck_id' => $second]));
        $list = $this->assertJsonResponse(200, $this->get('/account/favorite-decks'));
        $this->assertDocumented('get', '/account/favorite-decks', 200, $list);
        // Deck ids, in the order they were added.
        $this->assertSame([$second, $first], $list);

        $this->assertSame(204, $this->request('DELETE', "/account/favorite-decks/{$first}")->getStatusCode());

        $this->assertSame([$second], $this->assertJsonResponse(200, $this->get('/account/favorite-decks')));
        $this->assertSame(
            [['user_id' => $this->userId, 'deck_id' => $second], ['user_id' => $other->user_id, 'deck_id' => $first]],
            $this->rows('SELECT user_id, deck_id FROM user_favorite_decks ORDER BY user_id')
        );
    }

    public function testAnInvalidFavouriteDeckIsRefused(): void
    {
        $this->signInAs($this->userId);
        $invalid = ['error' => 'Invalid deck ID.'];

        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/account/favorite-decks', [])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/account/favorite-decks', ['deck_id' => 0])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->request('DELETE', '/account/favorite-decks/0')));
    }

    public function testADeckThatDoesNotExistCannotBeFavourited(): void
    {
        $this->signInAs($this->userId);

        $response = $this->post('/account/favorite-decks', ['deck_id' => 999]);

        $this->assertSame(['error' => 'Invalid deck ID.'], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('user_favorite_decks'));
    }

    public function testAPendingDeckIsRefusedLikeOneThatDoesNotExist(): void
    {
        $pending = $this->deck('Submitted Deck', approved: false);
        $this->signInAs($this->userId);

        $refused = $this->assertJsonResponse(400, $this->post('/account/favorite-decks', ['deck_id' => $pending]));
        $unknown = $this->assertJsonResponse(400, $this->post('/account/favorite-decks', ['deck_id' => $pending + 1]));

        $this->assertSame(['error' => 'Invalid deck ID.'], $refused);
        $this->assertSame($unknown, $refused);
        $this->assertSame(0, $this->rowCount('user_favorite_decks'));
    }

    private function deck(string $name, bool $approved = true): int
    {
        $system = $this->service(DeckSystemData::class)->store(['name' => "{$name} System", 'short_name' => 'S']);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => $name, 'deck_system_id' => $system->deck_system_id, 'approved' => $approved]);
        $this->assertNotNull($deck);
        return $deck->deck_id;
    }

    private function pathId(mixed $id): string
    {
        return is_int($id) || is_string($id) ? (string) $id : '';
    }
}
