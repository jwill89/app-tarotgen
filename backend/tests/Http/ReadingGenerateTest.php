<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\ReadingController;
use Routes\Middleware\AdminAuth;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\SpecialCardData;
use Tarot\Data\SpreadData;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Service\PluginTokenService;
use Tarot\Service\ReadingService;
use Tarot\Service\CardNameResolver;
use Tarot\Repository\SpecialCardRepository;
use Tarot\Repository\DeckSystemRepository;
use Tarot\Structure\Reading;

/**
 * Making a reading: POST /readings/generate (a random draw, optionally shaped
 * by a spread) and POST /readings (cards chosen by hand). What gets stored, which
 * options only an account may set (its own personal spreads among them), and
 * what is refused (a deck still awaiting approval, unless an admin asks).
 */
#[CoversClass(ReadingController::class)]
#[CoversClass(ReadingService::class)]
#[CoversClass(ReadingRepository::class)]
#[CoversClass(UserSpreadRepository::class)]
#[CoversClass(AdminAuth::class)]
#[CoversClass(CardNameResolver::class)]
#[CoversClass(SpecialCardRepository::class)]
#[CoversClass(DeckSystemRepository::class)]
#[CoversClass(Reading::class)]
final class ReadingGenerateTest extends HttpTestCase
{
    /** A small deck, so a draw can cover all of it. */
    private const int DECK_SIZE = 5;

    private int $deckId;

    private int $systemId;

    protected function setUp(): void
    {
        parent::setUp();

        $systems = $this->service(DeckSystemData::class);
        $system = $systems->store(['name' => 'Little Arcana', 'short_name' => 'LA', 'total_cards' => self::DECK_SIZE]);
        $this->assertNotNull($system);
        $this->systemId = $system->deck_system_id;
        $systems->storeCards($system->deck_system_id, array_map(
            static fn(int $id): array => ['card_id' => $id, 'name' => "Card {$id}"],
            range(1, self::DECK_SIZE)
        ));
        $deck = $this->service(DeckData::class)->store([
            'name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id, 'additional_cards' => 2,
        ]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
    }

    public function testAnUnknownDeckIsRefused(): void
    {
        $response = $this->post('/readings/generate', ['deck_id' => $this->deckId + 1]);

        $this->assertSame(['error' => 'InvalidDeckID'], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testASignedInReadingKeepsItsOwnersOptions(): void
    {
        $owner = $this->createUser('owner@example.test');
        $this->signInAs($owner->user_id);

        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId,
            'reading_name' => '  Morning  ',
            'reading_notes' => "First line\n\n    indented",
            'hide_user' => 'true',
            'password' => 'abcd',
        ]));

        $this->assertTrue($created['is_owner']);
        $this->assertTrue($created['can_draw_more']);
        $this->assertSame('Guest', $created['reader']);

        $row = $this->readingRow($created['reading_id']);
        $this->assertSame($owner->user_id, $row['user_id']);
        $this->assertSame('Morning', $row['reading_name']);
        // Notes keep their whitespace: they're Markdown.
        $this->assertSame("First line\n\n    indented", $row['reading_notes']);
        $this->assertSame(1, $row['hide_user']);
        $this->assertIsString($row['password_hash']);
        $this->assertTrue(password_verify('abcd', $row['password_hash']));
    }

