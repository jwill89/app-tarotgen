<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminDeckSystemController;
use Routes\Internal\AdminSpreadController;
use Routes\Internal\DeckSystemController;
use Tarot\Data\DeckSystemData;
use Tarot\Data\PendingSpreadData;

/**
 * Responses that send everything the spec documents for them: a deck system
 * viewed with its cards (DeckSystemWithCards, not a bare DeckSystem) and the
 * pending spread queue (typed rows, not a list of "object"). The public views
 * also leave out the submitter, which the spec marks admin-only.
 */
#[CoversClass(DeckSystemController::class)]
#[CoversClass(AdminDeckSystemController::class)]
#[CoversClass(AdminSpreadController::class)]
final class CatalogPayloadsTest extends HttpTestCase
{
    public function testViewingADeckSystemSendsItsCards(): void
    {
        $submitter = $this->createUser('maker@example.test');
        $systems = $this->service(DeckSystemData::class);
        $system = $systems->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'approved' => true, 'submitted_by' => $submitter->user_id]);
        $this->assertNotNull($system);
        $systems->storeCards($system->deck_system_id, [
            ['card_id' => 1, 'name' => 'The Fool', 'keywords' => 'beginnings'],
            ['card_id' => 2, 'name' => 'The Magician'],
        ]);

        $public = $this->assertJsonResponse(200, $this->get("/deck-systems/{$system->deck_system_id}"));
        $this->assertDocumented('get', '/deck-systems/{id}', 200, $public);
        $this->assertArrayNotHasKey('submitted_by', $public);
        $this->assertSame(['The Fool', 'The Magician'], array_column($public['cards'], 'name'));

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
        $admin = $this->assertJsonResponse(200, $this->get("/admin/deck-systems/{$system->deck_system_id}"));
        $this->assertDocumented('get', '/admin/deck-systems/{id}', 200, $admin);
        $this->assertSame($submitter->user_id, $admin['submitted_by']);
        $this->assertCount(2, $admin['cards']);
    }

    public function testThePendingSpreadQueueSendsWhatItDocuments(): void
    {
        $this->service(PendingSpreadData::class)->store([
            'name' => 'Crossroads',
            'positions' => [['order' => 1, 'title' => 'Here', 'x' => 50, 'y' => 50, 'rotation' => 0]],
        ]);
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $queue = $this->assertJsonResponse(200, $this->get('/admin/pending-spreads'));

        $this->assertDocumented('get', '/admin/pending-spreads', 200, $queue);
        $this->assertSame(['Crossroads'], array_column($queue, 'name'));
    }
}
