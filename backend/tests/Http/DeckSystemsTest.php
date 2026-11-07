<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\DeckSystemController;
use Routes\Middleware\AdminAuth;
use Tarot\Data\DeckSystemData;
use Tarot\Repository\DeckSystemRepository;
use Tarot\Repository\UserRepository;

/**
 * The public deck-system catalogue and submissions to it: a signed-in visitor's
 * system waits for review (until then only an admin sees it), an admin's is
 * published at once, and every card in the system has to be named.
 */
#[CoversClass(DeckSystemController::class)]
#[CoversClass(AdminAuth::class)]
#[CoversClass(DeckSystemRepository::class)]
#[CoversClass(DeckSystemData::class)]
final class DeckSystemsTest extends HttpTestCase
{
    public function testTheCatalogueListsOnlyApprovedSystemsWithoutTheirSubmitter(): void
    {
        $maker = $this->createUser('maker@example.test');
        $systems = $this->service(DeckSystemData::class);
        $systems->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'approved' => true, 'submitted_by' => $maker->user_id]);
        $systems->store(['name' => 'Lenormand', 'short_name' => 'LEN', 'approved' => false, 'submitted_by' => $maker->user_id]);

        $response = $this->get('/deck-systems');

        $list = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/deck-systems', 200, $list);
        $this->assertSame(['Rider-Waite-Smith'], array_column($list, 'name'));
        $this->assertIsArray($list[0]);
        $this->assertArrayNotHasKey('submitted_by', $list[0]);
        $this->assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
    }

    public function testAnUnknownSystemIsNotFound(): void
    {
        $this->assertSame(['error' => 'Deck system not found'], $this->assertJsonResponse(404, $this->get('/deck-systems/999')));
    }

    public function testAPendingSystemLooksToAVisitorExactlyLikeOneThatDoesNotExist(): void
    {
        $pending = $this->storeSystem('Lenormand', approved: false);

        $unknown = $this->asSeenByANewBrowser('/deck-systems/999');
        $this->assertSame([404, '{"error":"Deck system not found"}', ''], array_slice($unknown, 0, 3));
        // Down to the session: the admin check starts one for both, or a cookie
        // would tell a pending id from a free one.
        $this->assertSame($unknown, $this->asSeenByANewBrowser("/deck-systems/{$pending}"));

        $this->signInAs($this->createUser('reader@example.test')->user_id);
        $this->assertSame(['error' => 'Deck system not found'], $this->assertJsonResponse(404, $this->get("/deck-systems/{$pending}")));
    }

    public function testAnApprovedSystemIsServedToAnyoneWithoutStartingASession(): void
    {
        $approved = $this->storeSystem('Rider-Waite-Smith', approved: true);

        [$status, , $cacheControl, $session] = $this->asSeenByANewBrowser("/deck-systems/{$approved}");

        $this->assertSame([200, 'public, max-age=300', false], [$status, $cacheControl, $session]);
    }

    public function testAnAdminSeesAPendingSystemWhichNoSharedCacheMayKeep(): void
    {
        $pending = $this->storeSystem('Lenormand', approved: false);
        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);

        $response = $this->get("/deck-systems/{$pending}");

        $system = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/deck-systems/{id}', 200, $system);
        $this->assertSame(['Lenormand', false, ['Rider', 'Clover']], [$system['name'], $system['approved'], array_column($system['cards'], 'name')]);
        $this->assertArrayNotHasKey('submitted_by', $system);
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));

        // The check is the admin routes' own, read from the database each time.
        $this->service(UserRepository::class)->setAdmin($admin->user_id, false);
        $this->assertJsonResponse(404, $this->get("/deck-systems/{$pending}"));
    }

    public function testSubmittingASystemNeedsASignedInAccount(): void
    {
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->post('/deck-systems', $this->submission('Lenormand', 2))));

        $gone = $this->createUser('gone@example.test');
        $this->signInAs($gone->user_id);
        $this->service(UserRepository::class)->delete($gone->user_id);
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->post('/deck-systems', $this->submission('Lenormand', 2))));

        $this->assertSame(0, $this->rowCount('deck_systems'));
    }

    public function testAVisitorsSystemWaitsForReviewWithItsCards(): void
    {
        $maker = $this->createUser('maker@example.test');
        $this->signInAs($maker->user_id);

        $response = $this->post('/deck-systems', [
            'name' => ' Lenormand ', 'short_name' => ' LEN ', 'total_cards' => 2, 'approved' => true,
            'cards' => [
                ['name' => ' Rider ', 'keywords' => 'news', 'card_id' => 40],
                ['name' => 'Clover', 'reversed_meaning' => 'Missed luck'],
            ],
        ]);

        $system = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/deck-systems', 201, $system);
        $this->assertSame(['Lenormand', 'LEN', 2, false, $maker->user_id], [
            $system['name'], $system['short_name'], $system['total_cards'], $system['approved'], $system['submitted_by'],
        ]);
        // Cards are numbered by their place in the list, whatever ids were sent.
        $this->assertSame([
            ['card_id' => 1, 'name' => 'Rider', 'keywords' => 'news', 'reversed_meaning' => null],
            ['card_id' => 2, 'name' => 'Clover', 'keywords' => null, 'reversed_meaning' => 'Missed luck'],
        ], $this->rows('SELECT card_id, name, keywords, reversed_meaning FROM deck_system_cards ORDER BY card_id'));
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/deck-systems')));
    }

    public function testAnAdminsSystemIsPublishedAtOnce(): void
    {
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $system = $this->assertJsonResponse(201, $this->post('/deck-systems', $this->submission('Lenormand', 2)));

        $this->assertTrue($system['approved']);
        $public = $this->assertJsonResponse(200, $this->get("/deck-systems/{$system['deck_system_id']}"));
        $this->assertSame(['Card 1', 'Card 2'], array_column($public['cards'], 'name'));
    }

    public function testCardsMayArriveAsAJsonString(): void
    {
        $this->signInAs($this->createUser('maker@example.test')->user_id);
        $submission = $this->submission('Lenormand', 2);
        $submission['cards'] = json_encode($submission['cards'], JSON_THROW_ON_ERROR);

        $this->assertJsonResponse(201, $this->post('/deck-systems', $submission));

        $this->assertSame(2, $this->rowCount('deck_system_cards'));
    }

    public function testASubmissionIsCheckedBeforeAnythingIsStored(): void
    {
        $this->signInAs($this->createUser('maker@example.test')->user_id);
        $valid = $this->submission('Lenormand', 2);

        $cases = [
            'Name and Short Name are required.' => ['short_name' => '  '] + $valid,
            'Total cards must be at least 1.' => ['total_cards' => 0] + $valid,
            'At least 3 card entries are required (name is mandatory for each).' => ['total_cards' => 3] + $valid,
            'Card #2 requires a name.' => ['cards' => [['name' => 'Rider'], ['name' => ' ']]] + $valid,
        ];
        foreach ($cases as $error => $submission) {
            $this->assertSame(['error' => $error], $this->assertJsonResponse(400, $this->post('/deck-systems', $submission)));
        }
        $this->assertSame(
            ['error' => 'At least 2 card entries are required (name is mandatory for each).'],
            $this->assertJsonResponse(400, $this->post('/deck-systems', ['cards' => 'not json'] + $valid))
        );

        $this->assertSame(0, $this->rowCount('deck_systems'));
        $this->assertSame(0, $this->rowCount('deck_system_cards'));
    }

    public function testASystemNameAlreadyTakenIsRefusedWithAReason(): void
    {
        $this->service(DeckSystemData::class)->store(['name' => 'Lenormand', 'short_name' => 'LEN']);
        $this->signInAs($this->createUser('maker@example.test')->user_id);

        $this->assertSame(
            ['error' => 'A deck system with that name already exists.'],
            $this->assertJsonResponse(409, $this->post('/deck-systems', $this->submission('Lenormand', 2)))
        );
        $this->assertSame(1, $this->rowCount('deck_systems'));
        $this->assertSame(0, $this->rowCount('deck_system_cards'));
    }

    /** A two-card system as a visitor submitted it; returns its id. */
    private function storeSystem(string $name, bool $approved): int
    {
        $systems = $this->service(DeckSystemData::class);
        $system = $systems->store([
            'name' => $name, 'short_name' => strtoupper(substr($name, 0, 3)), 'total_cards' => 2, 'approved' => $approved,
            'submitted_by' => $this->createUser(strtolower($name) . '@example.test')->user_id,
        ]);
        $this->assertNotNull($system);
        $systems->storeCards($system->deck_system_id, [['card_id' => 1, 'name' => 'Rider'], ['card_id' => 2, 'name' => 'Clover']]);

        return $system->deck_system_id;
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

    /** @return array{name:string,short_name:string,total_cards:int,cards:list<array{name:string}>} */
    private function submission(string $name, int $cards): array
    {
        return [
            'name' => $name,
            'short_name' => strtoupper(substr($name, 0, 3)),
            'total_cards' => $cards,
            'cards' => array_map(static fn(int $i): array => ['name' => "Card {$i}"], range(1, $cards)),
        ];
    }
}
