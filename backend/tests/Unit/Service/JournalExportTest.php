<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tarot\Data\DeckData;
use Tarot\Data\ReadingData;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Service\JournalExport;

/**
 * The journal download on the production schema in memory: how a reading's
 * cards are paired with their positions and named, what a damaged reading or an
 * empty journal comes out as, and the CSV cells a spreadsheet can't run.
 */
#[CoversClass(JournalExport::class)]
final class JournalExportTest extends TestCase
{
    private PDO $db;

    private JournalExport $export;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $this->db->exec("INSERT INTO users (user_id, email, password_hash, display_name) VALUES (1, 'one@example.test', '', 'One')");
        $this->db->exec("INSERT INTO decks (deck_id, name) VALUES (1, 'Rider-Waite')");

        $this->export = new JournalExport(
            new ReadingRepository(new ReadingData($this->db)),
            new DeckRepository(new DeckData($this->db))
        );
    }

    public function testCardsArePairedWithTheirPositionsByOrderAndNamedAsThePageNamesThem(): void
    {
        $this->insertReading('spread0001', [
            'deck_id' => 1,
            'spread'  => [
                'spread_id'   => 0,
                'name'        => 'Two Paths',
                'description' => '',
                // Out of draw order: a position holds the card drawn in its `order`.
                'positions'   => [
                    ['order' => 2, 'title' => 'Right', 'x' => 75, 'y' => 50, 'rotation' => 0],
                    ['order' => 1, 'title' => 'Left', 'x' => 25, 'y' => 50, 'rotation' => 0],
                ],
            ],
            'draw' => [
                ['card_id' => 1, 'reversed' => false, 'card_name' => 'The Fool'],
                ['card_id' => 79, 'reversed' => true],
                // Drawn after the layout was placed: no position yet.
                ['card_id' => 3, 'reversed' => false, 'card_name' => 'The High Priestess'],
            ],
        ]);

        $cards = $this->journal()['readings'][0]['cards'];

        $this->assertSame([
            ['position' => 1, 'position_title' => 'Left', 'card_id' => 1, 'card_name' => 'The Fool', 'reversed' => false],
            ['position' => 2, 'position_title' => 'Right', 'card_id' => 79, 'card_name' => 'Card #79', 'reversed' => true],
            ['position' => 3, 'position_title' => null, 'card_id' => 3, 'card_name' => 'The High Priestess', 'reversed' => false],
        ], $cards);
    }

    public function testADamagedReadingStillComesOutRatherThanSinkingTheExport(): void
    {
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, user_id) VALUES (?, ?, 1)')
            ->execute(['broken0001', '{not json']);
        $this->insertReading('fine000002', ['deck_id' => 1, 'draw' => ['not a card', ['card_id' => 5, 'reversed' => false]]]);

        $readings = array_column($this->journal()['readings'], null, 'reading_id');

        $this->assertSame([0, 'Deck #0', null, []], [
            $readings['broken0001']['deck_id'],
            $readings['broken0001']['deck_name'],
            $readings['broken0001']['spread'],
            $readings['broken0001']['cards'],
        ]);
        // A stray entry in the draw is skipped without shifting the others' places.
        $this->assertSame([['position' => 2, 'position_title' => null, 'card_id' => 5, 'card_name' => 'Card #5', 'reversed' => false]], $readings['fine000002']['cards']);
    }

    public function testAnEmptyJournalIsStillAWholeDocument(): void
    {
        $journal = $this->journal();

        $this->assertSame([], $journal['readings']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $journal['exported_at']);
        $this->assertSame("\u{FEFF}" . '"Reading ID","Reading Time (UTC)","Reading Name","Question","Querent","Tags","Privacy","Deck","Spread","Position","Position Title","Card","Reversed","Notes"' . "\r\n", implode('', iterator_to_array($this->export->csv(1), false)));
    }

    public function testTheJournalFieldsAndPrivacyComeAlong(): void
    {
        $this->insertReading('journal001', ['deck_id' => 1, 'draw' => [['card_id' => 1, 'reversed' => false, 'card_name' => 'The Fool']]]);
        $this->db->exec("UPDATE readings SET question = 'Should I move?', querent = 'Sam', is_private = 1 WHERE reading_id = 'journal001'");
        $this->db->exec("INSERT INTO reading_tags (reading_id, tag) VALUES ('journal001', 'move'), ('journal001', 'career')");
        $this->insertReading('locked0002', ['deck_id' => 1, 'draw' => []], '2026-01-02 09:00:00');
        $this->db->exec("UPDATE readings SET password_hash = 'x' WHERE reading_id = 'locked0002'");

        $readings = array_column($this->journal()['readings'], null, 'reading_id');
        $this->assertSame(
            ['Should I move?', 'Sam', ['career', 'move'], 'private', false],
            [$readings['journal001']['question'], $readings['journal001']['querent'], $readings['journal001']['tags'], $readings['journal001']['visibility'], $readings['journal001']['password_protected']]
        );
        $this->assertSame([null, null, [], 'password'], [$readings['locked0002']['question'], $readings['locked0002']['querent'], $readings['locked0002']['tags'], $readings['locked0002']['visibility']]);

        $rows = array_map(
            static fn(string $line): array => str_getcsv($line, ',', '"', ''),
            explode("\r\n", trim(substr(implode('', iterator_to_array($this->export->csv(1), false)), 3)))
        );
        $this->assertSame(['Should I move?', 'Sam', 'career, move', 'Private'], array_slice($rows[2], 3, 4));
        $this->assertSame(['', '', '', 'Password'], array_slice($rows[1], 3, 4));
    }

    public function testTheJsonIsPrettyPrintedForReading(): void
    {
        $this->insertReading('first00001', ['deck_id' => 1, 'draw' => [['card_id' => 1, 'reversed' => false]]], '2026-01-01 09:00:00');
        $this->insertReading('second0002', ['deck_id' => 1, 'draw' => [['card_id' => 2, 'reversed' => false]]], '2026-01-02 09:00:00');

        $json = implode('', iterator_to_array($this->export->json(1), false));

        $this->assertStringContainsString("\n        {\n            \"reading_id\": \"second0002\",", $json);
        $this->assertStringContainsString("\n        },\n        {\n            \"reading_id\": \"first00001\",", $json);
        $this->assertStringEndsWith("\n    ]\n}\n", $json);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function cells(): iterable
    {
        yield 'plain text' => ['The Fool', '"The Fool"'];
        yield 'empty' => ['', '""'];
        yield 'quotes doubled' => ['The "Fool"', '"The ""Fool"""'];
        yield 'commas and line breaks kept inside the quotes' => ["a, b\r\nc", "\"a, b\r\nc\""];
        yield 'equals' => ['=HYPERLINK("http://evil.example","x")', '"\'=HYPERLINK(""http://evil.example"",""x"")"'];
        yield 'plus' => ['+1+1', '"\'+1+1"'];
        yield 'minus' => ['-2+3', '"\'-2+3"'];
        yield 'at' => ['@SUM(A1)', '"\'@SUM(A1)"'];
        yield 'tab' => ["\t=1", "\"'\t=1\""];
        yield 'carriage return' => ["\r=1", "\"'\r=1\""];
        yield 'a formula character later on is harmless' => ['Ace = beginnings', '"Ace = beginnings"'];
    }

    #[DataProvider('cells')]
    public function testACsvCellIsQuotedAndCannotRunAsAFormula(string $value, string $cell): void
    {
        $this->assertSame($cell, JournalExport::csvCell($value));
    }

    /**
     * @return array{exported_at: string, readings: list<array<string,mixed>>}
     */
    private function journal(): array
    {
        $decoded = json_decode(implode('', iterator_to_array($this->export->json(1), false)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertIsString($decoded['exported_at']);
        $this->assertIsArray($decoded['readings']);

        /** @var array{exported_at: string, readings: list<array<string,mixed>>} $decoded */
        return $decoded;
    }

    /**
     * @param array<string,mixed> $info
     */
    private function insertReading(string $id, array $info, string $time = '2026-01-01 09:00:00'): void
    {
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, reading_time, user_id) VALUES (?, ?, ?, 1)')
            ->execute([$id, json_encode($info, JSON_THROW_ON_ERROR), $time]);
    }
}
