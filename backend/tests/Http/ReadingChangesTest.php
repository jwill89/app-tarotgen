<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Routes\Internal\ReadingController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\SpreadData;
use Tarot\Repository\ReadingRepository;
use Tarot\Service\PluginTokenService;
use Tarot\Service\ReadingService;

/**
 * Changing a reading after it's made: drawing more cards and finalizing (owner
 * only), and placing its cards on the canvas (the owner, or anyone holding a
 * guest reading's link). A final reading can't be changed at all.
 */
#[CoversClass(ReadingController::class)]
#[CoversClass(ReadingService::class)]
#[CoversClass(ReadingRepository::class)]
final class ReadingChangesTest extends HttpTestCase
{
    private const int DECK_SIZE = 5;

    private int $deckId;

    protected function setUp(): void
    {
        parent::setUp();

        $systems = $this->service(DeckSystemData::class);
        $system = $systems->store(['name' => 'Little Arcana', 'short_name' => 'LA', 'total_cards' => self::DECK_SIZE]);
        $this->assertNotNull($system);
        // A custom reading needs a named card; generated draws don't.
        $systems->storeCard(['deck_system_id' => $system->deck_system_id, 'card_id' => 1, 'name' => 'The Magician']);
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
    }

    public function testTheOwnerDrawsMoreCardsWithoutRepeatingAny(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $id = $this->generate(2);
        $before = $this->storedDraw($id);

        $drawn = $this->assertJsonResponse(200, $this->post("/readings/{$id}/draw", ['count' => 2]));

        $this->assertTrue($drawn['can_draw_more']);
        $after = $this->storedDraw($id);
        $this->assertCount(4, $after);
        // The new cards go on the end; the first two stay where they were.
        $this->assertSame($before, array_slice($after, 0, 2));
        $this->assertCount(4, array_unique(array_column($after, 'card_id')));
    }

    public function testDrawingStopsWhenTheDeckRunsOut(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $id = $this->generate(2);

        $this->assertJsonResponse(200, $this->post("/readings/{$id}/draw", ['count' => 50]));
        $this->assertCount(self::DECK_SIZE, $this->storedDraw($id));

        $exhausted = $this->post("/readings/{$id}/draw");
        $this->assertSame(['error' => 'No more cards are left to draw from this deck.'], $this->assertJsonResponse(409, $exhausted));
    }

    public function testTheDecksExtraCardsCanBeDrawnIntoAReadingWhenAskedFor(): void
    {
        $this->service(DeckData::class)->update($this->deckId, ['additional_cards' => 2]);
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $id = $this->generate(self::DECK_SIZE);

        $this->assertJsonResponse(409, $this->post("/readings/{$id}/draw"));
        $this->assertJsonResponse(200, $this->post("/readings/{$id}/draw", ['count' => 5, 'use_additional_cards' => 'true']));

        $ids = array_column($this->storedDraw($id), 'card_id');
        sort($ids);
        $this->assertSame(range(1, self::DECK_SIZE + 2), $ids);
    }

    public function testTheOwnerCanStillDrawMoreAfterTheDeckLosesItsApproval(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $id = $this->generate(2);
        $this->service(DeckData::class)->update($this->deckId, ['approved' => false]);

        // The reading is already theirs, and its deck id probes nothing.
        $this->assertJsonResponse(200, $this->post("/readings/{$id}/draw"));
        $this->assertCount(3, $this->storedDraw($id));
        // A new reading from the deck is refused, as for any visitor.
        $this->assertSame(['error' => 'InvalidDeckID'], $this->assertJsonResponse(400, $this->post('/readings/generate', ['deck_id' => $this->deckId])));
    }

    public function testDrawingFromADeckThatHasSinceBeenDeletedIsRefused(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $id = $this->generate(1);
        $this->service(DeckData::class)->delete($this->deckId);

        $response = $this->post("/readings/{$id}/draw");

        $this->assertSame(['error' => 'InvalidDeckID'], $this->assertJsonResponse(400, $response));
        $this->assertCount(1, $this->storedDraw($id));
    }

