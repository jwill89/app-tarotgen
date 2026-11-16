<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\CardReportData;
use Tarot\Repository\CardReportRepository;
use Tarot\Structure\CardReport;
use Tarot\Structure\CardReportReason;
use Tarot\Structure\CardReportSubmission;

/**
 * Exercises the card report store against an in-memory SQLite DB: the
 * report-or-increment upsert, deck-name resolution, resolve/reopen, and the
 * reasons (submissions) stored with each report.
 */
#[CoversClass(CardReportData::class)]
#[CoversClass(CardReportRepository::class)]
final class CardReportDataTest extends TestCase
{
    private CardReportRepository $reports;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE "decks" (deck_id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO \"decks\" (deck_id, name) VALUES (1, 'Rider-Waite'), (2, 'Thoth')");

        $pdo->exec(
            "CREATE TABLE card_reports (
                report_id         INTEGER PRIMARY KEY AUTOINCREMENT,
                deck_id           INTEGER NOT NULL,
                card_id           INTEGER NOT NULL,
                card_name         TEXT    NOT NULL DEFAULT '',
                report_count      INTEGER NOT NULL DEFAULT 1,
                resolved_at       TEXT    DEFAULT NULL,
                first_reported_at DATETIME    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                last_reported_at  DATETIME    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                round             INTEGER NOT NULL DEFAULT 1
            )"
        );
        $pdo->exec('CREATE UNIQUE INDEX idx_card_reports_card ON card_reports (deck_id, card_id)');

        $pdo->exec(
            "CREATE TABLE card_report_submissions (
                submission_id INTEGER PRIMARY KEY AUTOINCREMENT,
                report_id     INTEGER NOT NULL REFERENCES card_reports(report_id) ON DELETE CASCADE,
                round         INTEGER NOT NULL,
                reason        TEXT    NOT NULL,
                details       TEXT    NOT NULL DEFAULT '',
                submitted_at  DATETIME    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            )"
        );

        $this->reports = new CardReportRepository(new CardReportData($pdo));
    }

    public function testFirstReportCreatesRowWithCountOneAndDeckName(): void
    {
        $this->reports->report(1, 0, 'The Fool', CardReportReason::Artefact, '');

        $rows = $this->reports->get(false);
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->deck_id);
        $this->assertSame('Rider-Waite', $rows[0]->deck_name);
        $this->assertSame(0, $rows[0]->card_id);
        $this->assertSame('The Fool', $rows[0]->card_name);
        $this->assertSame(1, $rows[0]->report_count);
        $this->assertSame(1, $rows[0]->round);
        $this->assertNull($rows[0]->resolved_at);
    }

    public function testReReportingTheSameCardIncrementsTheCounter(): void
    {
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, '');
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Quality, '');
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Cropping, '');

        $rows = $this->reports->get(false);
        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]->report_count);
        $this->assertCount(3, $rows[0]->submissions);
    }

    public function testDifferentCardsAndDecksAreSeparateRows(): void
    {
        $this->reports->report(1, 5, 'A', CardReportReason::Artefact, '');
        $this->reports->report(1, 6, 'B', CardReportReason::Artefact, '');
        $this->reports->report(2, 5, 'C', CardReportReason::Artefact, '');

        $rows = $this->reports->get(false);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertCount(1, $row->submissions, 'each card keeps only its own reasons');
        }
    }

    public function testEmptyCardNameDoesNotOverwriteAnExistingName(): void
    {
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, '');
        $this->reports->report(1, 5, '', CardReportReason::Artefact, '');

        $rows = $this->reports->get(false);
        $this->assertSame('The Hierophant', $rows[0]->card_name);
    }

    public function testReportStoresTheReasonAndDetails(): void
    {
        $this->reports->report(1, 17, 'The Star', CardReportReason::WrongCard, 'This shows The Moon.');

        $submissions = $this->reports->get(false)[0]->submissions;
        $this->assertCount(1, $submissions);
        $this->assertSame('wrong_card', $submissions[0]->reason);
        $this->assertSame('This shows The Moon.', $submissions[0]->details);
        $this->assertSame(1, $submissions[0]->round);
        $this->assertNotSame('', $submissions[0]->submitted_at);
    }

    public function testReportWithoutAReasonOnlyBumpsTheCounter(): void
    {
        // Older plugin builds report without a reason; their details are never stored.
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, 'Dust, top left.');
        $this->reports->report(1, 5, 'The Hierophant', null, 'ignored');

        $report = $this->reports->get(false)[0];
        $this->assertSame(2, $report->report_count);
        $this->assertCount(1, $report->submissions);
        $this->assertSame('Dust, top left.', $report->submissions[0]->details);
    }

    public function testSubmissionsAreListedNewestFirst(): void
    {
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, 'first');
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Quality, 'second');

        // Same-second reports fall back to insertion order, newest first.
        $details = array_map(
            static fn(CardReportSubmission $s): string => $s->details,
            $this->reports->get(false)[0]->submissions
        );
        $this->assertSame(['second', 'first'], $details);
    }

    public function testResolveHidesFromOpenListButReopensOnNewReport(): void
    {
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, '');
        $id = $this->reports->get(false)[0]->report_id;

        $this->assertTrue($this->reports->setResolved($id, true));
        $this->assertCount(0, $this->reports->get(false));           // hidden from open list
        $this->assertCount(1, $this->reports->get(true));            // still visible with all

        // A fresh report reopens it and bumps the count.
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, '');
        $open = $this->reports->get(false);
        $this->assertCount(1, $open);
        $this->assertNull($open[0]->resolved_at);
        $this->assertSame(2, $open[0]->report_count);
    }

    public function testReportingAResolvedCardStartsANewRound(): void
    {
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, 'old problem');
        $this->reports->setResolved($this->reports->get(false)[0]->report_id, true);

        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::WrongCard, 'new problem');

        $report = $this->reports->get(false)[0];
        $this->assertSame(2, $report->round);
        $this->assertSame(['new problem' => 2, 'old problem' => 1], $this->roundsByDetails($report));
    }

    public function testFurtherReportsOnAnOpenCardStayInTheSameRound(): void
    {
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, 'one');
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, 'two');

        $report = $this->reports->get(false)[0];
        $this->assertSame(1, $report->round);
        $this->assertSame(['two' => 1, 'one' => 1], $this->roundsByDetails($report));
    }

    public function testAdminReopenKeepsTheSameRound(): void
    {
        // Reopening undoes a resolve; it isn't a new problem, so the reasons stay current.
        $this->reports->report(1, 5, 'The Hierophant', CardReportReason::Artefact, 'still dusty');
        $id = $this->reports->get(false)[0]->report_id;

        $this->reports->setResolved($id, true);
        $this->assertTrue($this->reports->setResolved($id, false));

        $report = $this->reports->get(false)[0];
        $this->assertSame(1, $report->round);
        $this->assertSame(['still dusty' => 1], $this->roundsByDetails($report));
    }

    public function testFindReturnsOneReportWithItsDeckNameAndReasons(): void
    {
        $this->reports->report(1, 6, 'The Lovers', CardReportReason::Quality, 'blurry');
        // Reported twice, so it lists first.
        $this->reports->report(2, 5, 'The Hierophant', CardReportReason::Artefact, 'dusty');
        $this->reports->report(2, 5, 'The Hierophant', CardReportReason::Cropping, 'crooked');
        $id = $this->reports->get(false)[0]->report_id;
        $this->reports->setResolved($id, true);

        $report = $this->reports->find($id);

        $this->assertNotNull($report);
        $this->assertSame([$id, 'Thoth', 'The Hierophant', 2], [$report->report_id, $report->deck_name, $report->card_name, $report->report_count]);
        $this->assertNotNull($report->resolved_at);
        $this->assertSame(
            ['crooked', 'dusty'],
            array_map(static fn(CardReportSubmission $s): string => $s->details, $report->submissions)
        );
        $this->assertNull($this->reports->find(999));
    }

    public function testOpenReportsSortBeforeResolvedOnes(): void
    {
        $this->reports->report(1, 5, 'A', CardReportReason::Artefact, '');
        $this->reports->report(1, 6, 'B', CardReportReason::Artefact, '');

        // Resolve either one; the open one must still sort first in the full list.
        $this->reports->setResolved($this->reports->get(false)[0]->report_id, true);

        $all = $this->reports->get(true);
        $this->assertCount(2, $all);
        $this->assertNull($all[0]->resolved_at);        // open first
        $this->assertNotNull($all[1]->resolved_at);
    }

    /**
     * Each submission's round keyed by its details, newest first.
     *
     * @return array<string,int>
     */
    private function roundsByDetails(CardReport $report): array
    {
        $rounds = [];
        foreach ($report->submissions as $submission) {
            $rounds[$submission->details] = $submission->round;
        }

        return $rounds;
    }
}
