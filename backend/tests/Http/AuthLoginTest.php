<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Routes\Internal\UserController;
use Tarot\Repository\UserRepository;
use Tarot\Service\AuthService;
use Tarot\Service\TurnstileService;
use Tarot\Structure\User;
use Tarot\Utility\Session;
use Tarot\Structure\UserCredentials;

/**
 * Password sign-in, sign-out and "me" beyond the happy path SignInFlowTest walks:
 * what a failed sign-in gives away, the per-IP limit, the captcha, "remember me",
 * and what happens to the session id on the way in and out.
 */
#[CoversClass(UserController::class)]
#[CoversClass(AuthService::class)]
#[CoversClass(Session::class)]
#[CoversClass(TurnstileService::class)]
#[CoversClass(UserCredentials::class)]
final class AuthLoginTest extends HttpTestCase
{
    private const string EMAIL = 'reader@example.test';
    private const string INVALID = 'Invalid email or password.';

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = 'test-only-' . bin2hex(random_bytes(8));
    }

    public function testAnUnknownEmailAndAWrongPasswordGetTheSameAnswer(): void
    {
        $this->createUserWithPassword(self::EMAIL);

        $unknown = $this->assertJsonResponse(401, $this->signIn('nobody@example.test', $this->password));
        $wrong = $this->assertJsonResponse(401, $this->signIn(self::EMAIL, 'not-' . $this->password));

        $this->assertSame(['error' => self::INVALID], $unknown);
        $this->assertSame($unknown, $wrong);
    }

    public function testAnUnactivatedAccountIsOnlyNamedAsSuchToSomeoneWithItsPassword(): void
    {
        $this->createUserWithPassword(self::EMAIL, active: false);

        $this->assertSame(['error' => self::INVALID], $this->assertJsonResponse(401, $this->signIn(self::EMAIL, 'not-' . $this->password)));
        $this->assertSame(
            ['error' => 'Your account is not activated yet. Please use the activation link sent to your email.'],
            $this->assertJsonResponse(403, $this->signIn(self::EMAIL, $this->password))
        );
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testTheEmailIsMatchedWhateverItsCaseAndSurroundingSpaces(): void
    {
        $this->createUserWithPassword(self::EMAIL);

        $signedIn = $this->assertJsonResponse(200, $this->signIn('  Reader@EXAMPLE.test ', $this->password));

        $this->assertDocumented('post', '/auth/login', 200, $signedIn);
        $this->assertIsArray($signedIn['user']);
        $this->assertSame(self::EMAIL, $signedIn['user']['email']);
    }

    public function testSigningInRecordsTheLoginTime(): void
    {
        $user = $this->createUserWithPassword(self::EMAIL);
        $this->assertNull($user->last_login_at);

        $signedIn = $this->assertJsonResponse(200, $this->signIn(self::EMAIL, $this->password));

        $this->assertIsArray($signedIn['user']);
        $this->assertIsString($signedIn['user']['last_login_at']);
        $this->assertSame([['last_login_at' => $signedIn['user']['last_login_at']]], $this->rows('SELECT last_login_at FROM users'));
    }

    public function testAnAccountWithPasswordLoginTurnedOffIsRefusedButOnlyToldWithTheRightPassword(): void
    {
        $user = $this->createUserWithPassword(self::EMAIL);
        $this->service(UserRepository::class)->setPasswordLoginDisabled($user->user_id, true);

        $this->assertSame(['error' => self::INVALID], $this->assertJsonResponse(401, $this->signIn(self::EMAIL, 'not-' . $this->password)));
        $this->assertSame(
            ['error' => 'Password login is disabled for this account. Please sign in with your passkey or Google.'],
            $this->assertJsonResponse(403, $this->signIn(self::EMAIL, $this->password))
        );
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testAPasswordHashedWithOlderSettingsIsUpgradedOnSignIn(): void
    {
        $user = $this->createUser(self::EMAIL);
        $this->service(UserRepository::class)->updatePasswordHash($user->user_id, password_hash($this->password, PASSWORD_BCRYPT, ['cost' => 4]));

        $this->assertJsonResponse(200, $this->signIn(self::EMAIL, $this->password));

        $hash = $this->service(UserRepository::class)->getPasswordHash($user->user_id);
        $this->assertIsString($hash);
        $this->assertFalse(password_needs_rehash($hash, AuthService::passwordAlgo()));
        $this->assertTrue(password_verify($this->password, $hash));
    }

    public function testTenFailedSignInsFromOneAddressBlockTheEleventhEvenWithTheRightPassword(): void
    {
        $this->createUserWithPassword(self::EMAIL);

        for ($i = 0; $i < 10; $i++) {
            $this->assertJsonResponse(401, $this->signIn("guess{$i}@example.test", $this->password));
        }

        $this->assertSame(
            ['error' => 'Too many attempts. Please try again later.'],
            $this->assertJsonResponse(429, $this->signIn(self::EMAIL, $this->password))
        );
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testASuccessfulSignInResetsTheFailureCount(): void
    {
        $this->createUserWithPassword(self::EMAIL);

        for ($i = 0; $i < 9; $i++) {
            $this->signIn("guess{$i}@example.test", $this->password);
        }
        $this->assertJsonResponse(200, $this->signIn(self::EMAIL, $this->password));

        // Without the reset this would be the 19th failure, well past the limit.
        for ($i = 0; $i < 10; $i++) {
            $this->assertJsonResponse(401, $this->signIn("guess{$i}@example.test", $this->password));
        }
    }

    public function testRememberMeKeepsTheSessionForThirtyDaysInsteadOfOne(): void
    {
        $this->createUserWithPassword(self::EMAIL);

        $this->assertJsonResponse(200, $this->signIn(self::EMAIL, $this->password));
        $this->assertLessThanOrEqual(time() + Session::DEFAULT_LIFETIME, $this->sessionExpiry());

        $this->post('/auth/logout');
        $this->assertJsonResponse(200, $this->post('/auth/login', [
            'email' => self::EMAIL, 'password' => $this->password, 'remember_me' => true,
        ]));
        $this->assertGreaterThan(time() + Session::REMEMBER_ME_LIFETIME - 60, $this->sessionExpiry());

        // Still remembered on the next visit, which slides the window along.
        $this->assertJsonResponse(200, $this->get('/auth/me'));
        $this->assertGreaterThan(time() + Session::REMEMBER_ME_LIFETIME - 60, $this->sessionExpiry());
    }

    public function testSigningOutRetiresTheSessionIdItself(): void
    {
        $user = $this->createUserWithPassword(self::EMAIL);
        $this->signInAs($user->user_id);
        $signedIn = $this->sessionCookie();

        $signedOut = $this->assertJsonResponse(200, $this->post('/auth/logout'));
        $this->assertDocumented('post', '/auth/logout', 200, $signedOut);
        $this->assertSame(['success' => true], $signedOut);
        $this->assertNotSame($signedIn, $this->sessionCookie());

        // A copy of the old cookie (say, a stolen one) doesn't come back to life.
        $this->useSessionCookie($signedIn);
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/auth/me')));
    }

    public function testSigningOutWithoutASessionStillSucceeds(): void
    {
        $this->assertSame(['success' => true], $this->assertJsonResponse(200, $this->post('/auth/logout')));
    }

    public function testASessionWhoseAccountWasDeletedIsSignedOut(): void
    {
        $user = $this->createUserWithPassword(self::EMAIL);
        $this->signInAs($user->user_id);
        $this->service(UserRepository::class)->delete($user->user_id);

        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->get('/auth/me')));
    }

    public function testMeDescribesTheSignedInUserWithoutSecrets(): void
    {
        $user = $this->createUserWithPassword(self::EMAIL);
        $this->signInAs($user->user_id);

        $me = $this->assertJsonResponse(200, $this->get('/auth/me'));

        $this->assertDocumented('get', '/auth/me', 200, $me);
        $this->assertIsArray($me['user']);
        $this->assertSame($user->user_id, $me['user']['user_id']);
        $this->assertStringNotContainsString('$argon2', json_encode($me, JSON_THROW_ON_ERROR));
    }

    public function testWithTurnstileConfiguredASignInWithoutAPassedChallengeIsRefused(): void
    {
        $this->createUserWithPassword(self::EMAIL);
        $this->configureTurnstile(passes: false);

        $refused = ['error' => 'Captcha verification failed. Please try again.'];
        $this->assertSame($refused, $this->assertJsonResponse(400, $this->signIn(self::EMAIL, $this->password)));
        $this->assertSame($refused, $this->assertJsonResponse(400, $this->post('/auth/login', [
            'email' => self::EMAIL, 'password' => $this->password, 'turnstile_token' => 'rejected-by-cloudflare',
        ])));
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testWithTurnstileConfiguredAPassedChallengeSignsIn(): void
    {
        $this->createUserWithPassword(self::EMAIL);
        $this->configureTurnstile(passes: true);

        $this->assertJsonResponse(200, $this->post('/auth/login', [
            'email' => self::EMAIL, 'password' => $this->password, 'turnstile_token' => 'passed-the-challenge',
        ]));
        $this->assertJsonResponse(200, $this->get('/auth/me'));
    }

    public function testFailedChallengesCountTowardsTheLimit(): void
    {
        $this->createUserWithPassword(self::EMAIL);
        $this->configureTurnstile(passes: false);

        for ($i = 0; $i < 10; $i++) {
            $this->assertJsonResponse(400, $this->signIn(self::EMAIL, $this->password));
        }

        $this->assertJsonResponse(429, $this->signIn(self::EMAIL, $this->password));
    }

    private function createUserWithPassword(string $email, bool $active = true): User
    {
        $user = $this->createUser($email, active: $active);
        $this->service(UserRepository::class)->updatePasswordHash($user->user_id, password_hash($this->password, AuthService::passwordAlgo()));
        return $user;
    }

    private function signIn(string $email, string $password): ResponseInterface
    {
        return $this->post('/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** Turnstile switched on, with Cloudflare's answer stood in. */
    private function configureTurnstile(bool $passes): void
    {
        $this->setEnv('CLOUDFLARE_TURNSTILE_SITEKEY', '1x00000000000000000000AA');
        $this->setEnv('CLOUDFLARE_TURNSTILE_SECRET', '1x0000000000000000000000000000000AA');
        $this->replaceService(TurnstileService::class, new class ($passes) extends TurnstileService {
            public function __construct(private readonly bool $passes) {}

            #[\Override]
            protected function postSiteVerify(array $payload): array
            {
                return ['success' => $this->passes];
            }
        });
    }

    /** When the browser's current session row expires (the table keys rows by the id's hash). */
    private function sessionExpiry(): int
    {
        $cookie = $this->sessionCookie();
        $this->assertNotNull($cookie);
        $row = $this->rows("SELECT expires FROM sessions WHERE id = '" . hash('sha256', $cookie) . "'");
        $this->assertCount(1, $row);
        return (int) $row[0]['expires'];
    }
}
