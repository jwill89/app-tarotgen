<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AdminCardReportController;
use Tarot\Data\CardReportData;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Repository\CardReportRepository;
use Tarot\Structure\CardReportReason;

/**
 * The reported-cards page: open reports first and most-reported first, each
 * with the reasons given, and the resolved flag an admin sets and clears.
 */
#[CoversClass(AdminCardReportController::class)]
#[CoversClass(CardReportRepository::class)]
#[CoversClass(CardReportData::class)]
final class AdminCardReportsTest extends HttpTestCase
{
    private int $deckId;

    private CardReportRepository $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS']);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => 'Thoth', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
        $this->reports = $this->service(CardReportRepository::class);

        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
    }

    public function testOpenReportsAreListedMostReportedFirstWithTheirReasons(): void
    {
        $this->reports->report($this->deckId, 2, 'The High Priestess', CardReportReason::Quality, '');
        $this->reports->report($this->deckId, 1, 'The Magician', CardReportReason::Cropping, 'Edge cut off');
        $this->reports->report($this->deckId, 1, 'The Magician', CardReportReason::WrongCard, 'Shows the Fool');
        $this->reports->report($this->deckId, 1, 'The Magician', null, '');
        $resolved = $this->reportId(3, 'The Empress');
        $this->reports->setResolved($resolved, true);

        $open = $this->assertJsonResponse(200, $this->get('/admin/card-reports'));

        $this->assertDocumented('get', '/admin/card-reports', 200, $open);
        $this->assertSame(['The Magician', 'The High Priestess'], array_column($open, 'card_name'));
        $this->assertSame([3, 1], array_column($open, 'report_count'));
        $this->assertSame(['Thoth', 'Thoth'], array_column($open, 'deck_name'));
        $this->assertIsArray($open[0]);
        // The report without a reason (an older plugin) only counts.
        $this->assertSame(['wrong_card', 'cropping'], array_column($open[0]['submissions'], 'reason'));

        $all = $this->assertJsonResponse(200, $this->get('/admin/card-reports?show_resolved=1'));
        $this->assertSame(['The Magician', 'The High Priestess', 'The Empress'], array_column($all, 'card_name'));
    }

    public function testResolvingAReportClosesItUntilItIsReopened(): void
    {
        $id = $this->reportId(1, 'The Magician');

        $body = $this->assertJsonResponse(200, $this->request('PATCH', "/admin/card-reports/{$id}", ['resolved' => true]));

        $this->assertDocumented('patch', '/admin/card-reports/{report_id}', 200, $body);
        $this->assertSame(['success' => true], $body);
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/admin/card-reports')));
        $this->assertNotNull($this->rows('SELECT resolved_at FROM card_reports')[0]['resolved_at']);

        $this->assertJsonResponse(200, $this->request('PATCH', "/admin/card-reports/{$id}", ['resolved' => false]));
        $this->assertSame([['resolved_at' => null]], $this->rows('SELECT resolved_at FROM card_reports'));
        $this->assertSame(['The Magician'], array_column($this->assertJsonResponse(200, $this->get('/admin/card-reports')), 'card_name'));
    }

    public function testAnUpdateWithoutTheResolvedFlagChangesNothing(): void
    {
        $id = $this->reportId(1, 'The Magician');

        $this->assertSame(['success' => true], $this->assertJsonResponse(200, $this->request('PATCH', "/admin/card-reports/{$id}", ['report_count' => 0])));

        $this->assertSame([['report_count' => 1, 'resolved_at' => null]], $this->rows('SELECT report_count, resolved_at FROM card_reports'));
    }

    public function testAReportIdThatIsNotAPositiveNumberIsRefused(): void
    {
        $invalid = ['error' => 'Invalid report ID'];

        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->request('PATCH', '/admin/card-reports/0', ['resolved' => true])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->request('PATCH', '/admin/card-reports/all', ['resolved' => true])));
    }

    public function testAReportThatDoesNotExistIsNotFound(): void
    {
        $this->reportId(1, 'The Magician');
        $notFound = ['error' => 'Report not found'];

        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PATCH', '/admin/card-reports/999', ['resolved' => true])));
        $this->assertSame($notFound, $this->assertJsonResponse(404, $this->request('PATCH', '/admin/card-reports/999', [])));
        $this->assertSame([['resolved_at' => null]], $this->rows('SELECT resolved_at FROM card_reports'));
    }

    public function testCardReportsNeedAnAdmin(): void
    {
        $id = $this->reportId(1, 'The Magician');
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $this->assertJsonResponse(401, $this->get('/admin/card-reports'));
        $this->assertJsonResponse(401, $this->request('PATCH', "/admin/card-reports/{$id}", ['resolved' => true]));
        $this->assertSame([['resolved_at' => null]], $this->rows('SELECT resolved_at FROM card_reports'));
    }

    private function reportId(int $cardId, string $cardName): int
    {
        $this->reports->report($this->deckId, $cardId, $cardName, CardReportReason::Other, '');
        $row = $this->rows("SELECT report_id FROM card_reports WHERE card_id = {$cardId}");
        $this->assertCount(1, $row);

        return (int) $row[0]['report_id'];
    }
}
