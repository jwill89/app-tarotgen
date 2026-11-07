<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\AccountController;
use Tarot\Data\ReadingData;
use Tarot\Repository\ReadingRepository;
use Tarot\Service\PluginTokenService;
use Tarot\Service\ReadingInput;
use Tarot\Structure\ReadingSpread;

/**
 * Finding a reading in My Readings (AUDIT §9.8): GET /account/readings narrowed
 * by text, deck, spread, tag, the reader's own dates and daily cards, each alone
 * and together, paged with the matching total — and a filter that can't be one
 * refused with a message, not ignored. GET /account/reading-spreads lists the
 * spreads the spread filter can find, each with what it finds.
 */
#[CoversClass(AccountController::class)]
#[CoversClass(ReadingInput::class)]
#[CoversClass(ReadingRepository::class)]
#[CoversClass(ReadingData::class)]
#[CoversClass(ReadingSpread::class)]
final class AccountReadingFiltersTest extends HttpTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('reader@example.test')->user_id;
        $other = $this->createUser('other@example.test')->user_id;

        // Times are UTC, as stored.
        $this->insert('celtic', $this->userId, '2026-03-01 10:00:00', ['deck_id' => 1, 'spread' => self::spread(3, 'Celtic Cross')], name: 'Career check', tags: ['career']);
        $this->insert('mine', $this->userId, '2026-03-05 23:30:00', ['deck_id' => 2, 'spread' => self::spread(0, 'My Own Spread')], querent: 'Alex');
        $this->insert('free', $this->userId, '2026-03-10 08:00:00', ['deck_id' => 1], question: 'Will it rain?', tags: ['career', 'weather']);
        $this->insert('notes', $this->userId, '2026-03-20 08:00:00', ['deck_id' => 2], notes: 'The Tower again.');
        $this->insert('theirs', $other, '2026-03-10 09:00:00', ['deck_id' => 1], name: 'Career', tags: ['career']);
        $this->db->exec("INSERT INTO daily_cards (user_id, day, reading_id) VALUES ({$this->userId}, '2026-03-10', 'free')");

        $this->signInAs($this->userId);
    }

    /**
     * @return iterable<string,array{string,list<string>}>
     */
    public static function filters(): iterable
    {
        yield 'nothing' => ['', ['notes', 'free', 'mine', 'celtic']];
        yield 'text in a name, any case' => ['q=CAREER', ['celtic']];
        yield 'text in the notes' => ['q=tower', ['notes']];
        yield 'text in a question' => ['q=rain', ['free']];
        yield 'text in a querent' => ['q=alex', ['mine']];
        yield 'deck' => ['deck_id=2', ['notes', 'mine']];
        yield 'spread by name' => ['spread=celtic+cross', ['celtic']];
        yield 'a personal spread by name' => ['spread=My+Own+Spread', ['mine']];
        yield 'free draws' => ['spread=Free+Draw', ['notes', 'free']];
        yield 'tag, normalised' => ['tag=%23Career', ['free', 'celtic']];
        yield 'from a day' => ['from=2026-03-10', ['notes', 'free']];
        yield 'to a day, included' => ['to=2026-03-05', ['mine', 'celtic']];
        yield 'a range' => ['from=2026-03-02&to=2026-03-10', ['free', 'mine']];
        // 23:30 UTC on the 5th is the 6th in Auckland (UTC+13).
        yield "the reader's own dates" => ['from=2026-03-06&to=2026-03-06&tz=Pacific/Auckland', ['mine']];
        yield 'daily cards only' => ['daily=only', ['free']];
        yield 'no daily cards' => ['daily=exclude', ['notes', 'mine', 'celtic']];
        yield 'blank filters are none' => ['q=&deck_id=&tag=&from=&to=&daily=&spread=', ['notes', 'free', 'mine', 'celtic']];
        yield 'combined' => ['tag=career&deck_id=1&daily=exclude', ['celtic']];
        yield 'combined, nothing left' => ['q=career&deck_id=2', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('filters')]
    public function testEachFilterAloneAndCombined(string $query, array $expected): void
    {
        $response = $this->get('/account/readings?limit=25&' . $query);

        $list = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('get', '/account/readings', 200, $list);
        $this->assertSame($expected, array_column($list, 'reading_id'));
        // The total is of what matched, not of every reading.
        $this->assertSame((string) count($expected), $response->getHeaderLine('X-Total-Count'));
    }

    public function testAFilteredListStillPages(): void
    {
        $first = $this->get('/account/readings?limit=1&tag=career');
        $this->assertSame(['free'], array_column($this->assertJsonResponse(200, $first), 'reading_id'));
        $this->assertSame('2', $first->getHeaderLine('X-Total-Count'));

        $second = $this->get('/account/readings?limit=1&offset=1&tag=career');
        $this->assertSame(['celtic'], array_column($this->assertJsonResponse(200, $second), 'reading_id'));
    }

    public function testTheWholeListCanBeFilteredToo(): void
    {
        $response = $this->get('/account/readings?deck_id=1');

        $this->assertSame(['free', 'celtic'], array_column($this->assertJsonResponse(200, $response), 'reading_id'));
        $this->assertFalse($response->hasHeader('X-Total-Count'));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function badFilters(): iterable
    {
        yield 'deck id' => ['deck_id=abc', "deck_id must be a deck's id."];
        yield 'deck id zero' => ['deck_id=0', "deck_id must be a deck's id."];
        yield 'from' => ['from=2026-02-30', 'from must be a date, as YYYY-MM-DD.'];
        yield 'to' => ['to=yesterday', 'to must be a date, as YYYY-MM-DD.'];
        yield 'backwards' => ['from=2026-03-10&to=2026-03-01', 'from must be on or before to.'];
        yield 'time zone' => ['from=2026-03-01&tz=Mars/Olympus', 'tz must be a time zone, such as Europe/London.'];
        yield 'daily' => ['daily=sometimes', 'daily must be only or exclude.'];
        yield 'a list' => ['tag[]=career&tag[]=love', 'tag must be sent once, as text.'];
    }

    #[DataProvider('badFilters')]
    public function testAFilterThatCannotBeOneIsRefused(string $query, string $error): void
    {
        $this->assertSame(['error' => $error], $this->assertJsonResponse(400, $this->get('/account/readings?limit=25&' . $query)));
    }

    public function testTheSpreadListIsYourOwnByNameWithCounts(): void
    {
        $this->addSpreadVariety();

        $response = $this->get('/account/reading-spreads');
        $spreads = $this->assertJsonResponse(200, $response);

        $this->assertDocumented('get', '/account/reading-spreads', 200, $spreads);
        // Alphabetical; a name in another case or with spaces around it is the
        // same spread; free draws never placed are "Free Draw"; a spread with no
        // name, a broken reading and the other reader's spreads are left out.
        $this->assertSame([
            ['name' => 'Celtic Cross', 'count' => 2],
            ['name' => 'Free Draw', 'count' => 2],
            ['name' => 'My Own Spread', 'count' => 1],
            ['name' => 'Past, Present, Future', 'count' => 1],
            ['name' => self::longName(), 'count' => 1],
        ], $spreads);
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testEverySpreadListedFindsItsReadings(): void
    {
        $this->addSpreadVariety();

        $spreads = $this->assertJsonResponse(200, $this->get('/account/reading-spreads'));

        $this->assertCount(5, $spreads);
        foreach ($spreads as $spread) {
            $this->assertIsArray($spread);
            $response = $this->get('/account/readings?limit=25&spread=' . rawurlencode((string) $spread['name']));
            $this->assertJsonResponse(200, $response);
            $this->assertSame((string) $spread['count'], $response->getHeaderLine('X-Total-Count'), (string) $spread['name']);
        }
    }

    public function testNoReadingsNoSpreads(): void
    {
        $this->signInAs($this->createUser('new@example.test')->user_id);

        $spreads = $this->assertJsonResponse(200, $this->get('/account/reading-spreads'));

        $this->assertSame([], $spreads);
        $this->assertDocumented('get', '/account/reading-spreads', 200, $spreads);
    }

    public function testTheSpreadListIsForASignedInBrowserOnly(): void
    {
        $this->useSessionCookie(null);
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/account/reading-spreads')));

        $bearer = ['Authorization' => 'Bearer ' . $this->service(PluginTokenService::class)->issueToken($this->userId)];
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/account/reading-spreads', $bearer)));
    }

    /**
     * Beside setUp's readings: the same spreads named apart, a personal spread
     * longer than the filter once kept (100 characters), readings with no spread
     * name to find them by, and another reader's spread.
     */
    private function addSpreadVariety(): void
    {
        $this->insert('lower', $this->userId, '2026-03-02 10:00:00', ['deck_id' => 1, 'spread' => self::spread(3, 'celtic cross')]);
        $this->insert('padded', $this->userId, '2026-03-03 10:00:00', ['deck_id' => 1, 'spread' => self::spread(4, '  Past, Present, Future ')]);
        $this->insert('long', $this->userId, '2026-03-04 10:00:00', ['deck_id' => 1, 'spread' => self::spread(0, self::longName())]);
        $this->insert('nameless', $this->userId, '2026-03-06 10:00:00', ['deck_id' => 1, 'spread' => ['spread_id' => 0, 'positions' => []]]);
        $this->insert('blank', $this->userId, '2026-03-07 10:00:00', ['deck_id' => 1, 'spread' => self::spread(0, '   ')]);
        $this->db->prepare("INSERT INTO readings (reading_id, reading_info, reading_time, user_id) VALUES ('broken', '{not json', '2026-03-08 10:00:00', ?)")
            ->execute([$this->userId]);
        $this->insert('horseshoe', $this->createUser('third@example.test')->user_id, '2026-03-09 10:00:00', ['deck_id' => 1, 'spread' => self::spread(5, 'Horseshoe')]);
    }

    /** A personal spread's name of 140 characters (they keep up to 200). */
    private static function longName(): string
    {
        return 'The ' . str_repeat('very ', 25) . 'long spread';
    }

    /**
     * A spread snapshot as a reading stores one (personal spreads have id 0).
     *
     * @return array<string,mixed>
     */
    private static function spread(int $id, string $name): array
    {
        return ['spread_id' => $id, 'name' => $name, 'description' => '', 'positions' => []];
    }

    /**
     * @param array<string,mixed> $info
     * @param list<string>        $tags
     */
    private function insert(
        string $id,
        int $userId,
        string $time,
        array $info,
        ?string $name = null,
        ?string $notes = null,
        ?string $question = null,
        ?string $querent = null,
        array $tags = [],
    ): void {
        $this->db->prepare(
            'INSERT INTO readings (reading_id, reading_info, reading_time, user_id, reading_name, reading_notes, question, querent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, json_encode($info + ['draw' => []], JSON_THROW_ON_ERROR), $time, $userId, $name, $notes, $question, $querent]);
        foreach ($tags as $tag) {
            $this->db->prepare('INSERT INTO reading_tags (reading_id, tag) VALUES (?, ?)')->execute([$id, $tag]);
        }
    }
}
