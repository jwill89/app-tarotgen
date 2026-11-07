<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Routes\Internal\GoogleAuthController;
use Tarot\Repository\PasskeyRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\GoogleOAuthService;
use Tarot\Service\SignInMethods;
use Tarot\Structure\User;
use Tarot\Utility\Session;
use Tarot\Data\UserData;

/**
 * "Sign in with Google": the redirect out with a state, the callback that must
 * bring the same state back to the same browser, and what the callback does for
 * each intent — sign in, sign up, or link to the signed-in account — plus
 * unlinking. Google itself is stood in for: it accepts self::CODE and answers
 * with $this->profile.
 */
#[CoversClass(GoogleAuthController::class)]
#[CoversClass(GoogleOAuthService::class)]
#[CoversClass(SignInMethods::class)]
#[CoversClass(UserRepository::class)]
#[CoversClass(Session::class)]
#[CoversClass(UserData::class)]
final class GoogleAuthTest extends HttpTestCase
{
    private const string CODE = 'code-from-google';
    private const string CLIENT_ID = 'tarotgen-test.apps.googleusercontent.com';
    private const array BAD_STATE = ['oauth_error' => 'Invalid OAuth state. Please try again.'];

    /** The Google account that approves the sign-in, unless a test changes $profile. */
    private const array GOOGLE_USER = [
        'sub' => 'google-sub-1', 'email' => 'Reader@Example.test', 'email_verified' => true, 'name' => 'Reader', 'picture' => '',
    ];

    /** @var array{sub:string,email:string,email_verified:bool,name:string,picture:string}|null Null: Google won't say. */
    private ?array $profile = self::GOOGLE_USER;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnv('GOOGLE_CLIENT_ID', self::CLIENT_ID);
        $this->setEnv('GOOGLE_CLIENT_SECRET', 'test-only-secret');
        $this->setEnv('GOOGLE_REDIRECT_URI', 'https://' . self::HOST . '/api/auth/google/callback');

