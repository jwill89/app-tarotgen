<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\AccountController;
use Routes\Middleware\UserAuth;
use Tarot\Repository\UserRepository;
use Tarot\Service\AuthService;
use Tarot\Service\PluginTokenService;
use Tarot\Utility\DbSessionHandler;
use Tarot\Utility\Session;

/**
 * "Sign out of all other devices": every other session of the account ends,
 * this browser stays signed in under a new id (so a copy of its old cookie is
 * signed out too), linked plugins keep their tokens, and nobody else is touched.
 * Only a signed-in browser may ask, never a plugin's token.
 */
#[CoversClass(AccountController::class)]
#[CoversClass(AuthService::class)]
#[CoversClass(DbSessionHandler::class)]
#[CoversClass(Session::class)]
#[CoversClass(UserAuth::class)]
final class AccountSessionsTest extends HttpTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('reader@example.test')->user_id;
        $this->signInAs($this->userId);
    }

    public function testEveryOtherDeviceIsSignedOutAndThisOneStays(): void
    {
        $here = $this->sessionCookie();
        $plugin = $this->service(PluginTokenService::class)->issueToken($this->userId);
        // The same account on a phone and a laptop, and someone else entirely.
        $phone = $this->signInElsewhere($this->userId);
        $laptop = $this->signInElsewhere($this->userId);
        $someoneElse = $this->signInElsewhere($this->createUser('other@example.test')->user_id);
        $this->useSessionCookie($here);

        $ended = $this->assertJsonResponse(200, $this->post('/account/sessions/end-others'));

        $this->assertDocumented('post', '/account/sessions/end-others', 200, $ended);
        $this->assertSame(['success' => true, 'ended' => 2], $ended);
        // This browser carries on, under a new id.
        $this->assertNotSame($here, $this->sessionCookie());
        $this->assertJsonResponse(200, $this->get('/auth/me'));
        // A copy of its old cookie, the phone and the laptop are signed out.
        foreach ([$here, $phone, $laptop] as $cookie) {
            $this->useSessionCookie($cookie);
            $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/auth/me')));
        }
        $this->assertCount(1, $this->rows("SELECT 1 FROM sessions WHERE user_id = {$this->userId}"));
        // Someone else's session is theirs.
        $this->useSessionCookie($someoneElse);
        $this->assertJsonResponse(200, $this->get('/auth/me'));
        // A linked plugin is revoked under Connected Apps, not by this.
        $this->useSessionCookie(null);
        $this->assertJsonResponse(200, $this->get('/account/readings', ['Authorization' => "Bearer {$plugin}"]));
    }

    public function testWithNoOtherDeviceNothingIsEnded(): void
    {
        $ended = $this->assertJsonResponse(200, $this->post('/account/sessions/end-others'));

        $this->assertSame(['success' => true, 'ended' => 0], $ended);
        $this->assertJsonResponse(200, $this->get('/auth/me'));
    }

    public function testASessionThatHadAlreadyRunOutIsNotCounted(): void
    {
        $here = $this->sessionCookie();
        $stale = $this->signInElsewhere($this->userId);
        $this->assertNotNull($stale);
        $this->db->exec('UPDATE sessions SET expires = ' . (time() - 1) . " WHERE id = '" . hash('sha256', $stale) . "'");
        $this->useSessionCookie($here);

        $ended = $this->assertJsonResponse(200, $this->post('/account/sessions/end-others'));

        $this->assertSame(['success' => true, 'ended' => 0], $ended);
        $this->assertCount(1, $this->rows("SELECT 1 FROM sessions WHERE user_id = {$this->userId}"));
    }

    public function testARememberedSessionStaysRemembered(): void
    {
        $users = $this->service(UserRepository::class);
        $users->updatePasswordHash($this->userId, password_hash('current passphrase 1', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->post('/auth/logout');
        $this->assertJsonResponse(200, $this->post('/auth/login', ['email' => 'reader@example.test', 'password' => 'current passphrase 1', 'remember_me' => true]));

        $this->assertJsonResponse(200, $this->post('/account/sessions/end-others'));

        $cookie = $this->sessionCookie();
        $this->assertNotNull($cookie);
        $expires = $this->rows("SELECT expires FROM sessions WHERE id = '" . hash('sha256', $cookie) . "'");
        $this->assertCount(1, $expires);
        $this->assertGreaterThan(time() + Session::REMEMBER_ME_LIFETIME - 60, (int) $expires[0]['expires']);
    }

    public function testALinkedPluginsTokenCannotSignTheAccountOut(): void
    {
        $here = $this->sessionCookie();
        $token = $this->service(PluginTokenService::class)->issueToken($this->userId);
        $this->useSessionCookie(null);

        $refused = $this->post('/account/sessions/end-others', [], ['Authorization' => "Bearer {$token}"]);

        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $refused));
        $this->useSessionCookie($here);
        $this->assertJsonResponse(200, $this->get('/auth/me'));
    }

    /** Sign $userId in from another browser; returns its cookie. This browser's is set aside. */
    private function signInElsewhere(int $userId): ?string
    {
        $this->useSessionCookie(null);
        $this->signInAs($userId);

        return $this->sessionCookie();
    }
}
