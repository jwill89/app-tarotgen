<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminDeckSystemController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Repository\DeckSystemRepository;
use Tarot\Structure\DeckSystem;

/**
 * Deck systems in the admin pages: the approved list and the review queue,
 * approval (which is what makes a submission public), editing a system and its
 * card list, and deletion.
 */
#[CoversClass(AdminDeckSystemController::class)]
#[CoversClass(DeckSystemRepository::class)]
#[CoversClass(DeckSystemData::class)]
#[CoversClass(DeckData::class)]
final class AdminDeckSystemsTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
    }

    public function testApprovingASubmittedSystemPublishesIt(): void
    {
        $this->system('Rider-Waite-Smith', approved: true);
        $submitter = $this->createUser('maker@example.test');
        $pending = $this->system('Lenormand', approved: false, submittedBy: $submitter->user_id);

        $queue = $this->assertJsonResponse(200, $this->get('/admin/deck-systems/pending'));
        $this->assertDocumented('get', '/admin/deck-systems/pending', 200, $queue);
        $this->assertSame(['Lenormand'], array_column($queue, 'name'));
        $this->assertSame([$submitter->user_id], array_column($queue, 'submitted_by'));
        $this->assertSame(['Rider-Waite-Smith'], array_column($this->assertJsonResponse(200, $this->get('/deck-systems')), 'name'));

        $approved = $this->assertJsonResponse(200, $this->post("/admin/deck-systems/{$pending->deck_system_id}/approve"));

        $this->assertDocumented('post', '/admin/deck-systems/{id}/approve', 200, $approved);
        $this->assertTrue($approved['approved']);
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/admin/deck-systems/pending')));
        $list = $this->assertJsonResponse(200, $this->get('/admin/deck-systems'));
        $this->assertDocumented('get', '/admin/deck-systems', 200, $list);
        $this->assertSame(['Lenormand', 'Rider-Waite-Smith'], array_column($list, 'name'));
        $this->assertSame(['Lenormand', 'Rider-Waite-Smith'], array_column($this->assertJsonResponse(200, $this->get('/deck-systems')), 'name'));
    }

    public function testOneSystemIsReadForTheEditorWithItsCardsAndWithoutACache(): void
    {
        $this->system('Rider-Waite-Smith', approved: true);
        $submitter = $this->createUser('maker@example.test');
        // The editor opens submissions too, before they are approved.
        $pending = $this->system('Lenormand', approved: false, submittedBy: $submitter->user_id);
        $this->service(DeckSystemData::class)->storeCards($pending->deck_system_id, [
            ['card_id' => 1, 'name' => 'Rider'], ['card_id' => 2, 'name' => 'Clover'],
        ]);

        $response = $this->get("/admin/deck-systems/{$pending->deck_system_id}");

        $read = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/admin/deck-systems/{id}', 200, $read);
        $this->assertSame(['Lenormand', 'LEN', false, $submitter->user_id], [$read['name'], $read['short_name'], $read['approved'], $read['submitted_by']]);
        $this->assertSame(['Rider', 'Clover'], array_column($read['cards'], 'name'));
        // The public GET /deck-systems/{id} is cached for five minutes; an editor must see the last save.
        $this->assertSame('', $response->getHeaderLine('Cache-Control'));
    }

    public function testASystemThatDoesNotExistIsNotFound(): void
    {
        $notFound = ['error' => 'Deck system not found'];

        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->get('/admin/deck-systems/999')));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->get('/admin/deck-systems/lenormand')));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->post('/admin/deck-systems/999/approve')));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PUT', '/admin/deck-systems/999', ['name' => 'Ghost'])));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('DELETE', '/admin/deck-systems/999')));
        $this->assertSame(0, $this->rowCount('deck_systems'));
    }

    public function testEditingASystemReplacesItsCardList(): void
    {
        $system = $this->system('Rider-Waite-Smith', approved: true);
        $this->service(DeckSystemData::class)->storeCards($system->deck_system_id, [
            ['card_id' => 1, 'name' => 'Fool'], ['card_id' => 2, 'name' => 'Magician'], ['card_id' => 3, 'name' => 'Priestess'],
        ]);

        $response = $this->request('PUT', "/admin/deck-systems/{$system->deck_system_id}", [
            'name' => 'RWS', 'short_name' => 'R', 'total_cards' => 2, 'submitted_by' => 7,
            'cards' => [
                ['name' => '  The Fool ', 'keywords' => 'beginnings'],
                ['card_id' => 2, 'name' => 'The Magician', 'reversed_meaning' => 'Trickery'],
                'not a card',
            ],
        ]);

        $updated = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('put', '/admin/deck-systems/{id}', 200, $updated);
        $this->assertSame(['RWS', 'R', 2, null], [$updated['name'], $updated['short_name'], $updated['total_cards'], $updated['submitted_by']]);
        $this->assertSame([
            ['card_id' => 1, 'name' => 'The Fool', 'keywords' => 'beginnings', 'reversed_meaning' => null],
            ['card_id' => 2, 'name' => 'The Magician', 'keywords' => null, 'reversed_meaning' => 'Trickery'],
        ], $this->rows('SELECT card_id, name, keywords, reversed_meaning FROM deck_system_cards ORDER BY card_id'));
    }

    public function testRenamingASystemToAnotherSystemsNameIsAConflict(): void
    {
        $this->system('Rider-Waite-Smith', approved: true);
        $system = $this->system('Lenormand', approved: true);
        $this->service(DeckSystemData::class)->storeCards($system->deck_system_id, [['card_id' => 1, 'name' => 'Rider']]);

        $response = $this->request('PUT', "/admin/deck-systems/{$system->deck_system_id}", [
            'name' => 'Rider-Waite-Smith', 'short_name' => 'X', 'cards' => [['card_id' => 1, 'name' => 'Renamed']],
        ]);

        // A duplicate name is refused with a 409, not a 500 from the UNIQUE constraint.
        $this->assertSame(['error' => 'A deck system with that name already exists'], $this->assertJsonResponse(409, $response));
        $this->assertSame(
            [['name' => 'Rider-Waite-Smith', 'short_name' => 'RID'], ['name' => 'Lenormand', 'short_name' => 'LEN']],
            $this->rows('SELECT name, short_name FROM deck_systems ORDER BY deck_system_id')
        );
        $this->assertSame(['Rider'], array_column($this->rows('SELECT name FROM deck_system_cards'), 'name'));
        // Its own name isn't another system's.
        $this->assertJsonResponse(200, $this->request('PUT', "/admin/deck-systems/{$system->deck_system_id}", ['name' => 'Lenormand']));
    }

    public function testARenameCannotBlankEitherName(): void
    {
        $system = $this->system('Lenormand', approved: true);
        $path = "/admin/deck-systems/{$system->deck_system_id}";

        $this->assertSame(['error' => 'Name is required'], $this->assertJsonResponse(400, $this->request('PUT', $path, ['name' => '  '])));
        $this->assertSame(['error' => 'Short name is required'], $this->assertJsonResponse(400, $this->request('PUT', $path, ['short_name' => ''])));
        $this->assertSame(
            [['name' => 'Lenormand', 'short_name' => 'LEN']],
            $this->rows('SELECT name, short_name FROM deck_systems')
        );
    }

    public function testEditingOnlyTheCardsKeepsTheSystemAsItWas(): void
    {
        $system = $this->system('Rider-Waite-Smith', approved: true);
        $this->service(DeckSystemData::class)->storeCards($system->deck_system_id, [['card_id' => 1, 'name' => 'Fool']]);

        $updated = $this->assertJsonResponse(200, $this->request('PUT', "/admin/deck-systems/{$system->deck_system_id}", [
            'cards' => [['card_id' => 1, 'name' => 'The Fool']],
        ]));

        $this->assertSame('Rider-Waite-Smith', $updated['name']);
        $this->assertSame(['The Fool'], array_column($this->rows('SELECT name FROM deck_system_cards'), 'name'));
    }

    public function testAnEmptyCardListLeavesTheCardsInPlace(): void
    {
        $system = $this->system('Rider-Waite-Smith', approved: true);
        $this->service(DeckSystemData::class)->storeCards($system->deck_system_id, [['card_id' => 1, 'name' => 'Fool']]);

        $this->assertJsonResponse(200, $this->request('PUT', "/admin/deck-systems/{$system->deck_system_id}", ['short_name' => 'R', 'cards' => []]));

        $this->assertSame(['Fool'], array_column($this->rows('SELECT name FROM deck_system_cards'), 'name'));
    }

    public function testDeletingASystemTakesItsCards(): void
    {
        $doomed = $this->system('Lenormand', approved: true);
        $kept = $this->system('Rider-Waite-Smith', approved: true);
        $data = $this->service(DeckSystemData::class);
        $data->storeCards($doomed->deck_system_id, [['card_id' => 1, 'name' => 'Rider']]);
        $data->storeCards($kept->deck_system_id, [['card_id' => 1, 'name' => 'Fool']]);

        $this->assertSame(204, $this->request('DELETE', "/admin/deck-systems/{$doomed->deck_system_id}")->getStatusCode());

        $this->assertSame([['deck_system_id' => $kept->deck_system_id, 'name' => 'Fool']], $this->rows('SELECT deck_system_id, name FROM deck_system_cards'));
        $this->assertSame(['Rider-Waite-Smith'], array_column($this->rows('SELECT name FROM deck_systems'), 'name'));
    }

    public function testDecksOfADeletedSystemStayReadable(): void
    {
        $system = $this->system('Lenormand', approved: true);
        $deck = $this->service(DeckData::class)->store(['name' => 'Petit', 'deck_system_id' => $system->deck_system_id, 'usable' => true]);
        $this->assertNotNull($deck);

        $this->assertSame(204, $this->request('DELETE', "/admin/deck-systems/{$system->deck_system_id}")->getStatusCode());

        // The deck keeps pointing at the missing system, and reads back with an empty
        // short name and no cards: the NULLs the empty join produces can't take every deck list down.
        $public = $this->assertJsonResponse(200, $this->get('/decks'));
        $this->assertDocumented('get', '/decks', 200, $public);
        $this->assertSame([['Petit', '', 0]], array_map(
            static fn(mixed $d): array => is_array($d) ? [$d['name'], $d['system_short_name'], $d['system_total_cards']] : [],
            $public
        ));
        $this->assertJsonResponse(200, $this->get('/admin/decks'));
    }

    private function system(string $name, bool $approved, ?int $submittedBy = null): DeckSystem
    {
        $system = $this->service(DeckSystemData::class)->store([
            'name' => $name, 'short_name' => strtoupper(substr($name, 0, 3)), 'approved' => $approved, 'submitted_by' => $submittedBy,
        ]);
        $this->assertNotNull($system);

        return $system;
    }
}
