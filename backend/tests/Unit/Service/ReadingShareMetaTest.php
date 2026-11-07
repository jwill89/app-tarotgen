<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\DeckData;
use Tarot\Data\ReadingData;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Service\ReadingShareMeta;
use Tarot\Utility\Clock;

/**
 * What og.php puts in a /reading/{id} page for crawlers, on the production
 * schema in memory: the reading's own title and deck when anyone with the link
 * may see it; only its name behind a password; and for a private, expired or
 * unknown reading the generic meta, the same for all three, with none of the
 * owner-only journal fields ever in it.
 */
#[CoversClass(ReadingShareMeta::class)]
final class ReadingShareMetaTest extends TestCase
{
    private const array GENERIC = ['TarotGen.io Tarot Generator', 'View this tarot reading on TarotGen.io.'];

    private PDO $db;

    private string $webRoot;

    private ReadingShareMeta $meta;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $this->db->exec("INSERT INTO users (user_id, email, password_hash, display_name) VALUES (1, 'one@example.test', '', 'One')");
        $this->db->exec("INSERT INTO decks (deck_id, name) VALUES (1, 'Rider-Waite'), (2, 'No Back Yet')");

        $this->webRoot = sys_get_temp_dir() . '/tarot-share-' . bin2hex(random_bytes(4));
        mkdir($this->webRoot . '/assets/decks/1', 0o777, true);
        file_put_contents($this->webRoot . '/assets/share_banner.png', 'banner');
        file_put_contents($this->webRoot . '/assets/decks/1/Card_Back.png', 'back');
        touch($this->webRoot . '/assets/share_banner.png', 1_700_000_000);
        touch($this->webRoot . '/assets/decks/1/Card_Back.png', 1_700_000_001);

