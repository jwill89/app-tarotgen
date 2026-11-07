<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AccountController;
use Routes\Internal\ReadingController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\ReadingData;
use Tarot\Repository\ReadingRepository;
use Tarot\Service\PluginTokenService;
use Tarot\Service\ReadingInput;
use Tarot\Service\ReadingService;
use Tarot\Structure\Reading;
use Tarot\Utility\Clock;

/**
 * Who can open a reading (AUDIT §9.9): anyone with the link (the default),
 * anyone with its password, or — private — only its owner, in the browser or
 * through their linked plugin; everyone else gets the same 404 as for a reading
 * that doesn't exist, on every reading route. And a guest's chosen expiry: past
 * it, the reading is gone for everyone before the nightly run deletes it.
 */
#[CoversClass(ReadingController::class)]
#[CoversClass(AccountController::class)]
#[CoversClass(ReadingService::class)]
#[CoversClass(ReadingInput::class)]
#[CoversClass(ReadingRepository::class)]
#[CoversClass(ReadingData::class)]
#[CoversClass(Reading::class)]
final class ReadingPrivacyTest extends HttpTestCase
{
    private const array UNKNOWN = ['error' => 'InvalidReadingID'];
    private const array NOT_FOUND = ['error' => 'Reading not found.'];

    private int $deckId;

    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Little Arcana', 'short_name' => 'LA', 'total_cards' => 5]);
        $this->assertNotNull($system);
        // A custom reading needs a named card; generated draws don't.
        $this->service(DeckSystemData::class)->storeCard(['deck_system_id' => $system->deck_system_id, 'card_id' => 1, 'name' => 'The Magician']);
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
        $this->ownerId = $this->createUser('owner@example.test')->user_id;
    }

    public function testOnlyTheOwnerCanOpenAPrivateReading(): void
    {
        $id = $this->privateReading();

        $mine = $this->assertJsonResponse(200, $this->get("/readings/{$id}"));
        $this->assertDocumented('get', '/readings/{reading_id}', 200, $mine);
        $this->assertSame('private', $mine['visibility']);
        $this->assertTrue($mine['is_owner']);

        // A guest, and another account: exactly as if there were no such reading.
        $this->useSessionCookie(null);
        $this->assertSame(self::UNKNOWN, $this->assertJsonResponse(404, $this->get("/readings/{$id}")));
        $this->assertSame(self::UNKNOWN, $this->assertJsonResponse(404, $this->post("/readings/{$id}/unlock", ['password' => 'anything'])));
        $this->signInAs($this->createUser('other@example.test')->user_id);
        $this->assertSame(self::UNKNOWN, $this->assertJsonResponse(404, $this->get("/readings/{$id}")));
        $this->assertSame(self::UNKNOWN, $this->assertJsonResponse(404, $this->get('/readings/0000000000')));
    }

    public function testTheOwnersLinkedPluginCanOpenItAndNoOtherTokenCan(): void
    {
        $id = $this->privateReading();
        $this->useSessionCookie(null);
        $tokens = $this->service(PluginTokenService::class);

        $mine = ['Authorization' => 'Bearer ' . $tokens->issueToken($this->ownerId)];
        $viewed = $this->assertJsonResponse(200, $this->get("/readings/{$id}", $mine));
        $this->assertTrue($viewed['is_owner']);
        $this->assertSame([$id], array_column($this->assertJsonResponse(200, $this->get('/account/readings', $mine)), 'reading_id'));

        $theirs = ['Authorization' => 'Bearer ' . $tokens->issueToken($this->createUser('other@example.test')->user_id)];
        $this->assertSame(self::UNKNOWN, $this->assertJsonResponse(404, $this->get("/readings/{$id}", $theirs)));
        $this->assertSame(self::NOT_FOUND, $this->assertJsonResponse(404, $this->post("/readings/{$id}/finalize", [], $theirs)));
    }

    public function testNoOneElseCanChangeAPrivateReadingOrLearnItExists(): void
    {
        $id = $this->privateReading();
        $this->signInAs($this->createUser('other@example.test')->user_id);

        // Not "you don't own this" (403), which would say it's there.
        $this->assertSame(self::NOT_FOUND, $this->assertJsonResponse(404, $this->post("/readings/{$id}/draw")));
        $this->assertSame(self::NOT_FOUND, $this->assertJsonResponse(404, $this->post("/readings/{$id}/finalize")));
        $placement = $this->request('PUT', "/readings/{$id}/placement", ['positions' => [['title' => 'Here', 'x' => 50, 'y' => 50]]]);
        $this->assertSame(self::NOT_FOUND, $this->assertJsonResponse(404, $placement));
        $this->assertSame(self::NOT_FOUND, $this->assertJsonResponse(404, $this->request('PATCH', "/account/readings/{$id}", ['visibility' => 'link'])));

        // The owner still can.
        $this->signInAs($this->ownerId);
        $this->assertJsonResponse(200, $this->post("/readings/{$id}/draw"));
        $this->assertTrue($this->assertJsonResponse(200, $this->post("/readings/{$id}/finalize"))['is_final']);
    }

    public function testPrivacyIsChosenWhenDrawingAndChangedLater(): void
    {
        $this->signInAs($this->ownerId);
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId]));
        $this->assertSame('link', $created['visibility']);
        $id = $created['reading_id'];
        $this->assertIsString($id);

        // Private: the link stops working for anyone else.
        $private = $this->assertJsonResponse(200, $this->request('PATCH', "/account/readings/{$id}", ['visibility' => 'private']));
        $this->assertDocumented('patch', '/account/readings/{reading_id}', 200, $private);
        $this->assertSame('private', $private['visibility']);
        $this->assertSame([['is_private' => 1, 'password_hash' => null]], $this->rows("SELECT is_private, password_hash FROM readings WHERE reading_id = '{$id}'"));
        $this->assertSame(404, $this->asGuest(fn() => $this->get("/readings/{$id}"))->getStatusCode());

        // Password: a password is needed, then it locks rather than hides.
        $refused = $this->request('PATCH', "/account/readings/{$id}", ['visibility' => 'password']);
        $this->assertSame(['error' => 'Choose a password of at least 4 characters for this reading.'], $this->assertJsonResponse(422, $refused));
        $locked = $this->assertJsonResponse(200, $this->request('PATCH', "/account/readings/{$id}", ['visibility' => 'password', 'password' => 'abcd']));
        $this->assertSame(['password', true], [$locked['visibility'], $locked['password_protected']]);
        $this->assertTrue($this->assertJsonResponse(200, $this->asGuest(fn() => $this->get("/readings/{$id}")))['locked']);
        // Choosing Password again keeps the one it has.
        $kept = $this->assertJsonResponse(200, $this->request('PATCH', "/account/readings/{$id}", ['visibility' => 'password', 'reading_name' => 'Renamed']));
        $this->assertSame('password', $kept['visibility']);

        // Private, then back to the link: either drops the password for good.
        $this->assertJsonResponse(200, $this->request('PATCH', "/account/readings/{$id}", ['visibility' => 'private']));
        $open = $this->assertJsonResponse(200, $this->request('PATCH', "/account/readings/{$id}", ['visibility' => 'link']));
        $this->assertSame(['link', false], [$open['visibility'], $open['password_protected']]);
        $this->assertSame([['is_private' => 0, 'password_hash' => null]], $this->rows("SELECT is_private, password_hash FROM readings WHERE reading_id = '{$id}'"));
        $this->assertFalse($this->assertJsonResponse(200, $this->asGuest(fn() => $this->get("/readings/{$id}")))['locked']);

        // A password sent without visibility still means Password, as it always did.
        $legacy = $this->assertJsonResponse(200, $this->request('PATCH', "/account/readings/{$id}", ['password' => 'efgh']));
        $this->assertSame('password', $legacy['visibility']);
    }

    public function testAPrivateReadingDrawnWithAPasswordKeepsNoPassword(): void
    {
        $this->signInAs($this->ownerId);
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'visibility' => 'private', 'password' => 'abcd',
        ]));

        $this->assertSame('private', $created['visibility']);
        $this->assertSame([['is_private' => 1, 'password_hash' => null]], $this->rows('SELECT is_private, password_hash FROM readings'));
    }

    public function testAPasswordReadingDrawnWithoutAPasswordIsRefused(): void
    {
        $this->signInAs($this->ownerId);

        $response = $this->post('/readings/generate', ['deck_id' => $this->deckId, 'visibility' => 'password']);

        $this->assertSame(['error' => 'Choose a password of at least 4 characters for this reading.'], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testAnUnknownVisibilityIsRefused(): void
    {
        $this->signInAs($this->ownerId);
        $refused = ['error' => 'Choose who can open the reading: link, password or private.'];

        $this->assertSame($refused, $this->assertJsonResponse(400, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'visibility' => 'friends'])));
        $id = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId]))['reading_id'];
        $this->assertIsString($id);
        $this->assertSame($refused, $this->assertJsonResponse(400, $this->request('PATCH', "/account/readings/{$id}", ['visibility' => 'public'])));
    }

    public function testAGuestCannotMakeAReadingPrivate(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'visibility' => 'private']));

        $this->assertSame('link', $created['visibility']);
        $this->assertSame(200, $this->get("/readings/{$created['reading_id']}")->getStatusCode());
    }

    public function testAGuestCanHaveTheirReadingDeletedSooner(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'expires_in_days' => '7']));

        $this->assertDocumented('post', '/readings/generate', 201, $created);
        $this->assertIsString($created['expires_at']);
        $expires = strtotime($created['expires_at'] . ' UTC');
        $this->assertEqualsWithDelta(time() + 7 * 86400, $expires, 5);
        $this->assertSame($created['expires_at'], $this->assertJsonResponse(200, $this->get("/readings/{$created['reading_id']}"))['expires_at']);

        // A custom reading takes it too.
        $custom = $this->assertJsonResponse(201, $this->post('/readings', [
            'deck_id' => $this->deckId, 'cards' => [['card_id' => 1]], 'expires_in_days' => 1,
        ]));
        $this->assertIsString($custom['expires_at']);
        $this->assertEqualsWithDelta(time() + 86400, strtotime($custom['expires_at'] . ' UTC'), 5);
    }

    public function testWithoutAChoiceAGuestReadingKeepsTheUsualRetention(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId]));

        $this->assertNull($created['expires_at']);
    }

    public function testOnlyOneSevenOrThirtyDaysCanBeChosen(): void
    {
        foreach (['2', 0, -1, 'forever', '7.5'] as $days) {
            $response = $this->post('/readings/generate', ['deck_id' => $this->deckId, 'expires_in_days' => $days]);
            $this->assertSame(
                ['error' => 'A reading made without an account can be kept for 1, 7 or 30 days, or the usual year.'],
                $this->assertJsonResponse(400, $response),
                (string) $days
            );
        }
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testASignedInReadingNeverExpires(): void
    {
        $this->signInAs($this->ownerId);

        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'expires_in_days' => 1]));

        $this->assertNull($created['expires_at']);
        $this->assertSame([['expires_at' => null]], $this->rows('SELECT expires_at FROM readings'));
    }

    public function testAReadingPastItsExpiryIsGoneForEveryone(): void
    {
        $id = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'expires_in_days' => 1]))['reading_id'];
        $this->assertIsString($id);
        $this->db->exec("UPDATE readings SET expires_at = '" . Clock::in(-1) . "' WHERE reading_id = '{$id}'");

        // Still stored until the nightly run, but no route will find it.
        $this->assertSame(1, $this->rowCount('readings'));
        $this->assertSame(self::UNKNOWN, $this->assertJsonResponse(404, $this->get("/readings/{$id}")));
        $this->assertSame(self::UNKNOWN, $this->assertJsonResponse(404, $this->post("/readings/{$id}/unlock")));
        $placement = $this->request('PUT', "/readings/{$id}/placement", ['positions' => [['title' => 'Here', 'x' => 50, 'y' => 50]]]);
        $this->assertSame(self::NOT_FOUND, $this->assertJsonResponse(404, $placement));
    }

    /** A private reading made by the owner, who stays signed in. */
    private function privateReading(): string
    {
        $this->signInAs($this->ownerId);
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'visibility' => 'private']));
        $this->assertDocumented('post', '/readings/generate', 201, $created);
        $this->assertSame('private', $created['visibility']);
        $this->assertIsString($created['reading_id']);

        return $created['reading_id'];
    }

    /**
     * Run $request as a browser with no session, then carry on as before.
     *
     * @template T
     * @param callable(): T $request
     * @return T
     */
    private function asGuest(callable $request): mixed
    {
        $cookie = $this->sessionCookie();
        $this->useSessionCookie(null);
        try {
            return $request();
        } finally {
            $this->useSessionCookie($cookie);
        }
    }
}
