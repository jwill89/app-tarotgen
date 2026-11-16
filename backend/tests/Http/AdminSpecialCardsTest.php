<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminDeckController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\SpecialCardData;
use Tarot\Repository\SpecialCardRepository;

/**
 * The extra cards a deck carries beyond its system's set, managed under
 * /admin/decks/{deck_id}/special-cards: the deck always comes from the path.
 */
#[CoversClass(AdminDeckController::class)]
#[CoversClass(SpecialCardRepository::class)]
#[CoversClass(SpecialCardData::class)]
final class AdminSpecialCardsTest extends HttpTestCase
{
    private int $deckId;

    private int $otherDeckId;

    protected function setUp(): void
    {
        parent::setUp();

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'approved' => true]);
        $this->assertNotNull($system);
        $decks = $this->service(DeckData::class);
        $deck = $decks->store(['name' => 'Thoth', 'deck_system_id' => $system->deck_system_id, 'additional_cards' => 2]);
        $other = $decks->store(['name' => 'Marseille', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->assertNotNull($other);
        $this->deckId = $deck->deck_id;
        $this->otherDeckId = $other->deck_id;

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
    }

    public function testASpecialCardJoinsTheDeckNamedInThePath(): void
    {
        $response = $this->post("/admin/decks/{$this->deckId}/special-cards", [
            'card_id' => 79, 'name' => 'The Artist', 'keywords' => 'craft', 'deck_id' => $this->otherDeckId,
        ]);

        $card = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/admin/decks/{deck_id}/special-cards', 201, $card);
        $this->assertSame([$this->deckId, 79, 'The Artist', 'craft'], [$card['deck_id'], $card['card_id'], $card['name'], $card['keywords']]);

        $list = $this->assertJsonResponse(200, $this->get("/admin/decks/{$this->deckId}/special-cards"));
        $this->assertDocumented('get', '/admin/decks/{deck_id}/special-cards', 200, $list);
        $this->assertSame(['The Artist'], array_column($list, 'name'));
        $this->assertSame([], $this->assertJsonResponse(200, $this->get("/admin/decks/{$this->otherDeckId}/special-cards")));
    }

    public function testTheListIsInCardOrder(): void
    {
        $cards = $this->service(SpecialCardData::class);
        $cards->store(['deck_id' => $this->deckId, 'card_id' => 80, 'name' => 'Second']);
        $cards->store(['deck_id' => $this->deckId, 'card_id' => 79, 'name' => 'First']);

        $list = $this->assertJsonResponse(200, $this->get("/admin/decks/{$this->deckId}/special-cards"));

        $this->assertSame([79, 80], array_column($list, 'card_id'));
    }

    public function testEditingASpecialCardChangesItsText(): void
    {
        $this->service(SpecialCardData::class)->store(['deck_id' => $this->deckId, 'card_id' => 79, 'name' => 'The Artist']);

        $response = $this->request('PUT', "/admin/decks/{$this->deckId}/special-cards/79", [
            'name' => 'The Maker', 'meaning_reversed' => 'Blocked', 'card_id' => 1,
        ]);

        $card = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('put', '/admin/decks/{deck_id}/special-cards/{card_id}', 200, $card);
        $this->assertSame([79, 'The Maker', 'Blocked'], [$card['card_id'], $card['name'], $card['meaning_reversed']]);
    }

    public function testEditingACardTheDeckDoesNotHaveIsNotFound(): void
    {
        $this->service(SpecialCardData::class)->store(['deck_id' => $this->deckId, 'card_id' => 79, 'name' => 'The Artist']);
        $notFound = 'Special card not found';

        $this->assertSame($notFound, $this->assertProblem(404, $this->request('PUT', "/admin/decks/{$this->otherDeckId}/special-cards/79", ['name' => 'Moved'])));
        $this->assertSame($notFound, $this->assertProblem(404, $this->request('PUT', "/admin/decks/{$this->deckId}/special-cards/79", ['card_id' => 80])));
        $this->assertSame([['deck_id' => $this->deckId, 'card_id' => 79, 'name' => 'The Artist']], $this->rows('SELECT deck_id, card_id, name FROM special_cards'));
    }

    public function testDeletingASpecialCardRemovesOnlyThatCard(): void
    {
        $cards = $this->service(SpecialCardData::class);
        $cards->store(['deck_id' => $this->deckId, 'card_id' => 79, 'name' => 'The Artist']);
        $cards->store(['deck_id' => $this->otherDeckId, 'card_id' => 79, 'name' => 'Other Artist']);

        $this->assertSame(204, $this->request('DELETE', "/admin/decks/{$this->deckId}/special-cards/79")->getStatusCode());

        $this->assertSame([['deck_id' => $this->otherDeckId, 'card_id' => 79]], $this->rows('SELECT deck_id, card_id FROM special_cards'));
    }

    public function testACardIdAlreadyUsedInTheDeckIsAConflict(): void
    {
        $this->service(SpecialCardData::class)->store(['deck_id' => $this->deckId, 'card_id' => 79, 'name' => 'The Artist']);

        $response = $this->post("/admin/decks/{$this->deckId}/special-cards", ['card_id' => 79, 'name' => 'Again']);

        $this->assertSame('This deck already has a special card with that card ID', $this->assertProblem(409, $response));
        // The same card id in another deck is a different card.
        $this->assertJsonResponse(201, $this->post("/admin/decks/{$this->otherDeckId}/special-cards", ['card_id' => 79, 'name' => 'Elsewhere']));
        $this->assertSame([['name' => 'The Artist'], ['name' => 'Elsewhere']], $this->rows('SELECT name FROM special_cards ORDER BY deck_id'));
    }

    public function testASpecialCardForADeckThatDoesNotExistIsNotFound(): void
    {
        $response = $this->post('/admin/decks/999/special-cards', ['card_id' => 79, 'name' => 'Nowhere']);

        $this->assertSame('Deck not found', $this->assertProblem(404, $response));
        $this->assertSame(0, $this->rowCount('special_cards'));
    }
}
