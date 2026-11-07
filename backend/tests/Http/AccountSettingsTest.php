<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\AccountController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Repository\FavoriteDeckRepository;
use Tarot\Repository\FavoriteSpreadRepository;
use Tarot\Repository\UserRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Service\AccountProtection;
use Tarot\Service\PluginTokenService;
use Tarot\Utility\DbSessionHandler;
use Tarot\Utility\Session;

/**
 * The account itself: renaming it, changing its password (which signs its other
 * sessions out), and deleting it — which takes the user's readings, spreads,
 * favourites and linked plugins with it and signs it out everywhere, but leaves
 * everyone else's data alone.
 */
#[CoversClass(AccountController::class)]
#[CoversClass(AccountProtection::class)]
#[CoversClass(DbSessionHandler::class)]
#[CoversClass(Session::class)]
final class AccountSettingsTest extends HttpTestCase
{
    private const string PASSWORD = 'current passphrase 1';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('reader@example.test')->user_id;
        // Cheap to hash; the account only needs a password it can be asked for.
        $this->service(UserRepository::class)->updatePasswordHash($this->userId, password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));
        $this->signInAs($this->userId);
    }

    public function testThePasswordCanBeChanged(): void
    {
        $response = $this->post('/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'a brand new passphrase']);

        $changed = $this->assertJsonResponse(200, $response);
        $this->assertDocumented('post', '/account/change-password', 200, $changed);
        $this->assertSame(['success' => true, 'message' => 'Your password has been updated.'], $changed);
        $hash = $this->passwordHash();
        $this->assertTrue(password_verify('a brand new passphrase', $hash));
        $this->assertFalse(password_verify(self::PASSWORD, $hash));
        // The browser that changed it stays signed in.
        $this->assertJsonResponse(200, $this->get('/account/spreads'));
    }

    public function testChangingThePasswordSignsOutEveryOtherSessionButThisOne(): void
    {
        $here = $this->sessionCookie();
        $plugin = $this->service(PluginTokenService::class)->issueToken($this->userId);
        // The same account on another device, and someone else entirely.
        $this->useSessionCookie(null);
        $this->signInAs($this->userId);
        $otherDevice = $this->sessionCookie();
        $this->useSessionCookie(null);
        $this->signInAs($this->createUser('other@example.test')->user_id);
        $someoneElse = $this->sessionCookie();
        $this->useSessionCookie($here);

        $this->assertJsonResponse(200, $this->post('/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'a brand new passphrase']));

        // This browser carries on, under a new id.
        $this->assertNotSame($here, $this->sessionCookie());
        $this->assertJsonResponse(200, $this->get('/auth/me'));
        // A copy of its old cookie and the other device are signed out.
        foreach ([$here, $otherDevice] as $cookie) {
            $this->useSessionCookie($cookie);
            $this->assertJsonResponse(401, $this->get('/auth/me'));
        }
        $this->useSessionCookie($someoneElse);
        $this->assertJsonResponse(200, $this->get('/auth/me'));
        // A linked plugin is revoked under Connected Apps, not by this.
        $this->useSessionCookie(null);
        $this->assertJsonResponse(200, $this->get('/account/readings', ['Authorization' => "Bearer {$plugin}"]));
    }

    public function testARememberedSessionStaysRememberedThroughThePasswordChange(): void
    {
        $this->post('/auth/logout');
        $this->assertJsonResponse(200, $this->post('/auth/login', ['email' => 'reader@example.test', 'password' => self::PASSWORD, 'remember_me' => true]));

        $response = $this->post('/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'a brand new passphrase']);

        $this->assertJsonResponse(200, $response);
        $cookie = $this->sessionCookie();
        $this->assertNotNull($cookie);
        $expires = $this->rows("SELECT expires FROM sessions WHERE id = '" . hash('sha256', $cookie) . "'");
        $this->assertCount(1, $expires);
        $this->assertGreaterThan(time() + Session::REMEMBER_ME_LIFETIME - 60, (int) $expires[0]['expires']);
    }

    public function testThePasswordChangeNeedsTheCurrentPassword(): void
    {
        $before = $this->passwordHash();
        $incorrect = ['error' => 'Your current password is incorrect.'];

        $wrong = $this->post('/account/change-password', ['current_password' => 'not it', 'new_password' => 'a brand new passphrase']);
        $this->assertSame($incorrect, $this->assertJsonResponse(422, $wrong));
        $missing = $this->post('/account/change-password', ['new_password' => 'a brand new passphrase']);
        $this->assertSame($incorrect, $this->assertJsonResponse(422, $missing));

        $this->assertSame($before, $this->passwordHash());
    }

    public function testTheNewPasswordMustMeetThePolicy(): void
    {
        $before = $this->passwordHash();

        $response = $this->post('/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'short']);

        $this->assertSame(
            ['error' => 'Password must be at least 12 characters long. A memorable passphrase works well.'],
            $this->assertJsonResponse(422, $response)
        );
        $this->assertSame($before, $this->passwordHash());
    }

    public function testTheDisplayNameCanBeChanged(): void
    {
        $response = $this->request('PATCH', '/account', ['display_name' => '  Moon Reader  ']);
        $updated = $this->assertJsonResponse(200, $response);

        $this->assertDocumented('patch', '/account', 200, $updated);
        $this->assertTrue($updated['success']);
        $this->assertIsArray($updated['user']);
        $this->assertSame('Moon Reader', $updated['user']['display_name']);
        $this->assertSame([['display_name' => 'Moon Reader']], $this->rows("SELECT display_name FROM users WHERE user_id = {$this->userId}"));
    }

    public function testKeepingTheSameNameInADifferentCaseIsAllowed(): void
    {
        $updated = $this->assertJsonResponse(200, $this->request('PATCH', '/account', ['display_name' => 'READER']));

        $this->assertIsArray($updated['user']);
        $this->assertSame('READER', $updated['user']['display_name']);
    }

    public function testAPatchWithoutADisplayNameChangesNothing(): void
    {
        $updated = $this->assertJsonResponse(200, $this->request('PATCH', '/account', []));

        $this->assertIsArray($updated['user']);
        $this->assertSame('reader', $updated['user']['display_name']);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function refusedDisplayNames(): iterable
    {
        yield 'too short' => ['ab', 'Display name must be between 3 and 30 characters.'];
        yield 'too long' => [str_repeat('a', 31), 'Display name must be between 3 and 30 characters.'];
        yield 'markup' => ['<b>Reader</b>', 'Display name may only contain letters, numbers, spaces, and . _ -'];
        yield "another account's, in any case" => ['TAKEN NAME', 'That display name is already taken.'];
    }

    #[DataProvider('refusedDisplayNames')]
    public function testADisplayNameMustBeValidAndFree(string $name, string $error): void
    {
        $this->service(UserRepository::class)->updateDisplayName($this->createUser('other@example.test')->user_id, 'Taken Name');

        $response = $this->request('PATCH', '/account', ['display_name' => $name]);

        $this->assertSame(['error' => $error], $this->assertJsonResponse(422, $response));
        $this->assertSame([['display_name' => 'reader']], $this->rows("SELECT display_name FROM users WHERE user_id = {$this->userId}"));
    }

    public function testDeletingTheAccountTakesItsDataWithIt(): void
    {
        $other = $this->createUser('other@example.test');
        $this->insertReading('mine000001', $this->userId);
        $this->insertReading('theirs0002', $other->user_id);
        $this->insertReading('guest00003', null);
        $spread = $this->service(UserSpreadRepository::class)->create($this->userId, ['name' => 'Mine', 'positions' => [['order' => 1]]]);
        $this->assertNotNull($spread);
        $this->assertJsonResponse(201, $this->post("/account/spreads/{$spread->user_spread_id}/submit"));
        $this->service(FavoriteSpreadRepository::class)->add($this->userId, 'public', 1);
        $this->service(FavoriteSpreadRepository::class)->add($other->user_id, 'public', 1);
        $this->service(FavoriteDeckRepository::class)->add($this->userId, $this->deck());
        $tokens = $this->service(PluginTokenService::class);
        $token = $tokens->issueToken($this->userId);
        $tokens->createAuthorizationCode($this->userId, 'challenge');

        $response = $this->request('DELETE', '/account', ['password' => self::PASSWORD]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([], $this->rows("SELECT user_id FROM users WHERE user_id = {$this->userId}"));
        $this->assertSame([['reading_id' => 'guest00003'], ['reading_id' => 'theirs0002']], $this->rows('SELECT reading_id FROM readings ORDER BY reading_id'));
        $this->assertSame(0, $this->rowCount('user_spreads'));
        $this->assertSame([['user_id' => $other->user_id]], $this->rows('SELECT user_id FROM user_favorite_spreads'));
        $this->assertSame(0, $this->rowCount('user_favorite_decks'));
        $this->assertSame(0, $this->rowCount('plugin_tokens'));
        $this->assertSame(0, $this->rowCount('plugin_auth_codes'));
        // A spread already sent for review stays in the queue, no longer credited.
        $this->assertSame([['name' => 'Mine', 'user_id' => null]], $this->rows('SELECT name, user_id FROM pending_spreads'));

        $this->assertJsonResponse(401, $this->get('/account/readings'));
        $this->assertJsonResponse(401, $this->get('/account/readings', ['Authorization' => "Bearer {$token}"]));
    }

    public function testDeletingTheAccountSignsTheBrowserOut(): void
    {
        $before = $this->sessionCookie();

        $this->assertSame(204, $this->request('DELETE', '/account', ['password' => self::PASSWORD])->getStatusCode());

        $this->assertNotSame($before, $this->sessionCookie());
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/auth/me')));
    }

    public function testDeletingTheAccountSignsItOutEverywhere(): void
    {
        $here = $this->sessionCookie();
        $this->useSessionCookie(null);
        $this->signInAs($this->userId);
        $otherDevice = $this->sessionCookie();
        $this->useSessionCookie(null);
        $other = $this->createUser('other@example.test');
        $this->signInAs($other->user_id);
        $this->useSessionCookie($here);

        $this->assertSame(204, $this->request('DELETE', '/account', ['password' => self::PASSWORD])->getStatusCode());

        $this->assertSame([], $this->rows("SELECT 1 FROM sessions WHERE user_id = {$this->userId}"));
        $this->useSessionCookie($otherDevice);
        $this->assertJsonResponse(401, $this->get('/auth/me'));
        $this->assertSame([['user_id' => $other->user_id]], $this->rows('SELECT user_id FROM sessions WHERE user_id IS NOT NULL'));
    }

    public function testAProtectedAccountCanChangeItsPasswordButNotDeleteItself(): void
    {
        $this->setEnv('PROTECTED_ADMIN_EMAILS', 'READER@example.test');

        $this->assertSame(
            ['error' => "This account is protected and can't be deleted."],
            $this->assertJsonResponse(403, $this->request('DELETE', '/account', ['password' => self::PASSWORD]))
        );
        $this->assertSame(1, $this->rowCount('users'));

        $this->assertJsonResponse(200, $this->post('/account/change-password', ['current_password' => self::PASSWORD, 'new_password' => 'a brand new passphrase']));
        $this->assertTrue(password_verify('a brand new passphrase', $this->passwordHash()));
    }

    public function testDeletingTheAccountNeedsItsPassword(): void
    {
        $incorrect = ['error' => 'Password is incorrect.'];
        $this->insertReading('mine000001', $this->userId);

        $this->assertSame($incorrect, $this->assertJsonResponse(403, $this->request('DELETE', '/account', ['password' => 'not it'])));
        $this->assertSame($incorrect, $this->assertJsonResponse(403, $this->request('DELETE', '/account')));

        $this->assertSame(1, $this->rowCount('users'));
        $this->assertSame(1, $this->rowCount('readings'));
        $this->assertJsonResponse(200, $this->get('/account/readings'));
    }

    private function passwordHash(): string
    {
        $hash = $this->service(UserRepository::class)->getPasswordHash($this->userId);
        $this->assertNotNull($hash);
        return $hash;
    }

    private function insertReading(string $id, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO readings (reading_id, reading_info, user_id) VALUES (?, ?, ?)')
            ->execute([$id, '{"deck_id":1,"draw":[]}', $userId]);
    }

    private function deck(): int
    {
        $system = $this->service(DeckSystemData::class)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS']);
        $this->assertNotNull($system);
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        return $deck->deck_id;
    }
}
