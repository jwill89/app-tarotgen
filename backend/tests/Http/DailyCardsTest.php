<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Routes\Internal\DailyCardController;
use Routes\Middleware\UserAuth;
use Tarot\Data\DailyCardData;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Exception\DailyCardTakenException;
use Tarot\Repository\DailyCardRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\DailyCardService;
use Tarot\Service\PluginTokenService;
use Tarot\Structure\DailyCard;

/**
 * The daily card: drawing the card for the user's day (a one-card reading of
 * their own), the one-a-day rule and the day window, the month calendar, how a
 * daily card shows in My Readings, and a day staying drawn once its reading is
 * deleted.
 */
#[CoversClass(DailyCardController::class)]
#[CoversClass(DailyCardService::class)]
#[CoversClass(DailyCardRepository::class)]
#[CoversClass(DailyCardData::class)]
#[CoversClass(DailyCard::class)]
#[CoversClass(DailyCardTakenException::class)]
#[CoversClass(UserAuth::class)]
final class DailyCardsTest extends HttpTestCase
{
    private const array CARD_NAMES = ['The Fool', 'The Magician', 'The High Priestess'];

    private int $userId;

    private int $deckId;

    protected function setUp(): void
    {
        parent::setUp();

        $systems = $this->service(DeckSystemData::class);
        $system = $systems->store(['name' => 'Little Arcana', 'short_name' => 'LA', 'total_cards' => count(self::CARD_NAMES)]);
        $this->assertNotNull($system);
        $systems->storeCards($system->deck_system_id, array_map(
            static fn(int $i): array => ['card_id' => $i + 1, 'name' => self::CARD_NAMES[$i]],
            array_keys(self::CARD_NAMES)
        ));
        $deck = $this->service(DeckData::class)->store(['name' => 'Moonlit Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;

        $this->userId = $this->createUser('reader@example.test')->user_id;
    }

    /** UTC today moved by $days, as "Y-m-d". */
    private static function day(int $days = 0): string
    {
        return new DateTimeImmutable('today', new DateTimeZone('UTC'))->modify("{$days} days")->format('Y-m-d');
    }

    /** @param array<string,mixed> $extra */
    private function draw(string $day, array $extra = []): ResponseInterface
    {
        return $this->post('/account/daily-cards', ['day' => $day, 'deck_id' => $this->deckId, ...$extra]);
    }

    public function testAVisitorWhoIsNotSignedInIsTurnedAway(): void
    {
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/account/daily-cards?month=2026-10')));
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->draw(self::day())));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testAPluginTokenCannotReachTheDailyCard(): void
    {
        $token = $this->service(PluginTokenService::class)->issueToken($this->userId);
        $bearer = ['Authorization' => "Bearer {$token}"];

        // Session only: the plugin doesn't use the daily card, so its token doesn't open it.
        $this->assertJsonResponse(401, $this->get('/account/daily-cards?month=2026-10', $bearer));
        $this->assertJsonResponse(401, $this->post('/account/daily-cards', ['day' => self::day(), 'deck_id' => $this->deckId], $bearer));
        $this->assertSame(0, $this->rowCount('daily_cards'));
    }

    public function testDrawingTheDaysCardMakesAOneCardReadingOfYourOwn(): void
    {
        $this->signInAs($this->userId);
        $today = self::day();

        $card = $this->assertJsonResponse(201, $this->draw($today, ['use_reversals' => false]));

        $this->assertDocumented('post', '/account/daily-cards', 201, $card);
        $this->assertSame([$today, $this->deckId, 'Moonlit Deck', false], [$card['day'], $card['deck_id'], $card['deck_name'], $card['reversed']]);
        $this->assertContains($card['card_name'], self::CARD_NAMES);
        $this->assertSame(self::CARD_NAMES[$card['card_id'] - 1], $card['card_name']);

        $rows = $this->rows('SELECT reading_id, user_id, reading_name, reading_info FROM readings');
        $this->assertCount(1, $rows);
        $this->assertSame($card['reading_id'], $rows[0]['reading_id']);
        $this->assertSame($this->userId, $rows[0]['user_id']);
        $named = new DateTimeImmutable($today)->format('j F Y');
        $this->assertSame("Daily card — {$named}", $rows[0]['reading_name']);
        $info = json_decode((string) $rows[0]['reading_info'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($info);
        $this->assertSame([$this->deckId, 'generated', 1], [$info['deck_id'], $info['origin'], count($info['draw'])]);
        $this->assertSame(
            [['user_id' => $this->userId, 'day' => $today, 'reading_id' => $card['reading_id']]],
            $this->rows('SELECT user_id, day, reading_id FROM daily_cards')
        );

        // It is an ordinary reading of the user's own, but one card: no drawing more.
        $reading = $this->assertJsonResponse(200, $this->get('/readings/' . $card['reading_id']));
        $this->assertTrue($reading['is_owner']);
        $this->assertTrue($reading['is_final']);
        $this->assertFalse($reading['can_draw_more']);
        $this->assertSame(409, $this->post('/readings/' . $card['reading_id'] . '/draw', ['count' => 1])->getStatusCode());
    }

    public function testASecondDrawForTheDayAnswersWithTheFirst(): void
    {
        $this->signInAs($this->userId);
        $first = $this->assertJsonResponse(201, $this->draw(self::day()));

        $again = $this->assertJsonResponse(409, $this->draw(self::day(), ['use_reversals' => true]));

        $this->assertDocumented('post', '/account/daily-cards', 409, $again);
        $this->assertSame(['error' => 'You have already drawn your daily card for this day.', 'reading_id' => $first['reading_id']], $again);
        $this->assertSame(1, $this->rowCount('readings'));
    }

    public function testEachAccountHasItsOwnDay(): void
    {
        $other = $this->createUser('other@example.test');
        $this->signInAs($other->user_id);
        $theirs = $this->assertJsonResponse(201, $this->draw(self::day()));

        $this->useSessionCookie(null);
        $this->signInAs($this->userId);
        $mine = $this->assertJsonResponse(201, $this->draw(self::day()));

        $this->assertNotSame($theirs['reading_id'], $mine['reading_id']);
        $this->assertSame([$mine], $this->assertJsonResponse(200, $this->get('/account/daily-cards?month=' . substr(self::day(), 0, 7))));
    }

    public function testADayEitherSideOfTheUtcDateIsAccepted(): void
    {
        // Somewhere it is already tomorrow (UTC+14) or still yesterday (UTC−12).
        $this->signInAs($this->userId);

        foreach ([-1, 1] as $offset) {
            $card = $this->assertJsonResponse(201, $this->draw(self::day($offset)));
            $this->assertSame(self::day($offset), $card['day']);
        }
        $this->assertSame(2, $this->rowCount('daily_cards'));
    }

    /** @return iterable<string,array{mixed,string}> */
    public static function badDays(): iterable
    {
        $notADate = 'day must be a date, as YYYY-MM-DD.';
        $notToday = "A daily card can only be drawn for today. Check your device's date.";

        yield 'two days ahead' => [self::day(2), $notToday];
        yield 'two days back' => [self::day(-2), $notToday];
        yield 'last year' => [self::day(-365), $notToday];
        yield 'no such month' => ['2026-13-01', $notADate];
        yield 'no such day' => ['2026-02-30', $notADate];
        yield 'not zero-padded' => ['2026-1-5', $notADate];
        yield 'a word' => ['today', $notADate];
        yield 'empty' => ['', $notADate];
        yield 'a number' => [20261030, $notADate];
        yield 'missing' => [null, $notADate];
    }

    #[DataProvider('badDays')]
    public function testADayFarFromTodayOrNotADateIsRefused(mixed $day, string $error): void
    {
        $this->signInAs($this->userId);

        $body = ['deck_id' => $this->deckId];
        if ($day !== null) {
            $body['day'] = $day;
        }

        $this->assertSame(['error' => $error], $this->assertJsonResponse(400, $this->post('/account/daily-cards', $body)));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testTheDeckMustBeOneThatCanBeDrawnFrom(): void
    {
        $pending = $this->service(DeckData::class)->store(['name' => 'Pending Deck', 'approved' => false]);
        $this->assertNotNull($pending);
        $this->signInAs($this->userId);

        $choose = ['error' => 'Choose a deck to draw from.'];
        $this->assertSame($choose, $this->assertJsonResponse(400, $this->post('/account/daily-cards', ['day' => self::day()])));
        $this->assertSame($choose, $this->assertJsonResponse(400, $this->draw(self::day(), ['deck_id' => 0])));
        $this->assertSame($choose, $this->assertJsonResponse(400, $this->draw(self::day(), ['deck_id' => 'three'])));
        $this->assertSame(['error' => 'InvalidDeckID'], $this->assertJsonResponse(400, $this->draw(self::day(), ['deck_id' => $this->deckId + 100])));
        $this->assertSame(['error' => 'InvalidDeckID'], $this->assertJsonResponse(400, $this->draw(self::day(), ['deck_id' => $pending->deck_id])));
        $this->assertSame(0, $this->rowCount('readings'));

        // A deck id sent as a numeric string is still a deck id.
        $this->assertJsonResponse(201, $this->draw(self::day(), ['deck_id' => (string) $this->deckId]));
    }

    public function testTheMonthListsTheDaysDrawn(): void
    {
        $other = $this->createUser('other@example.test');
        $this->dailyCard('sep30', $this->userId, '2026-09-30', ['card_id' => 1, 'reversed' => false, 'card_name' => 'The Fool']);
        $this->dailyCard('oct31', $this->userId, '2026-10-31', ['card_id' => 79, 'reversed' => true], deckId: 99);
        $this->dailyCard('oct01', $this->userId, '2026-10-01', ['card_id' => 2, 'reversed' => false, 'card_name' => 'The Magician']);
        $this->dailyCard('nov01', $this->userId, '2026-11-01', ['card_id' => 3, 'reversed' => false, 'card_name' => 'The High Priestess']);
        $this->dailyCard('theirs', $other->user_id, '2026-10-15', ['card_id' => 3, 'reversed' => false, 'card_name' => 'The High Priestess']);
        // A day whose reading was deleted since.
        $this->db->prepare('INSERT INTO daily_cards (user_id, day, reading_id) VALUES (?, ?, NULL)')->execute([$this->userId, '2026-10-20']);
        $this->signInAs($this->userId);

        $response = $this->get('/account/daily-cards?month=2026-10');
        $october = $this->assertJsonResponse(200, $response);

        $this->assertDocumented('get', '/account/daily-cards', 200, $october);
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame([
            ['day' => '2026-10-01', 'reading_id' => 'oct01', 'deck_id' => $this->deckId, 'deck_name' => 'Moonlit Deck', 'card_id' => 2, 'card_name' => 'The Magician', 'reversed' => false],
            // Still drawn, but with its reading gone there is no card to show.
            ['day' => '2026-10-20', 'reading_id' => null, 'deck_id' => null, 'deck_name' => null, 'card_id' => null, 'card_name' => null, 'reversed' => null],
            // A card its deck never named, from a deck deleted since.
            ['day' => '2026-10-31', 'reading_id' => 'oct31', 'deck_id' => 99, 'deck_name' => 'Deck #99', 'card_id' => 79, 'card_name' => 'Card #79', 'reversed' => true],
        ], $october);
        $this->assertSame([], $this->assertJsonResponse(200, $this->get('/account/daily-cards?month=2026-08')));
    }

    /** @return iterable<string,array{string}> */
    public static function badMonths(): iterable
    {
        yield 'missing' => [''];
        yield 'empty' => ['?month='];
        yield 'no such month' => ['?month=2026-13'];
        yield 'not zero-padded' => ['?month=2026-1'];
        yield 'a whole date' => ['?month=2026-10-30'];
        yield 'a list' => ['?month[]=2026-10'];
    }

    #[DataProvider('badMonths')]
    public function testTheMonthMustBeAMonth(string $query): void
    {
        $this->signInAs($this->userId);

        $this->assertSame(['error' => 'month must be a month, as YYYY-MM.'], $this->assertJsonResponse(400, $this->get('/account/daily-cards' . $query)));
    }

    public function testMyReadingsMarksTheDailyCard(): void
    {
        $this->signInAs($this->userId);
        $this->db->exec("INSERT INTO readings (reading_id, reading_info, reading_time, user_id) VALUES ('plain00001', '{\"deck_id\":1,\"draw\":[]}', '2020-01-01 00:00:00', {$this->userId})");
        $daily = $this->assertJsonResponse(201, $this->draw(self::day()));

        $list = $this->assertJsonResponse(200, $this->get('/account/readings?limit=10'));

        $this->assertDocumented('get', '/account/readings', 200, $list);
        $this->assertSame(
            [$daily['reading_id'] => self::day(), 'plain00001' => null],
            array_column($list, 'daily_card_day', 'reading_id')
        );
    }

    public function testDeletingTheReadingKeepsTheDayTaken(): void
    {
        $this->signInAs($this->userId);
        $first = $this->assertJsonResponse(201, $this->draw(self::day()));

        $this->assertSame(204, $this->request('DELETE', '/account/readings/' . $first['reading_id'])->getStatusCode());

        // No redrawing until a card suits: the day stays drawn, with no reading.
        $this->assertSame([['day' => self::day(), 'reading_id' => null]], $this->rows('SELECT day, reading_id FROM daily_cards'));
        $again = $this->assertJsonResponse(409, $this->draw(self::day()));
        $this->assertDocumented('post', '/account/daily-cards', 409, $again);
        $this->assertSame(['error' => 'You have already drawn your daily card for this day, and its reading was deleted.', 'reading_id' => null], $again);
        $this->assertSame(0, $this->rowCount('readings'));

        $month = $this->assertJsonResponse(200, $this->get('/account/daily-cards?month=' . substr(self::day(), 0, 7)));
        $this->assertDocumented('get', '/account/daily-cards', 200, $month);
        $this->assertSame([[
            'day' => self::day(), 'reading_id' => null, 'deck_id' => null, 'deck_name' => null,
            'card_id' => null, 'card_name' => null, 'reversed' => null,
        ]], $month);
    }

    public function testAnAdminDeletingTheReadingKeepsTheDayTaken(): void
    {
        $this->signInAs($this->userId);
        $card = $this->assertJsonResponse(201, $this->draw(self::day()));

        $this->useSessionCookie(null);
        $this->signInAs($this->createUser('admin@example.test', admin: true)->user_id);
        $this->assertSame(204, $this->request('DELETE', '/admin/readings/' . $card['reading_id'])->getStatusCode());

        $this->assertSame([['user_id' => $this->userId, 'reading_id' => null]], $this->rows('SELECT user_id, reading_id FROM daily_cards'));
        $this->useSessionCookie(null);
        $this->signInAs($this->userId);
        $this->assertNull($this->assertJsonResponse(409, $this->draw(self::day()))['reading_id']);
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testDeletingTheAccountTakesItsDays(): void
    {
        $this->signInAs($this->userId);
        $this->assertJsonResponse(201, $this->draw(self::day()));
        $deleted = $this->assertJsonResponse(201, $this->draw(self::day(-1)));
        $this->assertSame(204, $this->request('DELETE', '/account/readings/' . $deleted['reading_id'])->getStatusCode());
        $this->assertSame(2, $this->rowCount('daily_cards'));

        $this->service(UserRepository::class)->delete($this->userId);

        // The day kept, its reading deleted, goes with the account too.
        $this->assertSame(0, $this->rowCount('daily_cards'));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testADailyCardCountsTowardCardFrequency(): void
    {
        $this->signInAs($this->userId);
        $card = $this->assertJsonResponse(201, $this->draw(self::day()));

        $frequency = $this->assertJsonResponse(200, $this->get('/account/card-frequency'));

        $this->assertSame(1, $frequency['readings']);
        $this->assertSame([$card['card_name']], array_column($frequency['cards'], 'card_name'));
    }

    /** @param array<string,mixed> $card */
    private function dailyCard(string $readingId, int $userId, string $day, array $card, ?int $deckId = null): void
    {
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, user_id) VALUES (?, ?, ?)')
            ->execute([$readingId, json_encode(['deck_id' => $deckId ?? $this->deckId, 'origin' => 'generated', 'draw' => [$card]], JSON_THROW_ON_ERROR), $userId]);
        $this->db->prepare('INSERT INTO daily_cards (user_id, day, reading_id) VALUES (?, ?, ?)')
            ->execute([$userId, $day, $readingId]);
    }
}
