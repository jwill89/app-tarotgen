<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminDeckController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\FavoriteDeckData;
use Tarot\Data\SpecialCardData;
use Tarot\Repository\CardReportRepository;
use Tarot\Structure\CardReportReason;

/**
 * Deleting a deck from the admin pages takes everything that hangs off it with
 * it, through the schema's ON DELETE CASCADE — and nothing that belongs to
 * another deck.
 */
#[CoversClass(AdminDeckController::class)]
final class AdminDecksTest extends HttpTestCase
{
    public function testDeletingADeckTakesItsSpecialCardsReportsAndFavourites(): void
    {
        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS']);
        $this->assertNotNull($system);
        $decks = $this->service(DeckData::class);
        $doomed = $decks->store(['name' => 'Doomed', 'deck_system_id' => $system->deck_system_id]);
        $kept = $decks->store(['name' => 'Kept', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($doomed);
        $this->assertNotNull($kept);

        $reader = $this->createUser('reader@example.test');
        foreach ([$doomed->deck_id, $kept->deck_id] as $deckId) {
            $this->service(SpecialCardData::class)->store(['deck_id' => $deckId, 'card_id' => 79, 'name' => 'Extra']);
            $this->service(CardReportRepository::class)->report($deckId, 1, 'The Magician', CardReportReason::Quality, '');
            $this->service(FavoriteDeckData::class)->add($reader->user_id, $deckId);
        }

        $admin = $this->createUser('admin@example.test', admin: true);
        $this->signInAs($admin->user_id);
        $response = $this->request('DELETE', "/admin/decks/{$doomed->deck_id}");

        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        foreach (['decks', 'special_cards', 'card_reports', 'card_report_submissions', 'user_favorite_decks'] as $table) {
            $this->assertSame(1, $this->rowCount($table), "{$table} should keep only the other deck's row");
        }
        $this->assertSame([['deck_id' => $kept->deck_id]], $this->rows('SELECT deck_id FROM special_cards'));
    }
}
