<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Routes\Internal\AccountController;
use Routes\Middleware\UserAuth;
use Tarot\Service\JournalExport;
use Tarot\Service\PluginTokenService;
use Tarot\Utility\Clock;

/**
 * Downloading the whole journal: every one of the user's readings and no one
 * else's, as JSON (documented) or as CSV with a row per drawn card, a dated
 * attachment either way. Only a signed-in browser may: a linked plugin's token
 * reaches the readings list, not this.
 */
#[CoversClass(AccountController::class)]
#[CoversClass(JournalExport::class)]
#[CoversClass(UserAuth::class)]
final class AccountExportTest extends HttpTestCase
{
    private const array SPREAD = [
        'spread_id'   => 3,
        'name'        => 'Past, Present, Future',
        'description' => 'A **classic** line.',
        'positions'   => [
            ['order' => 1, 'title' => 'Past', 'x' => 25, 'y' => 50, 'rotation' => 0],
            ['order' => 2, 'title' => 'Present', 'x' => 50, 'y' => 50, 'rotation' => 0],
        ],
    ];

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('reader@example.test')->user_id;
        $this->db->exec("INSERT INTO decks (deck_id, name, approved) VALUES (1, 'Rider-Waite', 1), (2, 'Awaiting Review', 0)");
    }

    public function testTheJsonHoldsEveryOneOfYourReadingsAndNoOneElses(): void
    {
        $other = $this->createUser('other@example.test');
        $this->insertReading('spread0001', $this->userId, [
            'deck_id' => 1,
            'origin'  => 'generated',
            'spread'  => self::SPREAD,
            'draw'    => [
                ['card_id' => 17, 'reversed' => false, 'card_name' => 'The Star'],
                ['card_id' => 18, 'reversed' => true, 'card_name' => 'The Moon'],
            ],
        ], '2026-01-01 09:00:00', name: 'New moon', notes: "## Notes\n\nKept as typed.", final: true, password: 'secret');
        $this->journal('spread0001', 'Will it last?', 'Robin', ['moon', 'monthly']);
        $this->insertReading('freedraw02', $this->userId, ['deck_id' => 2, 'draw' => [['card_id' => 5, 'reversed' => false]]], '2026-01-02 09:00:00');
        $this->insertReading('theirs0003', $other->user_id, ['deck_id' => 1, 'draw' => []], '2026-01-03 09:00:00');
        $this->insertReading('guest00004', null, ['deck_id' => 1, 'draw' => []], '2026-01-04 09:00:00');
        $this->signInAs($this->userId);

        $response = $this->get('/account/readings/export?format=json');

        $journal = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/account/readings/export', 200, $journal);
        $this->assertDownload($response, 'application/json; charset=utf-8', 'json');
        $this->assertIsArray($journal['readings']);
        $this->assertSame(['freedraw02', 'spread0001'], array_column($journal['readings'], 'reading_id'));
        $this->assertSame([
            'reading_id'         => 'spread0001',
            'link'               => 'https://tarotgen.io/reading/spread0001',
            'reading_time'       => '2026-01-01 09:00:00',
            'reading_name'       => 'New moon',
            'reading_notes'      => "## Notes\n\nKept as typed.",
            'question'           => 'Will it last?',
            'querent'            => 'Robin',
            'tags'               => ['monthly', 'moon'],
            'deck_id'            => 1,
            'deck_name'          => 'Rider-Waite',
            'spread'             => self::SPREAD,
            'cards'              => [
                ['position' => 1, 'position_title' => 'Past', 'card_id' => 17, 'card_name' => 'The Star', 'reversed' => false],
                ['position' => 2, 'position_title' => 'Present', 'card_id' => 18, 'card_name' => 'The Moon', 'reversed' => true],
            ],
            'is_final'           => true,
            'visibility'         => 'password',
            'password_protected' => true,
        ], $journal['readings'][1]);
        // A free draw has no layout; a deck awaiting review isn't named, as on the page.
        $this->assertIsArray($journal['readings'][0]);
        $this->assertNull($journal['readings'][0]['spread']);
        $this->assertSame('Deck #2', $journal['readings'][0]['deck_name']);
        // The view password is a flag, never its hash.
        $this->assertStringNotContainsString('$2y$', (string) $response->getBody());
    }

    public function testJsonIsTheDefaultFormat(): void
    {
        $this->signInAs($this->userId);

        $response = $this->get('/account/readings/export');

        $journal = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/account/readings/export', 200, $journal);
        $this->assertSame([], $journal['readings']);
        $this->assertDownload($response, 'application/json; charset=utf-8', 'json');
    }

    public function testTheCsvHasARowPerDrawnCardWithTheReadingRepeated(): void
    {
        $this->insertReading('spread0001', $this->userId, [
            'deck_id' => 1,
            'spread'  => self::SPREAD,
            'draw'    => [
                ['card_id' => 17, 'reversed' => false, 'card_name' => 'The Star'],
                ['card_id' => 18, 'reversed' => true, 'card_name' => 'The Moon'],
            ],
        ], '2026-01-01 09:00:00', name: 'New moon', notes: "Line one, with a comma\nand \"quotes\"");
        $this->journal('spread0001', 'Will it last?', 'Robin', ['moon', 'monthly']);
        $this->insertReading('freedraw02', $this->userId, ['deck_id' => 1, 'draw' => [['card_id' => 79, 'reversed' => false]]], '2026-01-02 09:00:00');
        $this->db->exec("UPDATE readings SET is_private = 1 WHERE reading_id = 'freedraw02'");
        $this->insertReading('nocards03', $this->userId, ['deck_id' => 9, 'draw' => []], '2026-01-03 09:00:00', notes: 'Only notes');
        $this->insertReading('theirs0004', $this->createUser('other@example.test')->user_id, ['deck_id' => 1, 'draw' => [['card_id' => 1, 'reversed' => false]]]);
        $this->signInAs($this->userId);

        $response = $this->get('/account/readings/export?format=csv');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDownload($response, 'text/csv; charset=utf-8', 'csv');
        $body = (string) $response->getBody();
        // A UTF-8 byte-order mark, so Excel doesn't read it in a legacy code page.
        $this->assertStringStartsWith("\u{FEFF}\"Reading ID\",", $body);
        $this->assertSame([
            ['Reading ID', 'Reading Time (UTC)', 'Reading Name', 'Question', 'Querent', 'Tags', 'Privacy', 'Deck', 'Spread', 'Position', 'Position Title', 'Card', 'Reversed', 'Notes'],
            ['nocards03', '2026-01-03 09:00:00', '', '', '', '', 'Anyone with the link', 'Deck #9', 'Free Draw', '', '', '', '', 'Only notes'],
            ['freedraw02', '2026-01-02 09:00:00', '', '', '', '', 'Private', 'Rider-Waite', 'Free Draw', '1', '', 'Card #79', 'No', ''],
            ['spread0001', '2026-01-01 09:00:00', 'New moon', 'Will it last?', 'Robin', 'monthly, moon', 'Anyone with the link', 'Rider-Waite', 'Past, Present, Future', '1', 'Past', 'The Star', 'No', "Line one, with a comma\nand \"quotes\""],
            ['spread0001', '2026-01-01 09:00:00', 'New moon', 'Will it last?', 'Robin', 'monthly, moon', 'Anyone with the link', 'Rider-Waite', 'Past, Present, Future', '2', 'Present', 'The Moon', 'Yes', "Line one, with a comma\nand \"quotes\""],
        ], $this->csvRows($body));
    }

    public function testACsvCellThatStartsLikeAFormulaIsDefused(): void
    {
        $this->insertReading('formula001', $this->userId, [
            'deck_id' => 1,
            'spread'  => ['spread_id' => 0, 'name' => '+Spread', 'description' => '', 'positions' => [['order' => 1, 'title' => "\tTab", 'x' => 50, 'y' => 50, 'rotation' => 0]]],
            'draw'    => [['card_id' => 1, 'reversed' => false, 'card_name' => '@Card']],
        ], name: '=HYPERLINK("https://evil.example","Open me")', notes: '-1+2');
        $this->journal('formula001', '=1+1', '@me', ['+tag']);
        $this->signInAs($this->userId);

        $rows = $this->csvRows((string) $this->get('/account/readings/export?format=csv')->getBody());

        $this->assertSame(
            ["'=HYPERLINK(\"https://evil.example\",\"Open me\")", "'=1+1", "'@me", "'+tag", "'+Spread", "'\tTab", "'@Card", "'-1+2"],
            [$rows[1][2], $rows[1][3], $rows[1][4], $rows[1][5], $rows[1][8], $rows[1][10], $rows[1][11], $rows[1][13]]
        );
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        $this->signInAs($this->userId);

        $response = $this->get('/account/readings/export?format=xlsx');

        $this->assertSame(['error' => 'Choose an export format: json or csv.'], $this->assertJsonResponse(400, $response));
        $this->assertFalse($response->hasHeader('Content-Disposition'));
    }

    public function testALinkedPluginsTokenCannotExportTheJournal(): void
    {
        $this->insertReading('mine000001', $this->userId, ['deck_id' => 1, 'draw' => []]);
        $token = $this->service(PluginTokenService::class)->issueToken($this->userId);
        $bearer = ['Authorization' => "Bearer {$token}"];

        // The token reads the readings list it was given...
        $this->assertCount(1, $this->assertJsonResponse(200, $this->get('/account/readings', $bearer)));
        // ...but the whole journal is for a signed-in browser only.
        foreach (['json', 'csv'] as $format) {
            $refused = $this->get("/account/readings/export?format={$format}", $bearer);
            $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $refused));
        }
    }

    private function assertDownload(ResponseInterface $response, string $type, string $extension): void
    {
        $this->assertSame($type, $response->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="tarotgen-journal-' . Clock::today() . '.' . $extension . '"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * The CSV's rows, read the way a spreadsheet reads them (quoted cells may hold
     * line breaks), without the byte-order mark.
     *
     * @return list<list<string>>
     */
    private function csvRows(string $body): array
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($body, strlen("\u{FEFF}")));
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = array_map(strval(...), $row);
        }
        fclose($stream);

        return $rows;
    }

    /**
     * Give a stored reading its journal fields, as its owner would.
     *
     * @param list<string> $tags
     */
    private function journal(string $id, string $question, string $querent, array $tags): void
    {
        $this->db->prepare('UPDATE readings SET question = ?, querent = ? WHERE reading_id = ?')->execute([$question, $querent, $id]);
        foreach ($tags as $tag) {
            $this->db->prepare('INSERT INTO reading_tags (reading_id, tag) VALUES (?, ?)')->execute([$id, $tag]);
        }
    }

    /**
     * @param array<string,mixed> $info
     */
    private function insertReading(
        string $id,
        ?int $userId,
        array $info,
        string $time = '2026-01-01 09:00:00',
        ?string $name = null,
        ?string $notes = null,
        bool $final = false,
        ?string $password = null,
    ): void {
        $this->db->prepare(
            'INSERT INTO readings (reading_id, reading_info, reading_time, user_id, reading_name, reading_notes, is_final, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            json_encode($info, JSON_THROW_ON_ERROR),
            $time,
            $userId,
            $name,
            $notes,
            $final ? 1 : 0,
            $password === null ? null : password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
    }
}