        // Only the two calls that leave for Google are stood in; the rest is the real service.
        $this->replaceService(GoogleOAuthService::class, new class (self::CODE, fn(): ?array => $this->profile) extends GoogleOAuthService {
            private const string ACCESS_TOKEN = 'access-token-from-google';

            /** @param \Closure(): (array{sub:string,email:string,email_verified:bool,name:string,picture:string}|null) $profile */
            public function __construct(private readonly string $code, private readonly \Closure $profile) {}

            #[\Override]
            public function exchangeCode(string $code): ?array
            {
                return $code === $this->code ? ['access_token' => self::ACCESS_TOKEN, 'id_token' => ''] : null;
            }

            #[\Override]
            public function fetchUserInfo(string $accessToken): ?array
            {
                return $accessToken === self::ACCESS_TOKEN ? ($this->profile)() : null;
            }
        });
    }

    public function testWithoutGoogleConfiguredTheFlowCannotStart(): void
    {
        $this->setEnv('GOOGLE_CLIENT_SECRET', '');

        $this->assertSame(['error' => 'Google OAuth is not configured.'], $this->assertJsonResponse(503, $this->get('/auth/google')));
    }

    public function testTheFlowStartsAtGoogleWithAFreshUnguessableState(): void
    {
        $response = $this->get('/auth/google');

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(self::CLIENT_ID, $query['client_id'] ?? null);
        $this->assertSame('https://' . self::HOST . '/api/auth/google/callback', $query['redirect_uri'] ?? null);
        $this->assertIsString($query['state'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $query['state']);

        $this->assertNotSame($query['state'], $this->startFlow());
    }

    public function testLinkingCannotStartWithoutASignedInUser(): void
    {
        $this->assertSame(
            ['error' => 'You must be logged in to link a Google account.'],
            $this->assertJsonResponse(401, $this->get('/auth/google?intent=link'))
        );
    }

    public function testACallbackWithoutTheStateItWasGivenIsRefused(): void
    {
        $this->startFlow();

        $this->assertRedirect('/login', self::BAD_STATE, $this->returnFromGoogle(['code' => self::CODE]));

        // The failed check also spent the state the browser was given.
        $state = $this->startFlow();
        $this->assertRedirect('/login', self::BAD_STATE, $this->returnFromGoogle(['code' => self::CODE, 'state' => 'not-' . $state]));
        $this->assertRedirect('/login', self::BAD_STATE, $this->returnFromGoogle(['code' => self::CODE, 'state' => $state]));

        $this->assertSame(0, $this->rowCount('users'));
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testAStateBelongsToTheBrowserItWasIssuedTo(): void
    {
        // Login CSRF: an attacker's own state, pushed into a victim's browser.
        $state = $this->startFlow();
        $this->useSessionCookie(null);

        $this->assertRedirect('/login', self::BAD_STATE, $this->returnFromGoogle(['code' => self::CODE, 'state' => $state]));
        $this->assertSame(0, $this->rowCount('users'));
    }

    public function testAStateWorksOnce(): void
    {
        $state = $this->startFlow();
        $this->assertRedirect('/', [], $this->returnFromGoogle(['code' => self::CODE, 'state' => $state]));

        $this->assertRedirect('/login', self::BAD_STATE, $this->returnFromGoogle(['code' => self::CODE, 'state' => $state]));
    }

    public function testCancellingAtGoogleReturnsToSignInWithGooglesReason(): void
    {
        $state = $this->startFlow();

        $this->assertRedirect('/login', ['oauth_error' => 'access_denied'], $this->returnFromGoogle(['error' => 'access_denied', 'state' => $state]));
    }

    public function testAFailedTokenExchangeOrProfileFetchReturnsToSignIn(): void
    {
        $this->assertRedirect('/login', ['oauth_error' => 'Failed to authenticate with Google.'], $this->returnFromGoogle(['code' => 'expired-code', 'state' => $this->startFlow()]));

        $this->profile = null;
        $this->assertRedirect('/login', ['oauth_error' => 'Could not retrieve your Google profile.'], $this->returnFromGoogle(['code' => self::CODE, 'state' => $this->startFlow()]));

        $this->assertSame(0, $this->rowCount('users'));
    }

    public function testAnUnverifiedGoogleEmailCannotSignInOrSignUp(): void
    {
        // Matching accounts by an unverified email would let anyone claim one.
        $reader = $this->createUser('reader@example.test');
        $this->profile = ['email_verified' => false] + self::GOOGLE_USER;
        $unverified = ['oauth_error' => 'Your Google email address is not verified. Please verify it with Google and try again.'];

        $this->assertRedirect('/login', $unverified, $this->completeFlow('login'));
        $this->assertRedirect('/register', $unverified, $this->completeFlow('register'));

        $this->assertFalse($this->user($reader->user_id)->google_linked);
        $this->assertSame(1, $this->rowCount('users'));
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testSigningInWithANewEmailCreatesAnActiveRememberedAccount(): void
    {
        $state = $this->startFlow();
        $visitor = $this->sessionCookie();

        $this->assertRedirect('/', [], $this->returnFromGoogle(['code' => self::CODE, 'state' => $state]));

        $me = $this->signedInUser();
        $this->assertSame('reader@example.test', $me['email']);
        $this->assertSame('Reader', $me['display_name']);
        $this->assertTrue($me['is_active']);
        $this->assertTrue($me['google_linked']);
        $this->assertIsString($me['last_login_at']);

        $this->assertNotSame($visitor, $this->sessionCookie());
        $this->assertGreaterThan(time() + Session::REMEMBER_ME_LIFETIME - 60, $this->sessionExpiry());

        // No password was set: a Google-only account can't sign in with an empty one.
        $this->assertJsonResponse(401, $this->post('/auth/login', ['email' => 'reader@example.test', 'password' => '']));
    }

    public function testSigningInLinksAndActivatesAnExistingAccountWithThatEmail(): void
    {
        $reader = $this->createUser('reader@example.test', active: false);

        $this->assertRedirect('/', [], $this->completeFlow('login'));

        $this->assertSame($reader->user_id, $this->signedInUser()['user_id']);
        $this->assertTrue($this->user($reader->user_id)->google_linked);
        $this->assertTrue($this->user($reader->user_id)->is_active);
        $this->assertSame(1, $this->rowCount('users'));
    }

    public function testALinkedGoogleAccountSignsInEvenAfterItsEmailChanges(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->service(UserRepository::class)->setGoogleId($reader->user_id, 'google-sub-1');
        $this->profile = ['email' => 'renamed@example.test'] + self::GOOGLE_USER;

        $this->assertRedirect('/', [], $this->completeFlow('login'));

        $this->assertSame($reader->user_id, $this->signedInUser()['user_id']);
        $this->assertSame(1, $this->rowCount('users'));
    }

    public function testANewAccountsDisplayNameAvoidsOnesAlreadyTaken(): void
    {
        $someone = $this->createUser('someone@example.test');
        $this->service(UserRepository::class)->updateDisplayName($someone->user_id, 'Reader');

        $this->assertRedirect('/', [], $this->completeFlow('login'));
        $this->assertSame('Reader1', $this->signedInUser()['display_name']);

        $this->post('/auth/logout');
        $this->profile = ['sub' => 'google-sub-2', 'email' => 'nameless@example.test', 'name' => '  '] + self::GOOGLE_USER;
        $this->assertRedirect('/', [], $this->completeFlow('login'));
        $this->assertSame('User', $this->signedInUser()['display_name']);
    }

    public function testAnIntentItDoesNotKnowIsTreatedAsSigningIn(): void
    {
        $this->assertRedirect('/', [], $this->completeFlow('admin'));

        $this->assertSame('reader@example.test', $this->signedInUser()['email']);
    }

    public function testSigningUpWithGoogleCreatesTheAccount(): void
    {
        $this->assertRedirect('/', [], $this->completeFlow('register'));

        $me = $this->signedInUser();
        $this->assertSame('reader@example.test', $me['email']);
        $this->assertTrue($me['google_linked']);
        $this->assertGreaterThan(time() + Session::REMEMBER_ME_LIFETIME - 60, $this->sessionExpiry());
    }

    public function testSigningUpWithAnEmailThatHasAnAccountSendsYouToSignInInstead(): void
    {
        $reader = $this->createUser('reader@example.test');

        $this->assertRedirect(
            '/register',
            ['oauth_error' => 'An account with that email already exists. Try logging in with Google instead.'],
            $this->completeFlow('register')
        );

        $this->assertFalse($this->user($reader->user_id)->google_linked);
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testSigningUpWithAnAlreadyLinkedGoogleAccountSignsIntoIt(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->service(UserRepository::class)->setGoogleId($reader->user_id, 'google-sub-1');

        $this->assertRedirect('/', [], $this->completeFlow('register'));

        $this->assertSame($reader->user_id, $this->signedInUser()['user_id']);
        $this->assertSame(1, $this->rowCount('users'));
    }

    public function testLinkingAddsGoogleToTheSignedInAccountWhateverItsEmail(): void
    {
        // Linking binds Google's account id to whoever is signed in; the email (even
        // an unverified one) plays no part.
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->profile = ['email' => 'elsewhere@example.test', 'email_verified' => false] + self::GOOGLE_USER;

        $this->assertRedirect('/account/settings', ['oauth_success' => 'Google account linked successfully!'], $this->completeFlow('link'));

        $me = $this->signedInUser();
        $this->assertSame($reader->user_id, $me['user_id']);
        $this->assertSame('reader@example.test', $me['email']);
        $this->assertTrue($me['google_linked']);
    }

    public function testAGoogleAccountLinkedToSomeoneElseStaysWithThem(): void
    {
        $owner = $this->createUser('owner@example.test');
        $this->service(UserRepository::class)->setGoogleId($owner->user_id, 'google-sub-1');
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);

        $this->assertRedirect(
            '/account/settings',
            ['oauth_error' => 'That Google account is already linked to a different user.'],
            $this->completeFlow('link')
        );

        $this->assertFalse($this->user($reader->user_id)->google_linked);
        $this->assertSame($owner->user_id, $this->service(UserRepository::class)->findByGoogleId('google-sub-1')?->user_id);
    }

    public function testSigningOutBeforeGoogleAnswersALinkAsksYouToSignInAgain(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $state = $this->startFlow('link');
        $this->post('/auth/logout');

        $this->assertRedirect(
            '/login',
            ['oauth_error' => 'Session expired. Please log in and try again.'],
            $this->returnFromGoogle(['code' => self::CODE, 'state' => $state])
        );
        $this->assertFalse($this->user($reader->user_id)->google_linked);
    }

    public function testUnlinkingNeedsASignedInUser(): void
    {
        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->post('/auth/google/unlink')));
    }

    public function testAnAccountThatIsNotActiveCannotUnlink(): void
    {
        $reader = $this->createUser('reader@example.test', active: false);
        $this->service(UserRepository::class)->setGoogleId($reader->user_id, 'google-sub-1');
        $this->signInAs($reader->user_id);

        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->post('/auth/google/unlink')));
        $this->assertSame([['google_id' => 'google-sub-1']], $this->rows('SELECT google_id FROM users'));
    }

    public function testAnAccountWithAPasswordCanUnlinkGoogle(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->service(UserRepository::class)->setGoogleId($reader->user_id, 'google-sub-1');
        $this->signInAs($reader->user_id);

        $unlinked = $this->assertJsonResponse(200, $this->post('/auth/google/unlink'));

        $this->assertDocumented('post', '/auth/google/unlink', 200, $unlinked);
        $this->assertTrue($unlinked['success']);
        $this->assertIsArray($unlinked['user']);
        $this->assertFalse($unlinked['user']['google_linked']);
        $this->assertSame([['google_id' => null]], $this->rows('SELECT google_id FROM users'));
    }

    public function testAGoogleOnlyAccountCannotUnlinkItsOnlyWayIn(): void
    {
        $this->assertRedirect('/', [], $this->completeFlow('login'));
        $this->assertFalse($this->signedInUser()['has_password']);

        $refused = $this->assertJsonResponse(409, $this->post('/auth/google/unlink'));

        $this->assertSame(['error' => 'That would leave you no way to sign in. First set a password or add a passkey.'], $refused);
        $this->assertTrue($this->signedInUser()['google_linked']);
    }

    public function testWithPasswordLoginOffGoogleStaysUntilThereIsAnotherWayIn(): void
    {
        $reader = $this->createUser('reader@example.test');
        $users = $this->service(UserRepository::class);
        $users->setGoogleId($reader->user_id, 'google-sub-1');
        $users->setPasswordLoginDisabled($reader->user_id, true);
        $this->signInAs($reader->user_id);

        $this->assertSame(
            ['error' => 'That would leave you no way to sign in. First turn password login back on or add a passkey.'],
            $this->assertJsonResponse(409, $this->post('/auth/google/unlink'))
        );
        $this->assertSame([['google_id' => 'google-sub-1']], $this->rows('SELECT google_id FROM users'));

        // A passkey is another way in.
        $this->service(PasskeyRepository::class)->create($reader->user_id, 'cred-1', '-----PEM-----', 'Laptop', 0);
        $this->assertJsonResponse(200, $this->post('/auth/google/unlink'));
        $this->assertSame([['google_id' => null]], $this->rows('SELECT google_id FROM users'));
    }

    public function testAGoogleOnlyAccountWithAPasskeyCanUnlink(): void
    {
        $this->assertRedirect('/', [], $this->completeFlow('login'));
        $this->service(PasskeyRepository::class)->create((int) $this->signedInUser()['user_id'], 'cred-1', '-----PEM-----', 'Laptop', 0);

        $unlinked = $this->assertJsonResponse(200, $this->post('/auth/google/unlink'));

        $this->assertIsArray($unlinked['user']);
        $this->assertSame([false, false, true], [$unlinked['user']['google_linked'], $unlinked['user']['has_password'], $unlinked['user']['has_passkeys']]);
    }

    public function testAGoogleOnlyAccountCanSetAPasswordAndThenUnlink(): void
    {
        $this->assertRedirect('/', [], $this->completeFlow('login'));
        $this->post('/auth/logout');

        $forgot = $this->assertJsonResponse(200, $this->post('/auth/forgot-password', ['email' => 'reader@example.test']));
        $this->assertIsString($forgot['reset_link']);
        parse_str((string) parse_url($forgot['reset_link'], PHP_URL_QUERY), $query);
        $password = 'test-only-' . bin2hex(random_bytes(8));
        $this->assertJsonResponse(200, $this->post('/auth/reset-password', ['token' => $query['token'] ?? '', 'password' => $password]));
        $this->assertJsonResponse(200, $this->post('/auth/login', ['email' => 'reader@example.test', 'password' => $password]));
        $this->assertTrue($this->signedInUser()['has_password']);

        $this->assertJsonResponse(200, $this->post('/auth/google/unlink'));
        $this->assertFalse($this->signedInUser()['google_linked']);
    }

    public function testUnlinkingFromADeletedAccountsSessionIsRefused(): void
    {
        $reader = $this->createUser('reader@example.test');
        $this->signInAs($reader->user_id);
        $this->service(UserRepository::class)->delete($reader->user_id);

        $this->assertSame(['error' => 'Not authenticated'], $this->assertJsonResponse(401, $this->post('/auth/google/unlink')));
    }

    /** GET /auth/google; returns the state Google would echo back to the callback. */
    private function startFlow(string $intent = 'login'): string
    {
        $response = $this->get('/auth/google?intent=' . $intent);
        $this->assertSame(302, $response->getStatusCode());
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['state'] ?? null);
        return $query['state'];
    }

    /** The whole round trip, with Google approving. */
    private function completeFlow(string $intent): ResponseInterface
    {
        return $this->returnFromGoogle(['code' => self::CODE, 'state' => $this->startFlow($intent)]);
    }

    /** @param array<string,string> $query */
    private function returnFromGoogle(array $query): ResponseInterface
    {
        return $this->get('/auth/google/callback?' . http_build_query($query));
    }

    /**
     * The callback answered with a redirect into the SPA at $path, carrying $query.
     *
     * @param array<string,string> $query
     */
    private function assertRedirect(string $path, array $query, ResponseInterface $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        $this->assertSame($path, parse_url($location, PHP_URL_PATH), "Redirected to {$location}");
        parse_str((string) parse_url($location, PHP_URL_QUERY), $actual);
        $this->assertSame($query, $actual, "Redirected to {$location}");
    }

    /** @return array<mixed> /auth/me's user: the browser is signed in. */
    private function signedInUser(): array
    {
        $me = $this->assertJsonResponse(200, $this->get('/auth/me'));
        $this->assertIsArray($me['user']);
        return $me['user'];
    }

    private function user(int $userId): User
    {
        $user = $this->service(UserRepository::class)->findById($userId);
        $this->assertNotNull($user);
        return $user;
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