    public function testALinkedPluginActsForItsUser(): void
    {
        $owner = $this->createUser('owner@example.test');
        $bearer = ['Authorization' => 'Bearer ' . $this->service(PluginTokenService::class)->issueToken($owner->user_id)];

        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'password' => 'abcd'], $bearer));
        $this->assertTrue($created['is_owner']);
        $id = $created['reading_id'];
        $this->assertIsString($id);
        $this->assertSame([['user_id' => $owner->user_id]], $this->rows('SELECT user_id FROM readings'));

        // Its user's own reading: no password needed, and the plugin may finish it.
        $this->assertFalse($this->assertJsonResponse(200, $this->get("/readings/{$id}", $bearer))['locked']);
        $this->assertJsonResponse(200, $this->post("/readings/{$id}/draw", [], $bearer));
        $this->assertTrue($this->assertJsonResponse(200, $this->post("/readings/{$id}/finalize", [], $bearer))['is_final']);

        $this->signInAs($this->createUser('other@example.test')->user_id);
        $theirs = $this->generate(1);
        $this->useSessionCookie(null);
        $this->assertFalse($this->assertJsonResponse(200, $this->get("/readings/{$theirs}", $bearer))['is_owner']);
        $this->assertJsonResponse(403, $this->post("/readings/{$theirs}/finalize", [], $bearer));
    }

    public function testOnlyTheOwnerCanDrawMore(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $owned = $this->generate(1);
        $this->useSessionCookie(null);
        $guestMade = $this->generate(1);

        $notYours = ['error' => 'You do not own this reading.'];
        // Signed out, even in the browser that made it: a guest reading has no owner.
        $this->assertSame($notYours, $this->assertJsonResponse(403, $this->post("/readings/{$owned}/draw")));
        $this->assertSame($notYours, $this->assertJsonResponse(403, $this->post("/readings/{$guestMade}/draw")));

        $this->signInAs($this->createUser('stranger@example.test')->user_id);
        $this->assertSame($notYours, $this->assertJsonResponse(403, $this->post("/readings/{$owned}/draw")));
        $this->assertSame(['error' => 'Reading not found.'], $this->assertJsonResponse(404, $this->post('/readings/0000000000/draw')));

        $this->assertCount(1, $this->storedDraw($owned));
        $this->assertCount(1, $this->storedDraw($guestMade));
    }

    public function testACustomReadingCannotDrawMore(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $custom = $this->assertJsonResponse(201, $this->post('/readings', ['deck_id' => $this->deckId, 'cards' => [['card_id' => 1]]]));
        $this->assertIsString($custom['reading_id']);

        $viewed = $this->assertJsonResponse(200, $this->get("/readings/{$custom['reading_id']}"));
        $this->assertTrue($viewed['is_owner']);
        $this->assertFalse($viewed['can_draw_more']);

        $response = $this->post("/readings/{$custom['reading_id']}/draw");
        $this->assertSame(['error' => 'Custom readings cannot draw additional cards.'], $this->assertJsonResponse(400, $response));
    }

    public function testFinalizingLocksAReadingForGood(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $id = $this->generate(1);

        $final = $this->assertJsonResponse(200, $this->post("/readings/{$id}/finalize"));
        $this->assertTrue($final['is_final']);
        $this->assertFalse($final['can_draw_more']);
        $this->assertSame([['is_final' => 1]], $this->rows("SELECT is_final FROM readings WHERE reading_id = '{$id}'"));

        $isFinal = ['error' => 'This reading is final and can no longer be changed.'];
        $this->assertSame($isFinal, $this->assertJsonResponse(409, $this->post("/readings/{$id}/draw")));
        $this->assertSame($isFinal, $this->assertJsonResponse(409, $this->place($id, [[]])));
        $this->assertCount(1, $this->storedDraw($id));

        // Finalizing again is harmless: still final, still the owner's.
        $this->assertTrue($this->assertJsonResponse(200, $this->post("/readings/{$id}/finalize"))['is_final']);
    }

    public function testOnlyTheOwnerCanFinalize(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $owned = $this->generate(1);
        $this->useSessionCookie(null);
        $guestMade = $this->generate(1);

        $notYours = ['error' => 'You do not own this reading.'];
        $this->assertSame($notYours, $this->assertJsonResponse(403, $this->post("/readings/{$owned}/finalize")));
        $this->assertSame($notYours, $this->assertJsonResponse(403, $this->post("/readings/{$guestMade}/finalize")));

        $this->signInAs($this->createUser('stranger@example.test')->user_id);
        $this->assertSame($notYours, $this->assertJsonResponse(403, $this->post("/readings/{$owned}/finalize")));
        $this->assertSame(['error' => 'Reading not found.'], $this->assertJsonResponse(404, $this->post('/readings/0000000000/finalize')));

        $this->assertSame([['is_final' => 0], ['is_final' => 0]], $this->rows('SELECT is_final FROM readings'));
    }

    public function testAGuestReadingCanBePlacedByAnyoneWithItsLink(): void
    {
        $id = $this->generate(2);
        $this->useSessionCookie(null);

        $placed = $this->assertJsonResponse(200, $this->place($id, [
            ['title' => '  Left  ', 'x' => 150, 'y' => -1, 'rotation' => 450],
            ['x' => 25.555, 'rotation' => -90],
        ]));

        $this->assertDocumented('put', '/readings/{reading_id}/placement', 200, $placed);
        $this->assertSame(['success' => true, 'reading_id' => $id], $placed);
        $this->assertSame([
            'spread_id' => 0,
            'name' => 'Free Draw Placement',
            'description' => '',
            'positions' => [
                ['order' => 1, 'title' => 'Left', 'x' => 100, 'y' => 0, 'rotation' => 90],
                ['order' => 2, 'title' => '', 'x' => 25.56, 'y' => 50, 'rotation' => 270],
            ],
        ], $this->storedInfo($id)['spread']);
    }

    public function testPlacementRewritesASpreadsPositionsButKeepsWhichSpreadItIs(): void
    {
        $spread = $this->service(SpreadData::class)->store([
            'name' => 'Pair', 'description' => 'Two cards',
            'positions' => [['order' => 1, 'title' => 'One'], ['order' => 2, 'title' => 'Two']],
        ]);
        $this->assertNotNull($spread);
        $id = $this->generate(1, ['spread_id' => $spread->spread_id]);

        $this->assertJsonResponse(200, $this->place($id, [['title' => 'Uno'], ['title' => 'Dos']]));
        $kept = $this->storedInfo($id)['spread'];
        $this->assertIsArray($kept);
        $this->assertSame([$spread->spread_id, 'Pair', 'Two cards'], [$kept['spread_id'], $kept['name'], $kept['description']]);
        $this->assertIsArray($kept['positions']);
        $this->assertSame(['Uno', 'Dos'], array_column($kept['positions'], 'title'));

        $this->assertJsonResponse(200, $this->place($id, [[], []], 'Renamed'));
        $renamed = $this->storedInfo($id)['spread'];
        $this->assertIsArray($renamed);
        $this->assertSame([$spread->spread_id, 'Renamed'], [$renamed['spread_id'], $renamed['name']]);
    }

    public function testPlacementNeedsOnePositionPerDrawnCard(): void
    {
        $id = $this->generate(2);
        $before = $this->storedInfo($id);

        $this->assertSame(['error' => 'At least one position is required.'], $this->assertJsonResponse(400, $this->place($id, [])));
        $this->assertSame(
            ['error' => 'At least one position is required.'],
            $this->assertJsonResponse(400, $this->request('PUT', "/readings/{$id}/placement", ['positions' => 'left, right']))
        );
        $this->assertSame(
            ['error' => 'Position count must match the number of drawn cards.'],
            $this->assertJsonResponse(400, $this->place($id, [[]]))
        );
        $this->assertSame(['error' => 'Invalid position data.'], $this->assertJsonResponse(400, $this->place($id, [[], 'right'])));

        $this->assertSame($before, $this->storedInfo($id));
    }

    public function testAnOwnedReadingCanOnlyBePlacedByItsOwner(): void
    {
        $owner = $this->createUser('owner@example.test');
        $this->signInAs($owner->user_id);
        $id = $this->generate(1);

        $this->useSessionCookie(null);
        $notYours = ['error' => 'You do not own this reading.'];
        $this->assertSame($notYours, $this->assertJsonResponse(403, $this->place($id, [[]])));
        $this->signInAs($this->createUser('stranger@example.test')->user_id);
        $this->assertSame($notYours, $this->assertJsonResponse(403, $this->place($id, [[]])));
        $this->assertArrayNotHasKey('spread', $this->storedInfo($id));
        $this->assertSame(['error' => 'Reading not found.'], $this->assertJsonResponse(404, $this->place('0000000000', [[]])));

        $this->useSessionCookie(null);
        $this->signInAs($owner->user_id);
        $this->assertJsonResponse(200, $this->place($id, [['title' => 'Mine']]));
        $this->assertArrayHasKey('spread', $this->storedInfo($id));
    }

    /**
     * Generate a reading of $cards cards in the current browser; returns its id.
     *
     * @param array<string,mixed> $options
     */
    private function generate(int $cards, array $options = []): string
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'number_of_cards' => $cards,
        ] + $options));
        $this->assertIsString($created['reading_id']);
        return $created['reading_id'];
    }

    /** @param array<mixed> $positions */
    private function place(string $id, array $positions, ?string $spreadName = null): ResponseInterface
    {
        $body = ['positions' => $positions];
        if ($spreadName !== null) {
            $body['spread_name'] = $spreadName;
        }
        return $this->request('PUT', "/readings/{$id}/placement", $body);
    }

    /** @return array<string,mixed> The stored reading_info, decoded. */
    private function storedInfo(string $id): array
    {
        $stmt = $this->db->prepare('SELECT reading_info FROM readings WHERE reading_id = ?');
        $stmt->execute([$id]);
        $info = json_decode((string) $stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($info);
        return $info;
    }

    /** @return list<mixed> */
    private function storedDraw(string $id): array
    {
        $draw = $this->storedInfo($id)['draw'];
        $this->assertIsArray($draw);
        return array_values($draw);
    }
}
