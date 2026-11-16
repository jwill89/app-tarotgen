<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\ReadingController;
use Routes\Internal\UserController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\ReadingData;
use Tarot\Data\UserData;
use Tarot\Repository\UserRepository;
use Tarot\Utility\Clock;

/**
 * A timestamp is written by the app in RFC 3339 UTC (Tarot\Utility\Clock),
 * stored in that form, and sent exactly as stored.
 */
#[CoversClass(Clock::class)]
#[CoversClass(ReadingController::class)]
#[CoversClass(ReadingData::class)]
#[CoversClass(UserController::class)]
#[CoversClass(UserData::class)]
final class TimestampsTest extends HttpTestCase
{
    private const string RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    public function testAReadingsTimesAreSentAsTheyAreStored(): void
    {
        $system = $this->service(DeckSystemData::class)->store(['name' => 'Little Arcana', 'short_name' => 'LA', 'total_cards' => 5]);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);

        $created = $this->assertJsonResponse(201, $this->post('/readings/generate', ['deck_id' => $deck->deck_id, 'expires_in_days' => 7]));

        $this->assertDocumented('post', '/readings/generate', 201, $created);
        $this->assertIsString($created['reading_time']);
        $this->assertIsString($created['expires_at']);
        $this->assertMatchesRegularExpression(self::RFC3339, $created['reading_time']);
        $this->assertMatchesRegularExpression(self::RFC3339, $created['expires_at']);
        $this->assertEqualsWithDelta(time(), strtotime($created['reading_time']), 5);
        $this->assertEqualsWithDelta(time() + 7 * 86400, strtotime($created['expires_at']), 5);
        $this->assertSame(
            [['reading_time' => $created['reading_time'], 'expires_at' => $created['expires_at']]],
            $this->rows("SELECT reading_time, expires_at FROM readings WHERE reading_id = '{$created['reading_id']}'"),
        );
    }

    public function testAnAccountsTimesAreSentAsTheyAreStored(): void
    {
        $user = $this->createUser('reader@example.test');
        $this->service(UserRepository::class)->updatePasswordHash($user->user_id, password_hash('current passphrase 1', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->assertJsonResponse(200, $this->post('/auth/login', ['email' => 'reader@example.test', 'password' => 'current passphrase 1']));

        $me = $this->assertJsonResponse(200, $this->get('/auth/me'));

        $this->assertIsArray($me['user']);
        $this->assertIsString($me['user']['registered_at']);
        $this->assertIsString($me['user']['last_login_at']);
        $this->assertMatchesRegularExpression(self::RFC3339, $me['user']['registered_at']);
        $this->assertMatchesRegularExpression(self::RFC3339, $me['user']['last_login_at']);
        $this->assertEqualsWithDelta(time(), strtotime($me['user']['last_login_at']), 5);
        $this->assertSame(
            [['registered_at' => $me['user']['registered_at'], 'last_login_at' => $me['user']['last_login_at']]],
            $this->rows("SELECT registered_at, last_login_at FROM users WHERE user_id = {$user->user_id}"),
        );
    }
}
