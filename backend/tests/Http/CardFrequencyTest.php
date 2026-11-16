<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\CardFrequencyController;
use Routes\Middleware\UserAuth;
use Tarot\Data\CardFrequencyData;
use Tarot\Repository\CardFrequencyRepository;
use Tarot\Service\PluginTokenService;
use Tarot\Structure\CardCount;
use Tarot\Structure\CardFrequency;
use Tarot\Utility\Clock;

/**
 * GET /account/card-frequency: which cards keep turning up in the signed-in
 * user's own readings, over every deck or one, all time or a recent window.
 */
#[CoversClass(CardFrequencyController::class)]
#[CoversClass(CardFrequencyRepository::class)]
#[CoversClass(CardFrequencyData::class)]
#[CoversClass(CardFrequency::class)]
#[CoversClass(CardCount::class)]
#[CoversClass(UserAuth::class)]
final class CardFrequencyTest extends HttpTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('reader@example.test')->user_id;
    }

    public function testAVisitorWhoIsNotSignedInIsTurnedAway(): void
    {
        $this->assertSame('Not authenticated', $this->assertProblem(401, $this->get('/account/card-frequency')));
    }

    public function testAPluginTokenCannotReachIt(): void
    {
        $token = $this->service(PluginTokenService::class)->issueToken($this->userId);

        $this->assertProblem(401, $this->get('/account/card-frequency', ['Authorization' => "Bearer {$token}"]));
    }

    public function testNothingDrawnYetCountsNothing(): void
    {
        $this->signInAs($this->userId);

        $response = $this->get('/account/card-frequency');
        $frequency = $this->assertJsonResponse(200, $response);

        $this->assertDocumented('get', '/account/card-frequency', 200, $frequency);
        $this->assertSame(['readings' => 0, 'deck_id' => null, 'days' => null, 'cards' => []], $frequency);
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testOnlyYourOwnReadingsAreCountedAcrossDecksByName(): void
    {
        $other = $this->createUser('other@example.test');
        $this->reading('mine000001', $this->userId, 1, [['card_id' => 16, 'reversed' => false, 'card_name' => 'The Tower'], ['card_id' => 79, 'reversed' => true]]);
        $this->reading('mine000002', $this->userId, 2, [['card_id' => 16, 'reversed' => true, 'card_name' => 'The Tower']]);
        $this->reading('theirs0003', $other->user_id, 1, [['card_id' => 16, 'reversed' => false, 'card_name' => 'The Tower'], ['card_id' => 17, 'reversed' => false, 'card_name' => 'The Star']]);
        $this->reading('guest00004', null, 1, [['card_id' => 17, 'reversed' => false, 'card_name' => 'The Star']]);
        $this->signInAs($this->userId);

        $frequency = $this->assertJsonResponse(200, $this->get('/account/card-frequency'));

        $this->assertDocumented('get', '/account/card-frequency', 200, $frequency);
        $this->assertSame(['readings' => 2, 'deck_id' => null, 'days' => null, 'cards' => [
            ['card_name' => 'The Tower', 'card_id' => null, 'deck_id' => null, 'count' => 2, 'upright' => 1, 'reversed' => 1],
            ['card_name' => 'Card #79', 'card_id' => 79, 'deck_id' => 1, 'count' => 1, 'upright' => 0, 'reversed' => 1],
        ]], $frequency);
    }

    public function testOneDeckIsCountedCardByCard(): void
    {
        $this->reading('mine000001', $this->userId, 1, [['card_id' => 16, 'reversed' => false, 'card_name' => 'The Tower']]);
        $this->reading('mine000002', $this->userId, 2, [['card_id' => 16, 'reversed' => true, 'card_name' => 'The Tower'], ['card_id' => 1, 'reversed' => false, 'card_name' => 'The Fool']]);
        $this->signInAs($this->userId);

        $frequency = $this->assertJsonResponse(200, $this->get('/account/card-frequency?deck_id=2'));

        $this->assertDocumented('get', '/account/card-frequency', 200, $frequency);
        $this->assertSame(['readings' => 1, 'deck_id' => 2, 'days' => null, 'cards' => [
            ['card_name' => 'The Fool', 'card_id' => 1, 'deck_id' => 2, 'count' => 1, 'upright' => 1, 'reversed' => 0],
            ['card_name' => 'The Tower', 'card_id' => 16, 'deck_id' => 2, 'count' => 1, 'upright' => 0, 'reversed' => 1],
        ]], $frequency);
    }

    public function testAWindowCountsOnlyTheRecentReadings(): void
    {
        $this->reading('recent0001', $this->userId, 1, [['card_id' => 17, 'reversed' => false, 'card_name' => 'The Star']], Clock::in(-5 * 86400));
        $this->reading('older00002', $this->userId, 1, [['card_id' => 16, 'reversed' => false, 'card_name' => 'The Tower']], Clock::in(-40 * 86400));
        $this->reading('oldest0003', $this->userId, 1, [['card_id' => 16, 'reversed' => false, 'card_name' => 'The Tower']], Clock::in(-400 * 86400));
        $this->signInAs($this->userId);

        $counts = fn(string $query): array => array_column($this->assertJsonResponse(200, $this->get('/account/card-frequency' . $query))['cards'], 'count', 'card_name');

        $this->assertSame(['The Star' => 1], $counts('?days=30'));
        $this->assertSame(['The Star' => 1, 'The Tower' => 1], $counts('?days=90'));
        $this->assertSame(['The Tower' => 2, 'The Star' => 1], $counts('?days=3650'));

        $year = $this->assertJsonResponse(200, $this->get('/account/card-frequency?days=365&deck_id=1'));
        $this->assertSame([2, 1, 365], [$year['readings'], $year['deck_id'], $year['days']]);
    }

    /** @return iterable<string,array{string,string}> */
    public static function badQueries(): iterable
    {
        $deck = 'deck_id must be a deck id (a whole number from 1).';
        $days = 'days must be a whole number from 1 to 3650.';

        yield 'deck zero' => ['deck_id=0', $deck];
        yield 'deck negative' => ['deck_id=-1', $deck];
        yield 'deck a word' => ['deck_id=tarot', $deck];
        yield 'deck a fraction' => ['deck_id=1.5', $deck];
        yield 'deck a list' => ['deck_id[]=1', $deck];
        yield 'deck too large' => ['deck_id=99999999999999999999', $deck];
        yield 'no days' => ['days=0', $days];
        yield 'too many days' => ['days=3651', $days];
        yield 'days a word' => ['days=month', $days];
        yield 'days with a sign' => ['days=+30', $days];
    }

    #[DataProvider('badQueries')]
    public function testParametersOutOfRangeAreRefused(string $query, string $error): void
    {
        $this->signInAs($this->userId);

        $this->assertSame($error, $this->assertProblem(422, $this->get("/account/card-frequency?{$query}")));
    }

    public function testEmptyParametersMeanEverything(): void
    {
        $this->reading('mine000001', $this->userId, 1, [['card_id' => 17, 'reversed' => false, 'card_name' => 'The Star']]);
        $this->signInAs($this->userId);

        $frequency = $this->assertJsonResponse(200, $this->get('/account/card-frequency?deck_id=&days='));

        $this->assertSame([1, null, null], [$frequency['readings'], $frequency['deck_id'], $frequency['days']]);
    }

    /** @param list<array<string,mixed>> $draw */
    private function reading(string $id, ?int $userId, int $deckId, array $draw, ?string $time = null): void
    {
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, reading_time, user_id) VALUES (?, ?, ?, ?)')
            ->execute([$id, json_encode(['deck_id' => $deckId, 'origin' => 'generated', 'draw' => $draw], JSON_THROW_ON_ERROR), $time ?? Clock::now(), $userId]);
    }
}
