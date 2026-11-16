<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\CardReportController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;

/**
 * POST /card-reports, the public report button in the card lightbox: what it
 * records, what it refuses, and both of its rate limits.
 */
#[CoversClass(CardReportController::class)]
final class CardReportsTest extends HttpTestCase
{
    private int $deckId;

    protected function setUp(): void
    {
        parent::setUp();

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS']);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
    }

    public function testAReportIsRecordedWithItsReason(): void
    {
        $response = $this->post('/card-reports', [
            'deck_id' => $this->deckId, 'card_id' => 0, 'card_name' => 'The Fool',
            'reason' => 'other', 'details' => 'Upside down',
        ]);

        $reported = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/card-reports', 201, $reported);
        $this->assertSame(['status' => 'reported'], $reported);
        $this->assertSame(
            [['card_id' => 0, 'card_name' => 'The Fool', 'report_count' => 1, 'reason' => 'other', 'details' => 'Upside down']],
            $this->reports()
        );
    }

    public function testAReportWithoutAReasonOnlyBumpsTheCount(): void
    {
        // What older plugin builds send.
        $this->assertJsonResponse(201, $this->post('/card-reports', ['deck_id' => $this->deckId, 'card_id' => 5]));

        $this->assertSame(
            [['card_id' => 5, 'card_name' => '', 'report_count' => 1, 'reason' => null, 'details' => null]],
            $this->reports()
        );
    }

    public function testTheSameCardFromTheSameVisitorCountsOnce(): void
    {
        $report = ['deck_id' => $this->deckId, 'card_id' => 3, 'reason' => 'cropping'];
        $this->assertJsonResponse(201, $this->post('/card-reports', $report));

        $again = $this->post('/card-reports', $report);

        $already = $this->assertJsonResponse(200, $again);
        $this->assertDocumented('post', '/card-reports', 200, $already);
        $this->assertSame(['status' => 'already_reported'], $already);
        $this->assertCount(1, $this->reports());
        $this->assertSame(1, $this->reports()[0]['report_count']);
    }

    public function testIncompleteReportsAreRefused(): void
    {
        $missingCard = $this->post('/card-reports', ['deck_id' => $this->deckId]);
        $this->assertSame('A valid deck and card are required.', $this->assertProblem(422, $missingCard));

        $missingDeck = $this->post('/card-reports', ['card_id' => 1]);
        $this->assertProblem(422, $missingDeck);

        $unknownReason = $this->post('/card-reports', ['deck_id' => $this->deckId, 'card_id' => 1, 'reason' => 'ugly']);
        $this->assertSame("Please choose what's wrong with this card.", $this->assertProblem(422, $unknownReason));

        $otherWithoutDetails = $this->post('/card-reports', ['deck_id' => $this->deckId, 'card_id' => 1, 'reason' => 'other']);
        $this->assertSame("Please describe what's wrong with this card.", $this->assertProblem(422, $otherWithoutDetails));

        $this->assertSame([], $this->reports());
    }

    public function testADeckThatDoesNotExistIsRefused(): void
    {
        $response = $this->post('/card-reports', ['deck_id' => $this->deckId + 1, 'card_id' => 1, 'reason' => 'quality']);

        $this->assertSame('A valid deck and card are required.', $this->assertProblem(422, $response));
    }

    public function testOneVisitorCanReportTwentyCardsAnHour(): void
    {
        for ($card = 1; $card <= 20; $card++) {
            $this->assertJsonResponse(201, $this->post('/card-reports', ['deck_id' => $this->deckId, 'card_id' => $card]));
        }

        $response = $this->post('/card-reports', ['deck_id' => $this->deckId, 'card_id' => 21]);

        $this->assertSame(
            'You have reported too many cards recently. Please try again later.',
            $this->assertProblem(429, $response)
        );
        $this->assertCount(20, $this->reports());
    }

    /** @return list<array<string,mixed>> */
    private function reports(): array
    {
        return $this->rows(
            'SELECT r.card_id, r.card_name, r.report_count, s.reason, s.details
             FROM card_reports r LEFT JOIN card_report_submissions s USING (report_id)
             ORDER BY r.card_id'
        );
    }
}
