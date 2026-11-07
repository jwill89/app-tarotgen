<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Routes\Internal\AdminDeckController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Repository\DeckRepository;
use Tarot\Service\CardImageService;
use Tarot\Structure\Deck;

/**
 * A deck's way through the admin pages: created or submitted, approved, made
 * usable (which is what puts it in the public selectors), edited, deleted.
 */
#[CoversClass(AdminDeckController::class)]
#[CoversClass(DeckRepository::class)]
#[CoversClass(DeckData::class)]
final class AdminDeckLifecycleTest extends HttpTestCase
{
    private int $systemId;

    /** Where the image service writes, instead of backend/assets/decks. */
    private string $imageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageRoot = sys_get_temp_dir() . '/tarot-admin-decks-' . bin2hex(random_bytes(4));
        mkdir($this->imageRoot);
        $this->replaceService(CardImageService::class, new CardImageService($this->imageRoot));

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'approved' => true]);
        $this->assertNotNull($system);
        $this->systemId = $system->deck_system_id;

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
    }

    protected function tearDown(): void
    {
        // Only folders: making a deck usable creates <deck_id>/thumbs and no files.
        $folders = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->imageRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($folders as $folder) {
            rmdir((string) $folder);
        }
        rmdir($this->imageRoot);

        parent::tearDown();
    }

    public function testACreatedDeckIsApprovedButNotYetOfferedToVisitors(): void
    {
        $response = $this->post('/admin/decks', [
            'name' => 'Thoth', 'artist' => 'Lady Frieda Harris', 'deck_system_id' => $this->systemId,
            // The admin can't skip the usable step by asking for it.
            'approved' => false, 'usable' => true,
        ]);

        $deck = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/admin/decks', 201, $deck);
        $this->assertSame(['Thoth', 'Lady Frieda Harris', true, false, 'RWS', null], [
            $deck['name'], $deck['artist'], $deck['approved'], $deck['usable'], $deck['system_short_name'], $deck['submitted_by'],
        ]);

        $this->assertSame(['Thoth'], array_column($this->assertJsonResponse(200, $this->get('/admin/decks')), 'name'));
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/decks')));
    }

    public function testADeckNameCanOnlyBeUsedOnce(): void
    {
        $this->assertJsonResponse(201, $this->post('/admin/decks', ['name' => 'Thoth']));

        $this->assertSame(['error' => 'A deck with that name already exists'], $this->assertJsonResponse(409, $this->post('/admin/decks', ['name' => 'Thoth'])));
        $this->assertSame(1, $this->rowCount('decks'));
    }

    public function testRenamingADeckToAnotherDecksNameIsAConflict(): void
    {
        $this->storeDeck('Thoth');
        $deck = $this->storeDeck('Marseille');

        $response = $this->request('PATCH', "/admin/decks/{$deck->deck_id}", ['name' => 'Thoth', 'artist' => 'Renamed']);

        $this->assertSame(['error' => 'A deck with that name already exists'], $this->assertJsonResponse(409, $response));
        $this->assertSame(
            [['name' => 'Thoth', 'artist' => 'Someone'], ['name' => 'Marseille', 'artist' => 'Someone']],
            $this->rows('SELECT name, artist FROM decks ORDER BY deck_id')
        );
        // Its own name isn't another deck's.
        $this->assertJsonResponse(200, $this->request('PATCH', "/admin/decks/{$deck->deck_id}", ['name' => 'Marseille']));
    }

    public function testADeckNeedsAName(): void
    {
        $required = ['error' => 'Name is required'];

        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/admin/decks', ['artist' => 'Harris'])));
        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/admin/decks', ['name' => '   '])));
        $this->assertSame(0, $this->rowCount('decks'));
    }

    public function testARenameCannotBlankTheName(): void
    {
        $deck = $this->storeDeck('Thoth');
        $required = ['error' => 'Name is required'];

        foreach (['', '   ', null] as $blank) {
            $response = $this->request('PATCH', "/admin/decks/{$deck->deck_id}", ['name' => $blank, 'artist' => 'Renamed']);
            $this->assertSame($required, $this->assertJsonResponse(400, $response), var_export($blank, true));
        }

        $this->assertSame([['name' => 'Thoth', 'artist' => 'Someone']], $this->rows('SELECT name, artist FROM decks'));
        // Leaving the name out changes the rest, as before.
        $this->assertSame('Renamed', $this->assertJsonResponse(200, $this->request('PATCH', "/admin/decks/{$deck->deck_id}", ['artist' => 'Renamed']))['artist']);
    }

    public function testApprovingASubmittedDeckMovesItOutOfThePendingQueue(): void
    {
        $submitter = $this->createUser('maker@example.test');
        $pending = $this->storeDeck('Wild Unknown', approved: false, submittedBy: $submitter->user_id);

        $queue = $this->assertJsonResponse(200, $this->get('/admin/decks/pending'));
        $this->assertDocumented('get', '/admin/decks/pending', 200, $queue);
        $this->assertSame([['Wild Unknown', $submitter->user_id]], array_map(
            static fn(mixed $d): array => is_array($d) ? [$d['name'], $d['submitted_by']] : [],
            $queue
        ));
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/admin/decks')));

        $approved = $this->assertJsonResponse(200, $this->post("/admin/decks/{$pending->deck_id}/approve"));

        $this->assertDocumented('post', '/admin/decks/{deck_id}/approve', 200, $approved);
        $this->assertTrue($approved['approved']);
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/admin/decks/pending')));
        $list = $this->assertJsonResponse(200, $this->get('/admin/decks'));
        $this->assertDocumented('get', '/admin/decks', 200, $list);
        $this->assertSame(['Wild Unknown'], array_column($list, 'name'));
        // Approval is a review step; visitors only see it once it's usable.
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/decks')));
    }

    public function testApprovingAnUnknownDeckIsNotFound(): void
    {
        $this->assertSame(['error' => 'Deck not found'], $this->assertJsonResponse(404, $this->post('/admin/decks/999/approve')));
    }

    public function testMakingADeckUsablePublishesItAndPreparesItsImageFolders(): void
    {
        $deck = $this->storeDeck('Thoth');

        $updated = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/decks/{$deck->deck_id}", ['usable' => true]));

        $this->assertDocumented('patch', '/admin/decks/{deck_id}', 200, $updated);
        $this->assertTrue($updated['usable']);
        $this->assertDirectoryExists("{$this->imageRoot}/{$deck->deck_id}/thumbs");
        $this->assertSame(['Thoth'], array_column($this->assertJsonResponse(200, $this->get('/decks')), 'name'));
    }

    public function testRetiringADeckLeavesItsImagesAlone(): void
    {
        $deck = $this->storeDeck('Thoth', usable: true);

        $updated = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/decks/{$deck->deck_id}", ['usable' => false]));

        $this->assertFalse($updated['usable']);
        $this->assertDirectoryDoesNotExist("{$this->imageRoot}/{$deck->deck_id}");
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/decks')));
    }

    public function testAnUpdateWritesOnlyTheEditableFields(): void
    {
        $deck = $this->storeDeck('Thoth');

        $updated = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/decks/{$deck->deck_id}", [
            'name' => 'Book of Thoth', 'artist' => 'Harris', 'card_aspect_w' => -3, 'additional_cards' => '2',
            'deck_id' => 500, 'submitted_by' => 42,
        ]));

        $this->assertSame(
            ['deck_id' => $deck->deck_id, 'name' => 'Book of Thoth', 'artist' => 'Harris', 'additional_cards' => 2, 'card_aspect_w' => 0.1, 'submitted_by' => null],
            array_intersect_key($updated, array_flip(['deck_id', 'name', 'artist', 'card_aspect_w', 'additional_cards', 'submitted_by']))
        );
    }

    public function testClearingAnArtistWithNullKeepsEveryDeckListReadable(): void
    {
        $deck = $this->storeDeck('Thoth', usable: true);

        // A NULL artist used to reach Deck's string property and fail every read.
        $updated = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/decks/{$deck->deck_id}", ['artist' => null, 'purchase_url' => null]));

        $this->assertSame(['', ''], [$updated['artist'], $updated['purchase_url']]);
        $this->assertSame(['Thoth'], array_column($this->assertJsonResponse(200, $this->get('/decks')), 'name'));
    }

    public function testAnUpdateWithNothingToChangeOrForNoDeckIsNotFound(): void
    {
        $deck = $this->storeDeck('Thoth');
        $notFound = ['error' => 'Deck not found or no valid fields'];

        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PATCH', "/admin/decks/{$deck->deck_id}", ['deck_id' => 7])));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PATCH', '/admin/decks/999', ['name' => 'Ghost'])));
        $this->assertSame([], $this->rows("SELECT 1 FROM decks WHERE name = 'Ghost'"));
    }

    public function testDeletingADeckThatIsAlreadyGoneStillSucceeds(): void
    {
        $deck = $this->storeDeck('Thoth');

        $this->assertSame(204, $this->request('DELETE', "/admin/decks/{$deck->deck_id}")->getStatusCode());
        $this->assertSame(204, $this->request('DELETE', "/admin/decks/{$deck->deck_id}")->getStatusCode());
        $this->assertSame(0, $this->rowCount('decks'));
    }

    public function testDeckAdministrationNeedsAnAdmin(): void
    {
        $deck = $this->storeDeck('Thoth', approved: false);
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $this->assertJsonResponse(401, $this->get('/admin/decks/pending'));
        $this->assertJsonResponse(401, $this->post('/admin/decks', ['name' => 'Mine']));
        $this->assertJsonResponse(401, $this->post("/admin/decks/{$deck->deck_id}/approve"));
        $this->assertJsonResponse(401, $this->request('DELETE', "/admin/decks/{$deck->deck_id}"));

        $this->assertSame([['name' => 'Thoth', 'approved' => 0]], $this->rows('SELECT name, approved FROM decks'));
    }

    private function storeDeck(string $name, bool $approved = true, bool $usable = false, ?int $submittedBy = null): Deck
    {
        $deck = $this->service(DeckData::class)->store([
            'name' => $name, 'artist' => 'Someone', 'deck_system_id' => $this->systemId,
            'approved' => $approved, 'usable' => $usable, 'submitted_by' => $submittedBy,
        ]);
        $this->assertNotNull($deck);

        return $deck;
    }
}
