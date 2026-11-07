<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Routes\Internal\AdminDeckController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Service\CardImageService;
use Tarot\Repository\DeckRepository;

/**
 * The admin buttons that derive each deck's web images from its PNG scans, run
 * against a temporary deck-images root rather than backend/assets/decks.
 */
#[CoversClass(AdminDeckController::class)]
#[CoversClass(DeckRepository::class)]
final class AdminDeckImagesTest extends HttpTestCase
{
    private string $imageRoot;

    private int $systemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageRoot = sys_get_temp_dir() . '/tarot-admin-images-' . bin2hex(random_bytes(4));
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is required.');
        }
        mkdir($this->imageRoot);
        $this->replaceService(CardImageService::class, new CardImageService($this->imageRoot));

        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'approved' => true]);
        $this->assertNotNull($system);
        $this->systemId = $system->deck_system_id;
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

    public function testADecksWebImagesAreMadeOnceAndThenLeftAlone(): void
    {
        $deckId = $this->deck('Thoth');
        $this->scan($deckId, 'Card_0001.png');
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $first = $this->assertJsonResponse(200, $this->post("/admin/decks/{$deckId}/images"));

        $this->assertDocumented('post', '/admin/decks/{deck_id}/images', 200, $first);
        $this->assertSame(['deck_id' => $deckId, 'generated' => 1, 'skipped' => 0, 'failed' => 0], $first);
        $this->assertFileExists("{$this->imageRoot}/{$deckId}/Card_0001.webp");
        $this->assertFileExists("{$this->imageRoot}/{$deckId}/thumbs/Card_0001.webp");

        $again = $this->assertJsonResponse(200, $this->post("/admin/decks/{$deckId}/images"));
        $this->assertSame(['deck_id' => $deckId, 'generated' => 0, 'skipped' => 1, 'failed' => 0], $again);
    }

    public function testGeneratingEveryDeckCoversPendingDecksAndCountsBrokenScans(): void
    {
        $approved = $this->deck('Thoth');
        $pending = $this->deck('Wild Unknown', approved: false);
        $this->scan($approved, 'Card_0001.png');
        $this->scan($pending, 'Card_0001.png');
        file_put_contents("{$this->imageRoot}/{$pending}/Card_0002.png", 'not a png');
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $totals = $this->assertJsonResponse(200, $this->post('/admin/decks/images'));

        $this->assertDocumented('post', '/admin/decks/images', 200, $totals);
        $this->assertSame(['decks' => 2, 'generated' => 2, 'skipped' => 0, 'failed' => 1], $totals);
    }

    public function testADeckWithNoScansHasNothingToDo(): void
    {
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $this->assertSame(
            ['deck_id' => 999, 'generated' => 0, 'skipped' => 0, 'failed' => 0],
            $this->assertJsonResponse(200, $this->post('/admin/decks/999/images'))
        );
        $this->assertDirectoryDoesNotExist("{$this->imageRoot}/999");
    }

    public function testADeckIdThatIsNotAPositiveNumberIsRefused(): void
    {
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);

        $this->assertSame(['error' => 'Invalid deck ID'], $this->assertJsonResponse(400, $this->post('/admin/decks/0/images')));
        $this->assertSame(['error' => 'Invalid deck ID'], $this->assertJsonResponse(400, $this->post('/admin/decks/thoth/images')));
    }

    public function testOnlyAnAdminCanGenerateImages(): void
    {
        $deckId = $this->deck('Thoth');
        $this->scan($deckId, 'Card_0001.png');
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        $this->assertJsonResponse(401, $this->post("/admin/decks/{$deckId}/images"));
        $this->assertJsonResponse(401, $this->post('/admin/decks/images'));

        $this->assertFileDoesNotExist("{$this->imageRoot}/{$deckId}/Card_0001.webp");
    }

    private function deck(string $name, bool $approved = true): int
    {
        $deck = $this->service(DeckData::class)->store(['name' => $name, 'deck_system_id' => $this->systemId, 'approved' => $approved]);
        $this->assertNotNull($deck);

        return $deck->deck_id;
    }

    /** A small stand-in for a master scan. */
    private function scan(int $deckId, string $file): void
    {
        $dir = "{$this->imageRoot}/{$deckId}";
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $image = imagecreatetruecolor(60, 103);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 80, 40, 160));
        imagepng($image, "{$dir}/{$file}");
    }
}
