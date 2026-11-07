<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\DeckController;
use Routes\Middleware\AdminAuth;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\SpecialCardData;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\UserRepository;
use Tarot\Structure\Deck;

/**
 * The public deck catalogue — only usable decks are offered — each deck's card
 * list for the custom-reading picker, and deck submissions from signed-in
 * visitors, which wait for review: until approved, only an admin sees one.
 */
#[CoversClass(DeckController::class)]
#[CoversClass(AdminAuth::class)]
#[CoversClass(DeckRepository::class)]
#[CoversClass(DeckData::class)]
final class DecksTest extends HttpTestCase
{
    private int $systemId;

    protected function setUp(): void
    {
        parent::setUp();

        $systems = $this->service(DeckSystemData::class);
        $system = $systems->store(['name' => 'Mini', 'short_name' => 'MINI', 'total_cards' => 3, 'approved' => true]);
        $this->assertNotNull($system);
        $systems->storeCards($system->deck_system_id, [
            ['card_id' => 1, 'name' => 'The Fool'], ['card_id' => 2, 'name' => 'The Magician'], ['card_id' => 3, 'name' => 'The Priestess'],
        ]);
        $this->systemId = $system->deck_system_id;
    }

    public function testTheCatalogueOffersOnlyUsableDecksWithoutTheirSubmitter(): void
    {
        $maker = $this->createUser('maker@example.test');
        $this->deck('Thoth', usable: true, submittedBy: $maker->user_id);
        $this->deck('Retired', usable: false);
        $this->deck('Submitted', approved: false, usable: false, submittedBy: $maker->user_id);

        $response = $this->get('/decks');

        $decks = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/decks', 200, $decks);
        $this->assertSame(['Thoth'], array_column($decks, 'name'));
        $this->assertIsArray($decks[0]);
        $this->assertArrayNotHasKey('submitted_by', $decks[0]);
        $this->assertSame('MINI', $decks[0]['system_short_name']);
        $this->assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
    }

    public function testASingleDeckIsServedWithoutItsSubmitter(): void
    {
        $deck = $this->deck('Thoth', usable: true, submittedBy: $this->createUser('maker@example.test')->user_id);

        $body = $this->assertJsonResponse(200, $this->get("/decks/{$deck->deck_id}"));

        $this->assertDocumented('get', '/decks/{deck_id}', 200, $body);
        $this->assertSame(['Thoth', 3], [$body['name'], $body['system_total_cards']]);
        $this->assertArrayNotHasKey('submitted_by', $body);
    }

    public function testAnUnknownDeckIsNotFoundAndNotCached(): void
    {
        $response = $this->get('/decks/999');

        $this->assertSame(['error' => 'InvalidDeckID'], $this->assertJsonResponse(404, $response));
        $this->assertSame('', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(['error' => 'InvalidDeckID'], $this->assertJsonResponse(404, $this->get('/decks/999/cards')));
    }

    public function testAPendingDeckLooksToAVisitorExactlyLikeOneThatDoesNotExist(): void
    {
        $pending = $this->deck('Submitted', approved: false, submittedBy: $this->createUser('maker@example.test')->user_id);

        foreach (['', '/cards'] as $suffix) {
            $unknown = $this->asSeenByANewBrowser("/decks/999{$suffix}");
            $this->assertSame([404, '{"error":"InvalidDeckID"}', ''], array_slice($unknown, 0, 3));
            // Down to the session: the admin check starts one for both, or a
            // cookie would tell a pending id from a free one.
            $this->assertSame($unknown, $this->asSeenByANewBrowser("/decks/{$pending->deck_id}{$suffix}"), $suffix);
        }

        $this->signInAs($this->createUser('reader@example.test')->user_id);
        foreach (['', '/cards'] as $suffix) {
            $this->assertSame(['error' => 'InvalidDeckID'], $this->assertJsonResponse(404, $this->get("/decks/{$pending->deck_id}{$suffix}")));
        }
    }

    public function testAnApprovedDeckIsServedToAnyoneWithoutStartingASession(): void
    {
        // Retired (approved, no longer usable) decks stay readable: readings made
        // with them still name them.
        $retired = $this->deck('Retired', usable: false);

        foreach (['', '/cards'] as $suffix) {
            [$status, , $cacheControl, $session] = $this->asSeenByANewBrowser("/decks/{$retired->deck_id}{$suffix}");
            $this->assertSame([200, 'public, max-age=300', false], [$status, $cacheControl, $session], $suffix);
        }
    }

    public function testAnAdminSeesAPendingDeckWhichNoSharedCacheMayKeep(): void
    {
        $pending = $this->deck('Submitted', approved: false, submittedBy: $this->createUser('maker@example.test')->user_id);
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $response = $this->get("/decks/{$pending->deck_id}");

        $deck = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/decks/{deck_id}', 200, $deck);
        $this->assertSame(['Submitted', false], [$deck['name'], $deck['approved']]);
        $this->assertArrayNotHasKey('submitted_by', $deck);
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));