        $this->meta = new ReadingShareMeta(
            new ReadingRepository(new ReadingData($this->db)),
            new DeckRepository(new DeckData($this->db)),
            $this->webRoot
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->webRoot . '/assets/decks/1/Card_Back.png');
        @unlink($this->webRoot . '/assets/share_banner.png');
        @rmdir($this->webRoot . '/assets/decks/1');
        @rmdir($this->webRoot . '/assets/decks');
        @rmdir($this->webRoot . '/assets');
        @rmdir($this->webRoot);
    }

    public function testAnOpenReadingIsDescribedByItsSpreadDeckAndCardBack(): void
    {
        $this->insert('spread0001', ['deck_id' => 1, 'spread' => ['name' => 'Celtic Cross'], 'draw' => [[], []]]);

        $this->assertSame([
            'title'       => 'Celtic Cross — Tarot Reading',
            'description' => 'Rider-Waite · A tarot reading on TarotGen.io.',
            'image'       => 'https://tarotgen.io/assets/decks/1/Card_Back.png?v=1700000001',
            'url'         => 'https://tarotgen.io/reading/spread0001',
        ], $this->meta->describe('spread0001'));
    }

    public function testANameWinsAndAFreeDrawCountsItsCards(): void
    {
        $this->insert('named00001', ['deck_id' => 2, 'draw' => [[]]], name: 'New moon');
        $this->insert('free000002', ['deck_id' => 2, 'draw' => [[], [], []]]);
        $this->insert('empty00003', ['deck_id' => 0, 'draw' => []]);

        $named = $this->meta->describe('named00001');
        $this->assertSame(['New moon', 'No Back Yet · A tarot reading on TarotGen.io.'], [$named['title'], $named['description']]);
        // No card back for the deck: the site's banner.
        $this->assertSame('https://tarotgen.io/assets/share_banner.png?v=1700000000', $named['image']);
        $this->assertSame('3-Card Tarot Reading', $this->meta->describe('free000002')['title']);
        $this->assertSame(['Tarot Reading', 'A tarot reading on TarotGen.io.'], array_slice(array_values($this->meta->describe('empty00003')), 0, 2));
    }

    public function testAPasswordReadingGivesAwayOnlyItsName(): void
    {
        $this->insert('locked0001', ['deck_id' => 1, 'spread' => ['name' => 'Celtic Cross'], 'draw' => []], name: 'Secret', password: 'x');
        $this->insert('locked0002', ['deck_id' => 1, 'draw' => []], password: 'x');

        $this->assertSame(
            ['Secret', 'This reading is password-protected on TarotGen.io.', 'https://tarotgen.io/assets/share_banner.png?v=1700000000'],
            array_slice(array_values($this->meta->describe('locked0001')), 0, 3)
        );
        $this->assertSame('Protected Tarot Reading', $this->meta->describe('locked0002')['title']);
    }

    public function testPrivateExpiredAndUnknownReadingsAllGetTheGenericMeta(): void
    {
        $this->insert('private001', ['deck_id' => 1, 'spread' => ['name' => 'Celtic Cross'], 'draw' => []], name: 'Mine alone', private: true);
        $this->insert('expired002', ['deck_id' => 1, 'draw' => []], name: 'Gone', userId: null, expires: Clock::in(-1));

        foreach (['private001', 'expired002', 'unknown003'] as $id) {
            $meta = $this->meta->describe($id);
            $this->assertSame(self::GENERIC, [$meta['title'], $meta['description']], $id);
            $this->assertSame('https://tarotgen.io/assets/share_banner.png?v=1700000000', $meta['image'], $id);
            $this->assertSame("https://tarotgen.io/reading/{$id}", $meta['url'], $id);
        }
        $this->assertSame(ReadingShareMeta::generic($this->webRoot, 'private001'), $this->meta->describe('private001'));
    }

    public function testTheJournalFieldsNeverAppear(): void
    {
        $this->insert('journal001', ['deck_id' => 1, 'draw' => [[]]]);
        $this->db->exec("UPDATE readings SET question = 'Will she call?', querent = 'Robin' WHERE reading_id = 'journal001'");
        $this->db->exec("INSERT INTO reading_tags (reading_id, tag) VALUES ('journal001', 'heartbreak')");

        $html = ReadingShareMeta::inject('<html><head></head><body></body></html>', $this->meta->describe('journal001'));

        foreach (['Will she call?', 'Robin', 'heartbreak'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function testTheShellsGenericTagsAreSwappedForTheReadings(): void
    {
        $shell = "<html><head>\n    <title>TarotGen.io</title>\n"
            . "    <meta name=\"description\" content=\"Generic\" />\n"
            . "    <meta property=\"og:title\" content=\"Generic\" />\n"
            . "    <meta name=\"twitter:card\" content=\"keep\" />\n"
            . "    <link rel=\"canonical\" href=\"https://tarotgen.io/\" />\n"
            . "</head><body></body></html>";

        $html = ReadingShareMeta::inject($shell, [
            'title' => 'A "quoted" <title>', 'description' => 'Deck & more', 'image' => 'https://tarotgen.io/i.png', 'url' => 'https://tarotgen.io/reading/abc',
        ]);

        $this->assertSame(1, substr_count($html, '<title>'));
        $this->assertStringContainsString('<title>A &quot;quoted&quot; &lt;title&gt;</title>', $html);
        $this->assertStringNotContainsString('Generic', $html);
        $this->assertStringNotContainsString('href="https://tarotgen.io/"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://tarotgen.io/reading/abc" />', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow" />', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Deck &amp; more" />', $html);
        $this->assertStringContainsString('<meta property="twitter:image" content="https://tarotgen.io/i.png" />', $html);
    }

    /**
     * @param array<string,mixed> $info
     */
    private function insert(
        string $id,
        array $info,
        ?string $name = null,
        ?string $password = null,
        bool $private = false,
        ?int $userId = 1,
        ?string $expires = null,
    ): void {
        $this->db->prepare(
            'INSERT INTO readings (reading_id, reading_info, user_id, reading_name, password_hash, is_private, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, json_encode($info, JSON_THROW_ON_ERROR), $userId, $name, $password, $private ? 1 : 0, $expires]);
    }
}