    public function testAGuestCannotNameHideOrProtectAReading(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'reading_name' => 'Mine', 'hide_user' => true, 'password' => 'abcd',
        ]));

        $this->assertFalse($created['is_owner']);
        $row = $this->readingRow($created['reading_id']);
        $this->assertNull($row['user_id']);
        $this->assertNull($row['reading_name']);
        $this->assertNull($row['password_hash']);
        // Nobody could ever unlock a guest's password, so it would lock the reading for good.
        $this->assertFalse($this->assertJsonResponse(200, $this->get("/readings/{$created['reading_id']}"))['locked']);
    }

    public function testAReadingPasswordNeedsFourCharacters(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);

        $response = $this->post('/readings/generate', ['deck_id' => $this->deckId, 'password' => 'abc']);

        $this->assertSame(['error' => 'Reading password must be at least 4 characters.'], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testADrawIsCappedByTheDeckAndNeverRepeatsACard(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'number_of_cards' => 50, 'use_reversals' => true,
        ]));

        $draw = $this->draw($created);
        $ids = array_column($draw, 'card_id');
        sort($ids);
        $this->assertSame(range(1, self::DECK_SIZE), $ids);
        foreach ($draw as $card) {
            $this->assertIsBool($card['reversed']);
            $this->assertSame("Card {$card['card_id']}", $card['card_name']);
        }
    }

    public function testWithoutReversalsEveryCardIsUpright(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'number_of_cards' => self::DECK_SIZE, 'use_reversals' => 'false',
        ]));

        $this->assertSame(array_fill(0, self::DECK_SIZE, false), array_column($this->draw($created), 'reversed'));
    }

    public function testTheDecksExtraCardsAreDealtOnlyWhenAskedFor(): void
    {
        $this->service(SpecialCardData::class)->store(['deck_id' => $this->deckId, 'card_id' => 6, 'name' => 'The Extra']);

        $standard = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $this->deckId, 'number_of_cards' => 50]));
        $this->assertCount(self::DECK_SIZE, $this->draw($standard));

        $extended = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'number_of_cards' => 50, 'use_additional_cards' => true,
        ]));
        $names = array_column($this->draw($extended), 'card_name', 'card_id');
        ksort($names);
        // Extras are named from the deck's own special cards; card 7 has no entry, so no name.
        $this->assertSame([1 => 'Card 1', 2 => 'Card 2', 3 => 'Card 3', 4 => 'Card 4', 5 => 'Card 5', 6 => 'The Extra'], $names);
        $this->assertCount(self::DECK_SIZE + 2, $this->draw($extended));
    }

    public function testASpreadSetsTheCardCountAndIsSnapshottedIntoTheReading(): void
    {
        $spread = $this->service(SpreadData::class)->store([
            'name' => 'Past Present Future', 'description' => 'Three in a row',
            'positions' => [
                ['order' => 1, 'title' => 'Past', 'x' => 20, 'y' => 50],
                ['order' => 2, 'title' => 'Present', 'x' => 50, 'y' => 50],
                ['order' => 3, 'title' => 'Future', 'x' => 80, 'y' => 50],
            ],
        ]);
        $this->assertNotNull($spread);

        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'number_of_cards' => 1, 'spread_id' => $spread->spread_id,
            // A blank title keeps the spread's own.
            'position_titles' => json_encode(['', 'Right now', '']),
        ]));
        $this->assertCount(3, $this->draw($created));

        // Editing the spread later must not change a reading already made with it.
        $this->service(SpreadData::class)->update($spread->spread_id, ['name' => 'Renamed', 'positions' => []]);

        $viewed = $this->assertJsonResponse(200, $this->get("/readings/{$created['reading_id']}"));
        $this->assertIsArray($viewed['reading_info']);
        $snapshot = $viewed['reading_info']['spread'];
        $this->assertIsArray($snapshot);
        $this->assertSame($spread->spread_id, $snapshot['spread_id']);
        $this->assertSame('Past Present Future', $snapshot['name']);
        $this->assertSame('Three in a row', $snapshot['description']);
        $this->assertIsArray($snapshot['positions']);
        $this->assertSame(['Past', 'Right now', 'Future'], array_column($snapshot['positions'], 'title'));
    }

    public function testAPersonalSpreadShapesAReadingLikeAPublicOne(): void
    {
        $this->signInAs($this->createUser('owner@example.test')->user_id);
        $spread = $this->assertJsonResponse(201, $this->post('/account/spreads', [
            'name' => 'My Pair', 'positions' => [['order' => 1, 'title' => 'Me'], ['order' => 2, 'title' => 'You']],
        ]));

        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'user_spread_id' => $spread['user_spread_id'],
        ]));

        $this->assertCount(2, $this->draw($created));
        $this->assertIsArray($created['reading_info']);
        $snapshot = $created['reading_info']['spread'];
        $this->assertIsArray($snapshot);
        // Not a public spread, so no public id to point at.
        $this->assertSame(0, $snapshot['spread_id']);
        $this->assertSame('My Pair', $snapshot['name']);
    }

    public function testOnlyItsOwnerCanUseAPersonalSpread(): void
    {
        $owner = $this->createUser('owner@example.test');
        $stranger = $this->createUser('stranger@example.test');
        $tokens = $this->service(PluginTokenService::class);
        $spreadId = $this->personalSpread($owner->user_id);

        // An id that doesn't exist is ignored: a one-card free draw.
        $asUnknown = $this->spreadShape($this->generateWithPersonalSpread($spreadId + 1));
        $this->assertSame([1, null], $asUnknown);

        // Anyone else's reading ignores the owner's spread in just the same way,
        // so the sequential ids can't be used to copy (or probe for) spreads.
        $this->assertSame($asUnknown, $this->spreadShape($this->generateWithPersonalSpread($spreadId)), 'a guest');
        $this->signInAs($stranger->user_id);
        $this->assertSame($asUnknown, $this->spreadShape($this->generateWithPersonalSpread($spreadId)), 'another account');
        $this->useSessionCookie(null);
        $strangersPlugin = $tokens->issueToken($stranger->user_id);
        $this->assertSame($asUnknown, $this->spreadShape($this->generateWithPersonalSpread($spreadId, $strangersPlugin)), "another account's plugin");
        $this->assertStringNotContainsString('Mine Only', implode(' ', array_column($this->rows('SELECT reading_info FROM readings'), 'reading_info')));

        // A plugin token acts as its user: the owner's own plugin may use it.
        $ownersPlugin = $tokens->issueToken($owner->user_id);
        $this->assertSame([2, 'Mine Only'], $this->spreadShape($this->generateWithPersonalSpread($spreadId, $ownersPlugin)));
    }

    public function testADeckAwaitingApprovalIsRefusedLikeAnUnknownOneUnlessAnAdminAsks(): void
    {
        $pending = $this->service(DeckData::class)->store([
            'name' => 'Submitted', 'deck_system_id' => $this->systemId, 'approved' => false,
        ]);
        $this->assertNotNull($pending);
        $refused = ['error' => 'InvalidDeckID'];
        $generate = ['deck_id' => $pending->deck_id];
        $custom = ['deck_id' => $pending->deck_id, 'cards' => [['card_id' => 1]]];
        $admin = $this->createUser('admin@example.test', admin: true);

        $this->assertSame($refused, $this->assertJsonResponse(400, $this->post('/readings/generate', $generate)));
        $this->assertSame($refused, $this->assertJsonResponse(400, $this->post('/readings', $custom)));
        $this->signInAs($this->createUser('reader@example.test')->user_id);
        $this->assertSame($refused, $this->assertJsonResponse(400, $this->post('/readings/generate', $generate)));
        $this->assertSame($refused, $this->assertJsonResponse(400, $this->post('/readings', $custom)));
        // Admin rights come with a browser session only, never with a plugin token.
        $this->useSessionCookie(null);
        $adminsPlugin = ['Authorization' => 'Bearer ' . $this->service(PluginTokenService::class)->issueToken($admin->user_id)];
        $this->assertSame($refused, $this->assertJsonResponse(400, $this->post('/readings/generate', $generate, $adminsPlugin)));
        $this->assertSame(0, $this->rowCount('readings'));

        $this->signInAs($admin->user_id);
        $this->assertJsonResponse(201, $this->post('/readings/generate', $generate));
        $this->assertJsonResponse(201, $this->post('/readings', $custom));
        $this->assertSame(2, $this->rowCount('readings'));
    }

    public function testGeneratingIsLimitedToThirtyAMinute(): void
    {
        for ($reading = 1; $reading <= 30; $reading++) {
            $this->assertSame(201, $this->post('/readings/generate', ['deck_id' => $this->deckId])->getStatusCode());
        }

        $response = $this->post('/readings/generate', ['deck_id' => $this->deckId]);

        $this->assertSame(
            ['error' => 'You are generating readings too quickly. Please wait a moment.'],
            $this->assertJsonResponse(429, $response)
        );
        $this->assertSame(30, $this->rowCount('readings'));
    }

    public function testACustomReadingRecordsTheChosenCardsAndLayout(): void
    {
        $owner = $this->createUser('owner@example.test');
        $this->signInAs($owner->user_id);

        $created = $this->assertJsonResponse(201, $this->post('/readings', [
            'deck_id' => $this->deckId,
            'name' => 'Kitchen table',
            'reading_name' => 'Recorded',
            'cards' => [
                // Names come from the deck, never from the client.
                ['card_id' => 3, 'reversed' => 'true', 'title' => '  Past  ', 'x' => 150, 'y' => -5, 'rotation' => -90, 'card_name' => 'Forged'],
                ['card_id' => 1, 'reversed' => false, 'title' => 'Present', 'x' => 20.456, 'y' => 50, 'rotation' => 450],
            ],
        ]));

        $this->assertSame($owner->user_id, $created['user_id']);
        $this->assertSame('Recorded', $created['reading_name']);
        $this->assertSame([
            'deck_id' => $this->deckId,
            'origin' => 'custom',
            'spread' => [
                'spread_id' => 0,
                'name' => 'Kitchen table',
                'description' => '',
                'positions' => [
                    // Clamped onto the canvas, rounded, rotation brought into 0–359.
                    ['order' => 1, 'title' => 'Past', 'x' => 100, 'y' => 0, 'rotation' => 270],
                    ['order' => 2, 'title' => 'Present', 'x' => 20.46, 'y' => 50, 'rotation' => 90],
                ],
            ],
            'draw' => [
                ['card_id' => 3, 'reversed' => true, 'card_name' => 'Card 3'],
                ['card_id' => 1, 'reversed' => false, 'card_name' => 'Card 1'],
            ],
        ], $created['reading_info']);
    }

    public function testAnUnnamedCustomReadingIsCalledCustomReading(): void
    {
        $created = $this->assertJsonResponse(201, $this->post('/readings', [
            'deck_id' => $this->deckId, 'name' => '   ', 'cards' => [['card_id' => 2]],
        ]));

        $this->assertNull($created['user_id']);
        $this->assertIsArray($created['reading_info']);
        $this->assertIsArray($created['reading_info']['spread']);
        $this->assertSame('Custom Reading', $created['reading_info']['spread']['name']);
    }

    /**
     * @return iterable<string,array{array<string,mixed>,string}>
     */
    public static function refusedCustomReadings(): iterable
    {
        yield 'no cards' => [['cards' => []], 'At least one card is required.'];
        yield 'an unknown deck' => [['deck_id' => 999, 'cards' => [['card_id' => 1]]], 'InvalidDeckID'];
        yield 'a card that is not an object' => [['cards' => ['card 1']], 'Invalid card data.'];
        yield 'a card outside the deck' => [['cards' => [['card_id' => self::DECK_SIZE + 3]]], 'A selected card is not part of this deck.'];
        yield 'card zero' => [['cards' => [['card_id' => 0]]], 'A selected card is not part of this deck.'];
        yield 'the same card twice' => [['cards' => [['card_id' => 2], ['card_id' => 2]]], 'Each card can only be used once in a reading.'];
        // An extra the deck allows but has no name for: nothing to show on the card.
        yield 'an extra with no name' => [['cards' => [['card_id' => self::DECK_SIZE + 1]]], 'A selected card could not be found.'];
    }

    /** @param array<string,mixed> $payload */
    #[DataProvider('refusedCustomReadings')]
    public function testACustomReadingWithBadCardsIsRefused(array $payload, string $error): void
    {
        $response = $this->post('/readings', $payload + ['deck_id' => $this->deckId]);

        $this->assertSame(['error' => $error], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    /** A two-position personal spread named "Mine Only"; returns its id. */
    private function personalSpread(int $ownerId): int
    {
        $spread = $this->service(UserSpreadRepository::class)->create($ownerId, [
            'name' => 'Mine Only', 'positions' => [['order' => 1, 'title' => 'Me'], ['order' => 2, 'title' => 'You']],
        ]);
        $this->assertNotNull($spread);

        return $spread->user_spread_id;
    }

    /**
     * @return array<mixed> The created reading.
     */
    private function generateWithPersonalSpread(int $userSpreadId, ?string $pluginToken = null): array
    {
        $headers = $pluginToken === null ? [] : ['Authorization' => "Bearer {$pluginToken}"];

        return $this->assertJsonResponse(201, $this->post('/readings/generate', [
            'deck_id' => $this->deckId, 'user_spread_id' => $userSpreadId,
        ], $headers));
    }

    /**
     * How many cards a reading drew, and the name of the spread it was laid out in.
     *
     * @param array<mixed> $reading
     * @return array{int,?string}
     */
    private function spreadShape(array $reading): array
    {
        $this->assertIsArray($reading['reading_info']);
        $spread = $reading['reading_info']['spread'] ?? null;

        return [count($this->draw($reading)), is_array($spread) ? (string) $spread['name'] : null];
    }

    /**
     * The stored row behind a reading id from a response.
     *
     * @return array<string,mixed>
     */
    private function readingRow(mixed $readingId): array
    {
        $this->assertIsString($readingId);
        $stmt = $this->db->prepare('SELECT user_id, reading_name, reading_notes, hide_user, password_hash FROM readings WHERE reading_id = ?');
        $stmt->execute([$readingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        return $row;
    }

    /**
     * @param array<mixed> $reading A reading payload.
     * @return list<array<string,mixed>>
     */
    private function draw(array $reading): array
    {
        $this->assertIsArray($reading['reading_info']);
        $draw = $reading['reading_info']['draw'];
        $this->assertIsArray($draw);
        return array_values($draw);
    }
}
