<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Tarot\Exception\ApiException;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Service\CardNameResolver;
use Tarot\Service\ReadingService;
use Tarot\Structure\Deck;
use Tarot\Structure\Reading;
use Tarot\Structure\Spread;
use Tarot\Structure\UserSpread;

#[CoversClass(ReadingService::class)]
final class ReadingServiceTest extends TestCase
{
    private ReadingRepository&Stub $readings;
    private DeckRepository&Stub $decks;
    private SpreadRepository&Stub $spreads;
    private UserSpreadRepository&Stub $userSpreads;
    private CardNameResolver&Stub $cardNames;

    protected function setUp(): void
    {
        // These collaborators are used purely as stubs (we assert on the
        // returned Reading, not on how they are called), so createStub() is the
        // right tool.
        $this->readings    = $this->createStub(ReadingRepository::class);
        $this->decks       = $this->createStub(DeckRepository::class);
        $this->spreads     = $this->createStub(SpreadRepository::class);
        $this->userSpreads = $this->createStub(UserSpreadRepository::class);
        $this->cardNames   = $this->createStub(CardNameResolver::class);
    }

    private function service(): ReadingService
    {
        return new ReadingService(
            $this->readings,
            $this->decks,
            $this->spreads,
            $this->userSpreads,
            $this->cardNames,
        );
    }

    /** Echo back whatever Reading is saved, simulating a successful persist. */
    private function persistSucceeds(): void
    {
        $this->readings->method('save')->willReturnCallback(static fn(Reading $r): Reading => $r);
    }

    /**
     * Decode the reading_info JSON of a saved reading.
     *
     * @return array<string, mixed>
     */
    private function decodeInfo(Reading $reading): array
    {
        $info = json_decode($reading->reading_info, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($info);

        return $info;
    }

    /**
     * The exception SQLite really raises for a duplicate reading id, captured
     * from an in-memory table shaped like `readings` — so the tests exercise
     * the actual message the retry matches on, not a hand-written imitation.
     */
    private static function realIdCollision(): PDOException
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE readings (reading_id TEXT NOT NULL UNIQUE, PRIMARY KEY (reading_id))');
        $db->exec("INSERT INTO readings VALUES ('abc')");
        try {
            $db->exec("INSERT INTO readings VALUES ('abc')");
        } catch (PDOException $e) {
            return $e;
        }
        self::fail('SQLite accepted a duplicate reading id.');
    }

    /** A stub that rejects the first $collisions saves with $error, then echoes the reading. */
    private function saveFailsThenSucceeds(int $collisions, PDOException $error): void
    {
        $calls = 0;
        $this->readings->method('save')->willReturnCallback(
            static function (Reading $r) use (&$calls, $collisions, $error): Reading {
                if (++$calls <= $collisions) {
                    throw $error;
                }
                return $r;
            }
        );
    }

