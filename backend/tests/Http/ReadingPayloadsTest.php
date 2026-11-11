<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\ReadingController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;

/**
 * The reading endpoints answer with exactly what the committed API spec documents
 * for them, not the stored-row schema; this reads each endpoint's documented
 * response from openapi.json and compares the real one with it, nested objects
 * included.
 */
#[CoversClass(ReadingController::class)]
final class ReadingPayloadsTest extends HttpTestCase
{
    private int $deckId;

    protected function setUp(): void
    {
        parent::setUp();

        $systems = $this->service(DeckSystemData::class);
        $system = $systems->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS']);
        $this->assertNotNull($system);
        // Only card 1 is named, so generated draws mostly come back without a card_name.
        $systems->storeCard(['deck_system_id' => $system->deck_system_id, 'card_id' => 1, 'name' => 'The Magician']);
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
    }

    public function testGenerateAndViewSendWhatTheyDocument(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'number_of_cards' => 3]));
        $this->assertDocumented('post', '/readings/generate', 201, $created);
        $this->assertIsString($created['reading_id']);

        $viewed = $this->assertJsonResponse(200, $this->get('/readings/' . $created['reading_id']));
        $this->assertDocumented('get', '/readings/{reading_id}', 200, $viewed);
    }

    public function testAPasswordedReadingIsALockedStubUntilUnlocked(): void
    {
        $owner = $this->createUser('owner@example.test');
        $this->signInAs($owner->user_id);
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'number_of_cards' => 1, 'reading_name' => 'Private', 'password' => 'open sesame',
        ]));
        $this->assertTrue($created['is_owner']);
        $id = $created['reading_id'];
        $this->assertIsString($id);

        $this->useSessionCookie(null);
        $locked = $this->assertJsonResponse(200, $this->get("/readings/{$id}"));
        $this->assertSame(['locked' => true, 'reading_name' => 'Private'], $locked);
        $this->assertDocumented('get', '/readings/{reading_id}', 200, $locked);

        $unlocked = $this->assertJsonResponse(200, $this->post("/readings/{$id}/unlock", ['password' => 'open sesame']));
        $this->assertDocumented('post', '/readings/{reading_id}/unlock', 200, $unlocked);
        $this->assertFalse($unlocked['is_owner']);
    }

    public function testDrawingMoreAndFinalizingSendWhatTheyDocument(): void
    {
        $owner = $this->createUser('owner@example.test');
        $this->signInAs($owner->user_id);
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'number_of_cards' => 1]));
        $id = $created['reading_id'];
        $this->assertIsString($id);

        $drawn = $this->assertJsonResponse(200, $this->post("/readings/{$id}/draw", ['count' => 2]));
        $this->assertDocumented('post', '/readings/{reading_id}/draw', 200, $drawn);

        $final = $this->assertJsonResponse(200, $this->post("/readings/{$id}/finalize"));
        $this->assertDocumented('post', '/readings/{reading_id}/finalize', 200, $final);
    }

    public function testACustomReadingSendsWhatItDocuments(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings', [
            'deck_id' => $this->deckId,
            'name' => 'Recreated',
            'cards' => [['card_id' => 1, 'reversed' => true, 'title' => 'Past', 'x' => 20, 'y' => 50, 'rotation' => 0]],
        ]));

        $this->assertDocumented('post', '/readings', 201, $created);
    }
}
