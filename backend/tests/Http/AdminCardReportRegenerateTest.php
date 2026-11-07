<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Routes\Internal\AdminCardReportController;
use Tarot\Data\CardReportData;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Repository\CardReportRepository;
use Tarot\Service\CardImageService;
use Tarot\Structure\CardReportReason;

/**
 * "Regenerate & resolve" on a reported card: its web images are rebuilt from the
 * replaced master scan and only then is the report closed. Runs against a
 * temporary deck-images root rather than backend/assets/decks.
 */
#[CoversClass(AdminCardReportController::class)]
#[CoversClass(CardReportRepository::class)]
#[CoversClass(CardReportData::class)]
#[CoversClass(CardImageService::class)]
final class AdminCardReportRegenerateTest extends HttpTestCase
{
    private string $imageRoot;

    private int $deckId;

    private CardReportRepository $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageRoot = sys_get_temp_dir() . '/tarot-report-images-' . bin2hex(random_bytes(4));
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is required.');
        }
        mkdir($this->imageRoot);
        $this->replaceService(CardImageService::class, new CardImageService($this->imageRoot));

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'approved' => true]);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => 'Thoth', 'deck_system_id' => $system->deck_system_id, 'approved' => true]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
        $this->reports = $this->service(CardReportRepository::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->imageRoot)) {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->imageRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($entries as $entry) {
                $path = (string) $entry;
                is_dir($path) ? rmdir($path) : unlink($path);
            }
            rmdir($this->imageRoot);
        }

        parent::tearDown();
    }

    public function testTheCardsImagesAreRebuiltFromTheNewScanAndTheReportIsResolved(): void
    {
        $id = $this->reportId(1, 'The Magician', 'Edge cut off');
        $this->scan('Card_0001.png');
        $this->scan('Card_0002.png');
        // The stale images look newer than the replaced scan, as they do when an
        // upload keeps the scan's original timestamp: they are rebuilt anyway.
        $this->staleImages('Card_0001', time() + 60);
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $report = $this->assertJsonResponse(200, $this->post("/admin/card-reports/{$id}/regenerate"));

        $this->assertDocumented('post', '/admin/card-reports/{report_id}/regenerate', 200, $report);
        $this->assertSame($id, $report['report_id']);
        $this->assertSame('Thoth', $report['deck_name']);
        $this->assertSame('The Magician', $report['card_name']);
        $this->assertIsString($report['resolved_at']);
        $this->assertSame(['Edge cut off'], array_column($report['submissions'], 'details'));
        $this->assertSame($report['resolved_at'], $this->rows('SELECT resolved_at FROM card_reports')[0]['resolved_at']);

        $this->assertIsWebp("{$this->imageRoot}/{$this->deckId}/Card_0001.webp");
        $this->assertIsWebp("{$this->imageRoot}/{$this->deckId}/thumbs/Card_0001.webp");
        // Only the reported card: the rest of the deck is the deck page's job.
        $this->assertFileDoesNotExist("{$this->imageRoot}/{$this->deckId}/Card_0002.webp");
    }

    public function testAReportThatIsAlreadyResolvedIsAConflict(): void
    {
        $id = $this->reportId(1, 'The Magician');
        $this->reports->setResolved($id, true);
        $resolvedAt = $this->rows('SELECT resolved_at FROM card_reports')[0]['resolved_at'];
        $this->scan('Card_0001.png');
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $this->assertSame(
            ['error' => 'This report is already resolved.'],
            $this->assertJsonResponse(409, $this->post("/admin/card-reports/{$id}/regenerate"))
        );
        $this->assertFileDoesNotExist("{$this->imageRoot}/{$this->deckId}/Card_0001.webp");
        $this->assertSame([['resolved_at' => $resolvedAt]], $this->rows('SELECT resolved_at FROM card_reports'));
    }

    public function testACardWithNoScanOnTheServerIsAConflictAndStaysOpen(): void
    {
        $id = $this->reportId(7, 'The Chariot');
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $this->assertSame(
            ['error' => "There is no master scan for this card on the server. Upload Card_0007.png to deck {$this->deckId}'s folder, then try again."],
            $this->assertJsonResponse(409, $this->post("/admin/card-reports/{$id}/regenerate"))
        );
        $this->assertSame([['resolved_at' => null]], $this->rows('SELECT resolved_at FROM card_reports'));
    }

    public function testAScanThatCannotBeConvertedFailsAndLeavesTheReportOpen(): void
    {
        $id = $this->reportId(1, 'The Magician');
        mkdir("{$this->imageRoot}/{$this->deckId}");
        file_put_contents("{$this->imageRoot}/{$this->deckId}/Card_0001.png", 'not a png');
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $this->assertSame(
            ['error' => "Couldn't make web images from Card_0001.png; is it a valid PNG? The report is still open."],
            $this->assertJsonResponse(500, $this->post("/admin/card-reports/{$id}/regenerate"))
        );
        $this->assertSame([['resolved_at' => null]], $this->rows('SELECT resolved_at FROM card_reports'));
        $this->assertFileDoesNotExist("{$this->imageRoot}/{$this->deckId}/Card_0001.webp");
    }

    public function testAReportThatDoesNotExistIsNotFoundAndABadIdIsRefused(): void
    {
        $this->reportId(1, 'The Magician');
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $this->assertSame(['error' => 'Report not found'], $this->assertJsonResponse(404, $this->post('/admin/card-reports/999/regenerate')));
        $this->assertSame(['error' => 'Invalid report ID'], $this->assertJsonResponse(400, $this->post('/admin/card-reports/0/regenerate')));
        $this->assertSame(['error' => 'Invalid report ID'], $this->assertJsonResponse(400, $this->post('/admin/card-reports/all/regenerate')));
        $this->assertSame([['resolved_at' => null]], $this->rows('SELECT resolved_at FROM card_reports'));
    }

    public function testAReportWhoseDeckIsGoneIsNotFound(): void
    {
        $id = $this->reportId(1, 'The Magician');
        $this->scan('Card_0001.png');
        // A report from before decks were foreign-keyed, which outlived its deck.
        $this->db->exec('PRAGMA foreign_keys = OFF');
        $this->db->exec("DELETE FROM decks WHERE deck_id = {$this->deckId}");
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $this->assertSame(['error' => 'Deck not found'], $this->assertJsonResponse(404, $this->post("/admin/card-reports/{$id}/regenerate")));
        $this->assertSame([['resolved_at' => null]], $this->rows('SELECT resolved_at FROM card_reports'));
        $this->assertFileDoesNotExist("{$this->imageRoot}/{$this->deckId}/Card_0001.webp");
    }

    public function testOnlyAnAdminCanRegenerateAReportedCard(): void
    {
        $id = $this->reportId(1, 'The Magician');
        $this->scan('Card_0001.png');

        $this->assertSame(['error' => 'Unauthorized'], $this->assertJsonResponse(401, $this->post("/admin/card-reports/{$id}/regenerate")));

        $this->signInAs($this->createUser('reader@example.test')->user_id);
        $this->assertSame(['error' => 'Unauthorized'], $this->assertJsonResponse(401, $this->post("/admin/card-reports/{$id}/regenerate")));

        $this->assertSame([['resolved_at' => null]], $this->rows('SELECT resolved_at FROM card_reports'));
        $this->assertFileDoesNotExist("{$this->imageRoot}/{$this->deckId}/Card_0001.webp");
    }

    private function reportId(int $cardId, string $cardName, string $details = ''): int
    {
        $this->reports->report($this->deckId, $cardId, $cardName, CardReportReason::Cropping, $details);
        $row = $this->rows("SELECT report_id FROM card_reports WHERE card_id = {$cardId}");
        $this->assertCount(1, $row);

        return (int) $row[0]['report_id'];
    }

    /** A small stand-in for a master scan. */
    private function scan(string $file): void
    {
        $dir = "{$this->imageRoot}/{$this->deckId}";
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $image = imagecreatetruecolor(60, 103);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 80, 40, 160));
        imagepng($image, "{$dir}/{$file}");
    }

    /** Placeholder display and thumbnail files dated $mtime. */
    private function staleImages(string $name, int $mtime): void
    {
        $dir = "{$this->imageRoot}/{$this->deckId}";
        if (!is_dir("{$dir}/thumbs")) {
            mkdir("{$dir}/thumbs", 0o775, true);
        }
        foreach (["{$dir}/{$name}.webp", "{$dir}/thumbs/{$name}.webp"] as $path) {
            file_put_contents($path, 'the old image');
            touch($path, $mtime);
        }
        clearstatcache();
    }

    private function assertIsWebp(string $path): void
    {
        $size = @getimagesize($path);
        $this->assertNotFalse($size, "{$path} is not an image");
        $this->assertSame(IMAGETYPE_WEBP, $size[2]);
    }
}