    private function generateOneCard(): Reading
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);

        return $this->service()->generate(['deck_id' => 1, 'number_of_cards' => 1]);
    }

    // ── persist(): reading-id collisions ────────────────────────

    public function testAnIdCollisionIsRetriedWithAFreshId(): void
    {
        $this->saveFailsThenSucceeds(1, self::realIdCollision());

        $reading = $this->generateOneCard();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $reading->reading_id);
    }

    public function testRepeatedCollisionsGiveUpAfterThreeAttempts(): void
    {
        $this->saveFailsThenSucceeds(3, self::realIdCollision());

        $this->expectException(PDOException::class);
        $this->generateOneCard();
    }

    public function testOtherIntegrityErrorsAreNotRetried(): void
    {
        $calls = 0;
        $this->readings->method('save')->willReturnCallback(static function () use (&$calls): never {
            $calls++;
            throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed');
        });

        try {
            $this->generateOneCard();
            $this->fail('Expected the foreign-key error to propagate.');
        } catch (PDOException) {
            $this->assertSame(1, $calls);
        }
    }

    // ── generate() ──────────────────────────────────────────────

    public function testGenerateThrowsWhenDeckIsInvalid(): void
    {
        $this->decks->method('get')->willReturn([]); // not a Deck

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageIs('InvalidDeckID');

        try {
            $this->service()->generate(['deck_id' => 999]);
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getStatusCode());
            throw $e;
        }
    }

    public function testGenerateDrawsRequestedNumberOfCards(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->persistSucceeds();

        $reading = $this->service()->generate(['deck_id' => 1, 'number_of_cards' => 3]);
        $info    = $this->decodeInfo($reading);

        $this->assertSame(1, $info['deck_id']);
        $this->assertCount(3, $info['draw']);
        foreach ($info['draw'] as $entry) {
            $this->assertArrayHasKey('card_id', $entry);
            $this->assertArrayHasKey('reversed', $entry);
            $this->assertArrayHasKey('card_name', $entry);
        }
    }

    public function testGenerateNeverDrawsMoreThanTheDeckHolds(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 2]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B']);
        $this->persistSucceeds();

        $reading = $this->service()->generate(['deck_id' => 1, 'number_of_cards' => 50]);

        $this->assertCount(2, $this->decodeInfo($reading)['draw']);
    }

    public function testGenerateWithoutReversalsKeepsEveryCardUpright(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->persistSucceeds();

        $reading = $this->service()->generate([
            'deck_id'         => 1,
            'number_of_cards' => 3,
            'use_reversals'   => false,
        ]);

        foreach ($this->decodeInfo($reading)['draw'] as $entry) {
            $this->assertFalse($entry['reversed']);
        }
    }

    public function testGenerateUsesSpreadCardCountAndSnapshotsLayout(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 10]));
        $this->spreads->method('get')->willReturn(new Spread([
            'spread_id'  => 4,
            'name'       => 'Two Card',
            'card_count' => 2,
            'positions'  => [['order' => 1, 'title' => 'Past'], ['order' => 2, 'title' => 'Future']],
        ]));
        $this->cardNames->method('resolve')->willReturn(array_combine(range(1, 10), array_fill(0, 10, 'X')));
        $this->persistSucceeds();

        $reading = $this->service()->generate([
            'deck_id'         => 1,
            'number_of_cards' => 9, // should be overridden by the spread
            'spread_id'       => 4,
        ]);
        $info = $this->decodeInfo($reading);

        $this->assertCount(2, $info['draw']);
        $this->assertArrayHasKey('spread', $info);
        $this->assertSame(4, $info['spread']['spread_id']);
        $this->assertCount(2, $info['spread']['positions']);
    }

    public function testGenerateUsesUserSpreadCardCountAndSnapshotsLayout(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 10]));
        $this->userSpreads->method('get')->willReturn(new UserSpread([
            'user_spread_id' => 7,
            'name'           => 'My Personal Spread',
            'card_count'     => 3,
            'positions'      => [
                ['order' => 1, 'title' => 'Body'],
                ['order' => 2, 'title' => 'Mind'],
                ['order' => 3, 'title' => 'Spirit'],
            ],
        ]));
        $this->cardNames->method('resolve')->willReturn(array_combine(range(1, 10), array_fill(0, 10, 'X')));
        $this->persistSucceeds();

        $reading = $this->service()->generate([
            'deck_id'         => 1,
            'number_of_cards' => 9, // should be overridden by the user spread
            'user_spread_id'  => 7,
        ], userId: 42);
        $info = $this->decodeInfo($reading);

        $this->assertCount(3, $info['draw']);
        $this->assertArrayHasKey('spread', $info);
        $this->assertSame('My Personal Spread', $info['spread']['name']);
        // A personal spread is snapshotted as a non-public spread (id 0).
        $this->assertSame(0, $info['spread']['spread_id']);
        $this->assertCount(3, $info['spread']['positions']);
    }

    public function testAPersonalSpreadIsLookedUpOnlyAmongTheCallersOwn(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 10]));
        $this->cardNames->method('resolve')->willReturn(array_combine(range(1, 10), array_fill(0, 10, 'X')));
        $this->persistSucceeds();
        $userSpreads = $this->createMock(UserSpreadRepository::class);
        // Someone else's spread isn't among the caller's: no spread comes back.
        $userSpreads->expects($this->once())->method('get')->with(42, 7)->willReturn(null);
        $this->userSpreads = $userSpreads;

        $reading = $this->service()->generate(['deck_id' => 1, 'number_of_cards' => 2, 'user_spread_id' => 7], userId: 42);

        // Ignored, like an id that doesn't exist: a plain draw of the asked count.
        $info = $this->decodeInfo($reading);
        $this->assertCount(2, $info['draw']);
        $this->assertArrayNotHasKey('spread', $info);
    }

    public function testAGuestsPersonalSpreadIdIsIgnoredWithoutALookup(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->persistSucceeds();
        $userSpreads = $this->createMock(UserSpreadRepository::class);
        $userSpreads->expects($this->never())->method('get');
        $this->userSpreads = $userSpreads;

        $reading = $this->service()->generate(['deck_id' => 1, 'user_spread_id' => 7]);

        $this->assertArrayNotHasKey('spread', $this->decodeInfo($reading));
    }

    public function testADeckAwaitingApprovalIsRefusedLikeAnUnknownOneUnlessTheCallerIsAnAdmin(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 3, 'approved' => false]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->persistSucceeds();

        foreach ([
            'generate' => fn(bool $isAdmin): Reading => $this->service()->generate(['deck_id' => 1], 42, $isAdmin),
            'custom'   => fn(bool $isAdmin): Reading => $this->service()->createCustom(['deck_id' => 1, 'cards' => [['card_id' => 1]]], 42, $isAdmin),
        ] as $kind => $make) {
            try {
                $make(false);
                $this->fail("{$kind}: expected InvalidDeckID");
            } catch (ApiException $e) {
                $this->assertSame(['InvalidDeckID', 400], [$e->getMessage(), $e->getStatusCode()], $kind);
            }

            $this->assertSame(1, $this->decodeInfo($make(true))['deck_id'], $kind);
        }
    }

    public function testGenerateThrowsWithStatus404WhenSaveFails(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->readings->method('save')->willReturn(null);

        try {
            $this->service()->generate(['deck_id' => 1, 'number_of_cards' => 1]);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('ErrorGeneratingReading', $e->getMessage());
        }
    }

    public function testGenerateMarksOriginAsGenerated(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);
        $this->persistSucceeds();

        $reading = $this->service()->generate(['deck_id' => 1, 'number_of_cards' => 1]);

        $this->assertSame('generated', $this->decodeInfo($reading)['origin']);
    }

    // ── drawAdditional() ────────────────────────────────────────

    public function testDrawAdditionalAppendsUniqueCards(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn(array_combine(range(1, 5), array_fill(0, 5, 'X')));

        $info = [
            'deck_id' => 1,
            'origin'  => 'generated',
            'draw'    => [
                ['card_id' => 1, 'reversed' => false, 'card_name' => 'A'],
                ['card_id' => 2, 'reversed' => false, 'card_name' => 'B'],
            ],
        ];

        $updated = $this->service()->drawAdditional($info, ['count' => 2]);

        // Two new entries appended, none colliding with the existing cards.
        $this->assertCount(4, $updated['draw']);
        $ids = array_column($updated['draw'], 'card_id');
        $this->assertSame($ids, array_unique($ids));
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        foreach ($updated['draw'] as $entry) {
            $this->assertArrayHasKey('card_name', $entry);
        }
    }

    public function testDrawAdditionalNeverExceedsTheDeck(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 3]));
        $this->cardNames->method('resolve')->willReturn(array_combine(range(1, 3), array_fill(0, 3, 'X')));

        $info = ['deck_id' => 1, 'origin' => 'generated', 'draw' => [['card_id' => 1]]];

        // Only cards 2 and 3 remain even though 10 were requested.
        $updated = $this->service()->drawAdditional($info, ['count' => 10]);
        $this->assertCount(3, $updated['draw']);
    }

    public function testDrawAdditionalThrowsWhenDeckExhausted(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 2]));

        $info = [
            'deck_id' => 1,
            'origin'  => 'generated',
            'draw'    => [['card_id' => 1], ['card_id' => 2]],
        ];

        $this->expectException(ApiException::class);
        $this->service()->drawAdditional($info, ['count' => 1]);
    }

    public function testDrawAdditionalKeepsUsingAReadingsDeckThatLostItsApproval(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 3, 'approved' => false]));
        $this->cardNames->method('resolve')->willReturn([1 => 'A', 2 => 'B', 3 => 'C']);

        $updated = $this->service()->drawAdditional(['deck_id' => 1, 'draw' => [['card_id' => 1]]], ['count' => 1]);

        $this->assertCount(2, $updated['draw']);
    }

    public function testDrawAdditionalThrowsOnInvalidDeck(): void
    {
        $this->decks->method('get')->willReturn([]); // not a Deck

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageIs('InvalidDeckID');

        $this->service()->drawAdditional(['deck_id' => 999, 'draw' => []], ['count' => 1]);
    }

    // ── createCustom() ──────────────────────────────────────────

    public function testCreateCustomRejectsEmptyCardList(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageIs('At least one card is required.');

        $this->service()->createCustom(['deck_id' => 1, 'cards' => []]);
    }

    public function testCreateCustomRejectsInvalidDeck(): void
    {
        $this->decks->method('get')->willReturn([]); // not a Deck

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageIs('InvalidDeckID');

        $this->service()->createCustom([
            'deck_id' => 999,
            'cards'   => [['card_id' => 1]],
        ]);
    }

    public function testCreateCustomRejectsCardOutsideDeckRange(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 5]));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageIs('A selected card is not part of this deck.');

        $this->service()->createCustom([
            'deck_id' => 1,
            'cards'   => [['card_id' => 99]],
        ]);
    }

    public function testCreateCustomRejectsDuplicateCards(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 5]));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageIs('Each card can only be used once in a reading.');

        $this->service()->createCustom([
            'deck_id' => 1,
            'cards'   => [['card_id' => 2], ['card_id' => 2]],
        ]);
    }

    public function testCreateCustomThrowsWhenNameCannotBeResolved(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([]); // nothing resolves

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageIs('A selected card could not be found.');

        $this->service()->createCustom([
            'deck_id' => 1,
            'cards'   => [['card_id' => 1]],
        ]);
    }

    public function testCreateCustomBuildsOrderedDrawAndSpreadSnapshot(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([1 => 'The Fool', 3 => 'The Empress']);
        $this->persistSucceeds();

        $reading = $this->service()->createCustom([
            'deck_id' => 1,
            'name'    => 'My Physical Reading',
            'cards'   => [
                ['card_id' => 1, 'reversed' => true, 'title' => 'Start', 'x' => 10, 'y' => 20, 'rotation' => 90],
                ['card_id' => 3, 'title' => 'End'],
            ],
        ]);
        $info = $this->decodeInfo($reading);

        $this->assertSame(1, $info['deck_id']);
        $this->assertSame('My Physical Reading', $info['spread']['name']);
        $this->assertCount(2, $info['draw']);

        $this->assertSame(1, $info['draw'][0]['card_id']);
        $this->assertTrue($info['draw'][0]['reversed']);
        $this->assertSame('The Fool', $info['draw'][0]['card_name']);

        $this->assertSame(3, $info['draw'][1]['card_id']);
        $this->assertFalse($info['draw'][1]['reversed']);
        $this->assertSame('The Empress', $info['draw'][1]['card_name']);

        // First position keeps its sanitised coordinates/rotation. (x is
        // compared loosely: a whole-number float may round-trip through JSON as
        // an int.)
        $this->assertEqualsWithDelta(10.0, $info['spread']['positions'][0]['x'], 0.001);
        $this->assertSame(90, $info['spread']['positions'][0]['rotation']);
    }

    public function testCreateCustomAcceptsCardsAsJsonString(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([2 => 'The High Priestess']);
        $this->persistSucceeds();

        $reading = $this->service()->createCustom([
            'deck_id' => 1,
            'cards'   => json_encode([['card_id' => 2]]),
        ]);
        $info = $this->decodeInfo($reading);

        $this->assertCount(1, $info['draw']);
        $this->assertSame('The High Priestess', $info['draw'][0]['card_name']);
    }

    public function testCreateCustomDefaultsBlankNameToCustomReading(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([1 => 'The Fool']);
        $this->persistSucceeds();

        $reading = $this->service()->createCustom([
            'deck_id' => 1,
            'name'    => '   ',
            'cards'   => [['card_id' => 1]],
        ]);

        $this->assertSame('Custom Reading', $this->decodeInfo($reading)['spread']['name']);
    }

    public function testCreateCustomMarksOriginAsCustom(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([1 => 'The Fool']);
        $this->persistSucceeds();

        $reading = $this->service()->createCustom(['deck_id' => 1, 'cards' => [['card_id' => 1]]]);

        $this->assertSame('custom', $this->decodeInfo($reading)['origin']);
    }

    public function testCreateCustomThrowsWithStatus500WhenSaveFails(): void
    {
        $this->decks->method('get')->willReturn(new Deck(['deck_id' => 1, 'system_total_cards' => 5]));
        $this->cardNames->method('resolve')->willReturn([1 => 'The Fool']);
        $this->readings->method('save')->willReturn(null);

        try {
            $this->service()->createCustom(['deck_id' => 1, 'cards' => [['card_id' => 1]]]);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
    }
}