        $cards = $this->get("/decks/{$pending->deck_id}/cards");
        $this->assertSame(['The Fool', 'The Magician', 'The Priestess'], array_column($this->assertJsonResponse(200, $cards), 'name'));
        $this->assertSame('private, no-store', $cards->getHeaderLine('Cache-Control'));
    }

    public function testAdminIsCheckedInTheDatabaseNotTakenFromTheSession(): void
    {
        $pending = $this->deck('Submitted', approved: false);
        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);
        $this->assertJsonResponse(200, $this->get("/decks/{$pending->deck_id}"));

        // Revoked mid-session: the same session no longer sees it.
        $this->service(UserRepository::class)->setAdmin($admin->user_id, false);

        $this->assertJsonResponse(404, $this->get("/decks/{$pending->deck_id}"));
    }

    public function testADecksCardListHasItsSystemsCardsThenItsExtras(): void
    {
        $deck = $this->deck('Thoth', usable: true, additionalCards: 2);
        // Only one of the two extra slots has been filled in.
        $this->service(SpecialCardData::class)->store(['deck_id' => $deck->deck_id, 'card_id' => 4, 'name' => 'The Artist']);

        $response = $this->get("/decks/{$deck->deck_id}/cards");

        $cards = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/decks/{deck_id}/cards', 200, $cards);
        $this->assertSame([
            ['card_id' => 1, 'name' => 'The Fool'],
            ['card_id' => 2, 'name' => 'The Magician'],
            ['card_id' => 3, 'name' => 'The Priestess'],
            ['card_id' => 4, 'name' => 'The Artist'],
        ], $cards);
        $this->assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
    }

    public function testSubmittingADeckNeedsASignedInAccount(): void
    {
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->post('/decks', ['name' => 'Mine', 'artist' => 'Me'])));
        $this->assertSame(0, $this->rowCount('decks'));
    }

    public function testADeletedAccountsLeftoverSessionCannotSubmit(): void
    {
        $gone = $this->createUser('gone@example.test');
        $this->signInAs($gone->user_id);
        $this->service(UserRepository::class)->delete($gone->user_id);

        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->post('/decks', ['name' => 'Spam', 'artist' => 'Spammer'])));
        $this->assertSame(0, $this->rowCount('decks'));
    }

    public function testASubmittedDeckWaitsForReview(): void
    {
        $maker = $this->createUser('maker@example.test');
        $this->signInAs($maker->user_id);

        $response = $this->post('/decks', [
            'name' => ' Wild Unknown ', 'artist' => ' Kim Krans ', 'purchase_url' => ' https://example.test/buy ',
            'deck_system_id' => $this->systemId, 'additional_cards' => '1', 'approved' => true, 'usable' => true,
        ]);

        $deck = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/decks', 201, $deck);
        $this->assertSame(
            ['Wild Unknown', 'Kim Krans', 'https://example.test/buy', 1, false, false, $maker->user_id],
            [$deck['name'], $deck['artist'], $deck['purchase_url'], $deck['additional_cards'], $deck['approved'], $deck['usable'], $deck['submitted_by']]
        );
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/decks')));

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
        $this->assertSame(['Wild Unknown'], array_column($this->assertJsonResponse(200, $this->get('/admin/decks/pending')), 'name'));
    }

    public function testAnAdminsSubmissionAlsoWaitsForReview(): void
    {
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $deck = $this->assertJsonResponse(201, $this->post('/decks', ['name' => 'Thoth', 'artist' => 'Harris']));

        $this->assertFalse($deck['approved']);
    }

    public function testADeckNeedsANameAndAnArtist(): void
    {
        $this->signInAs($this->createUser('maker@example.test')->user_id);
        $required = ['error' => 'Name and Artist are required.'];

        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/decks', ['name' => 'Thoth'])));
        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/decks', ['name' => '  ', 'artist' => 'Harris'])));
        $this->assertSame(0, $this->rowCount('decks'));
    }

    public function testADeckNameAlreadyTakenIsRefusedWithAReason(): void
    {
        $this->deck('Thoth', usable: true);
        $this->signInAs($this->createUser('maker@example.test')->user_id);

        $this->assertSame(
            ['error' => 'A deck with that name already exists.'],
            $this->assertJsonResponse(409, $this->post('/decks', ['name' => 'Thoth', 'artist' => 'Someone else']))
        );
        $this->assertSame(1, $this->rowCount('decks'));
    }

    /**
     * What a browser with no session cookie learns from a request: the status,
     * body and caching, and whether it was handed a session.
     *
     * @return array{int,string,string,bool}
     */
    private function asSeenByANewBrowser(string $path): array
    {
        $this->useSessionCookie(null);
        $response = $this->get($path);

        return [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('Cache-Control'), $this->sessionCookie() !== null];
    }

    private function deck(string $name, bool $approved = true, bool $usable = false, ?int $submittedBy = null, int $additionalCards = 0): Deck
    {
        $deck = $this->service(DeckData::class)->store([
            'name' => $name, 'deck_system_id' => $this->systemId, 'approved' => $approved, 'usable' => $usable,
            'submitted_by' => $submittedBy, 'additional_cards' => $additionalCards,
        ]);
        $this->assertNotNull($deck);

        return $deck;
    }
}
