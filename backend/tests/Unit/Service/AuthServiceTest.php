<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use ArrayObject;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\UserData;
use Tarot\Data\UserTokenData;
use Tarot\Repository\UserRepository;
use Tarot\Repository\UserTokenRepository;
use Tarot\Service\AccountProtection;
use Tarot\Service\AuthService;
use Tarot\Service\Mailer;
use Tarot\Utility\AfterResponse;
use Tarot\Structure\User;
use Tarot\Utility\DbSessionHandler;

#[CoversClass(AuthService::class)]
#[CoversClass(UserData::class)]
#[CoversClass(UserTokenData::class)]
#[CoversClass(UserRepository::class)]
#[CoversClass(UserTokenRepository::class)]
final class AuthServiceTest extends TestCase
{
    private PDO $pdo;
    private AuthService $auth;
    private UserRepository $userRepo;

    /** @var ArrayObject<int,array{string,string}> Each sign-up attempt notice sent, as [to, sign-in link]. */
    private ArrayObject $attemptNotices;

    private ?string $savedProtected = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Mirror db/migrate_users.php + migrate_google_oauth.php +
        // migrate_passkeys.php. UserData::hydrate() always probes user_passkeys
        // and authenticate() reads users.password_login_disabled, so the test
        // schema must include the OAuth/passkey columns and table.
        $this->pdo->exec(
            'CREATE TABLE users (
                user_id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                display_name TEXT NOT NULL COLLATE NOCASE,
                is_active INTEGER NOT NULL DEFAULT 0,
                is_admin INTEGER NOT NULL DEFAULT 0,
                registered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login_at TEXT DEFAULT NULL,
                google_id TEXT DEFAULT NULL,
                password_login_disabled INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec('CREATE UNIQUE INDEX idx_users_email ON users (email COLLATE NOCASE)');
        $this->pdo->exec('CREATE UNIQUE INDEX idx_users_display_name ON users (display_name COLLATE NOCASE)');
        $this->pdo->exec('CREATE UNIQUE INDEX idx_users_google_id ON users (google_id)');
        $this->pdo->exec(
            'CREATE TABLE user_tokens (
                token_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                used_at TEXT DEFAULT NULL
            )'
        );
        $this->pdo->exec(
            "CREATE TABLE user_passkeys (
                passkey_id     INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id        INTEGER NOT NULL,
                credential_id  TEXT    NOT NULL,
                public_key_pem TEXT    NOT NULL,
                name           TEXT    NOT NULL DEFAULT 'My Passkey',
                sign_count     INTEGER NOT NULL DEFAULT 0,
                created_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at   TEXT    DEFAULT NULL,
                FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
            )"
        );
        // Mirror db/migrate_sessions.php + migrate_sessions_user_id.php.
        $this->pdo->exec(
            "CREATE TABLE sessions (
                id      TEXT    PRIMARY KEY,
                data    TEXT    NOT NULL DEFAULT '',
                expires INTEGER NOT NULL,
                user_id INTEGER DEFAULT NULL
            )"
        );

        $this->savedProtected = $_ENV['PROTECTED_ADMIN_EMAILS'] ?? null;
        $_ENV['PROTECTED_ADMIN_EMAILS'] = '';

        $this->attemptNotices = new ArrayObject();
        $mailer = new class ($this->attemptNotices) extends Mailer {
            /** @param ArrayObject<int,array{string,string}> $notices */
            public function __construct(private readonly ArrayObject $notices)
            {
            }

            #[\Override]
            public function sendRegistrationAttempt(string $toEmail, string $toName, string $signInLink, string $resetLink): bool
            {
                $this->notices->append([$toEmail, $signInLink]);
                return true;
            }
        };

        $this->userRepo = new UserRepository(new UserData($this->pdo));
        $tokens = new UserTokenRepository(new UserTokenData($this->pdo));
        $this->auth = new AuthService($this->userRepo, $tokens, $mailer, new DbSessionHandler($this->pdo), new AccountProtection(), new AfterResponse());
    }

    protected function tearDown(): void
    {
        if ($this->savedProtected === null) {
            unset($_ENV['PROTECTED_ADMIN_EMAILS']);
        } else {
            $_ENV['PROTECTED_ADMIN_EMAILS'] = $this->savedProtected;
        }
    }

    /** A stored session row for $userId (null: signed out). */
    private function addSession(string $id, ?int $userId): void
    {
        $this->pdo->prepare('INSERT INTO sessions (id, data, expires, user_id) VALUES (:id, :data, :exp, :user)')
            ->execute([':id' => hash('sha256', $id), ':data' => '', ':exp' => time() + 3600, ':user' => $userId]);
    }

    /** @return list<int|null> Whose stored sessions remain, oldest first. */
    private function sessionOwners(): array
    {
        $owners = $this->pdo->query('SELECT user_id FROM sessions ORDER BY rowid');
        $this->assertNotFalse($owners);

        return array_values(array_map(static fn(mixed $id): ?int => $id === null ? null : (int)$id, $owners->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function tokenFromLink(string $link): string
    {
        $query = parse_url($link, PHP_URL_QUERY);
        $this->assertIsString($query, "No query string in {$link}");
        parse_str($query, $q);
        $this->assertArrayHasKey('token', $q, "No token in {$link}");
        $this->assertIsString($q['token']);

        return $q['token'];
    }

    /**
     * Register an account the test builds on, failing the test if registration
     * itself doesn't succeed.
     *
     * @return array{user: User, activation_link: string}
     */
    private function registered(string $email, string $displayName, string $password = 'correct horse battery staple'): array
    {
        $result = $this->auth->register($email, $displayName, $password);
        $this->assertTrue($result['ok'], implode(' ', $result['errors'] ?? []));
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('activation_link', $result);

        return ['user' => $result['user'], 'activation_link' => $result['activation_link']];
    }

    private function storedUser(int $userId): User
    {
        $user = $this->userRepo->findById($userId);
        $this->assertNotNull($user, "No user #{$userId}");

        return $user;
    }

    private function resetLinkFor(string $email): string
    {
        $request = $this->auth->requestPasswordReset($email);
        $this->assertArrayHasKey('reset_link', $request, "No reset link issued for {$email}");

        return $request['reset_link'];
    }

    public function testRegisterCreatesInactiveUserWithActivationLink(): void
    {
        $result = $this->auth->register('Person@Example.com', 'Seeker', 'correct horse battery staple');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('user', $result);
        $this->assertFalse($result['user']->is_active);
        $this->assertFalse($result['user']->is_admin);
        // Email is normalised to lowercase.
        $this->assertSame('person@example.com', $result['user']->email);
        $this->assertArrayHasKey('activation_link', $result);
        $this->assertStringContainsString('/activate?token=', $result['activation_link']);
    }

    public function testATakenDisplayNameIsRejectedCaseInsensitively(): void
    {
        $this->auth->register('dupe@example.com', 'Mystic', 'correct horse battery staple');

        $dupeName = $this->auth->register('other@example.com', 'mystic', 'correct horse battery staple');
        $this->assertSame(['ok' => false, 'errors' => ['That display name is already taken.']], $dupeName);
        $this->assertCount(1, $this->userRepo->getAll());
    }

    public function testATakenEmailLooksAcceptedCreatesNothingAndTellsItsOwner(): void
    {
        $this->registered('dupe@example.com', 'Mystic');

        $again = $this->auth->register('  DUPE@example.com ', 'Different', 'another long passphrase');

        // ok, with no account or activation link to show for it.
        $this->assertSame(['ok' => true, 'emailed' => true], $again);
        $this->assertCount(1, $this->userRepo->getAll());
        $this->assertSame([['dupe@example.com', 'https://tarotgen.io/login']], $this->attemptNotices->getArrayCopy());
        // The account itself is untouched: its own password is still the one.
        $this->assertSame('inactive', $this->auth->authenticate('dupe@example.com', 'correct horse battery staple')['status']);
    }

    public function testWeakPasswordIsRejected(): void
    {
        $result = $this->auth->register('weak@example.com', 'Weakling', 'short');
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertNotEmpty($result['errors']);
    }

    public function testLoginFailsBeforeActivationThenSucceedsAfter(): void
    {
        $password = 'correct horse battery staple';
        $reg = $this->registered('flow@example.com', 'Flowy', $password);

        // Not activated yet.
        $this->assertSame('inactive', $this->auth->authenticate('flow@example.com', $password)['status']);

        // Activate via the emailed token.
        $this->assertTrue($this->auth->activate($this->tokenFromLink($reg['activation_link'])));

        // Now login works (case-insensitive email), wrong password fails.
        $ok = $this->auth->authenticate('FLOW@example.com', $password);
        $this->assertSame('ok', $ok['status']);
        $this->assertArrayHasKey('user', $ok);
        $this->assertTrue($ok['user']->is_active);

        $this->assertSame('invalid', $this->auth->authenticate('flow@example.com', 'wrong password here')['status']);
    }

    public function testLoginIsBlockedWhenPasswordLoginIsDisabled(): void
    {
        $password = 'correct horse battery staple';
        $reg = $this->registered('nopw@example.com', 'NoPass', $password);
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));

        // Simulate the user disabling password login (passkey-only), as the
        // account settings / passkey flow does.
        $this->pdo->exec(
            'UPDATE users SET password_login_disabled = 1 WHERE user_id = '
            . $reg['user']->user_id
        );

        // Correct credentials are still refused — login must go via passkey/Google.
        $this->assertSame('password_disabled', $this->auth->authenticate('nopw@example.com', $password)['status']);
        // A wrong password is reported as invalid, not as disabled (no enumeration of the toggle).
        $this->assertSame('invalid', $this->auth->authenticate('nopw@example.com', 'wrong password here')['status']);
    }

    public function testTheStandInHashCostsWhatARealOneDoes(): void
    {
        $this->assertFalse(password_needs_rehash(AuthService::standInHash(), AuthService::passwordAlgo()));
    }

    public function testASignInWithNoPasswordToCheckTakesAsLongAsAWrongPassword(): void
    {
        $password = 'correct horse battery staple';
        $reg = $this->registered('timed@example.com', 'Timed', $password);
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));
        // An account made through Google has no password.
        $this->registered('google@example.com', 'Googler');
        $this->pdo->exec("UPDATE users SET password_hash = '' WHERE email = 'google@example.com'");

        $wrong = $this->fastest(fn(): array => $this->auth->authenticate('timed@example.com', 'wrong password here'));
        $unknown = $this->fastest(fn(): array => $this->auth->authenticate('nobody@example.com', 'wrong password here'));
        $googleOnly = $this->fastest(fn(): array => $this->auth->authenticate('google@example.com', ''));

        // Without the stand-in check these answer in well under a percent of the time.
        $this->assertGreaterThan($wrong * 0.4, $unknown);
        $this->assertGreaterThan($wrong * 0.4, $googleOnly);
    }

    /**
     * The quickest of a few runs of $signIn, in seconds; each must be refused.
     *
     * @param callable(): array{status:string} $signIn
     */
    private function fastest(callable $signIn): float
    {
        $best = PHP_FLOAT_MAX;
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            $result = $signIn();
            $best = min($best, (hrtime(true) - $start) / 1e9);
            $this->assertSame('invalid', $result['status']);
        }

        return $best;
    }

    public function testActivationTokenIsSingleUse(): void
    {
        $reg = $this->registered('once@example.com', 'Once', 'correct horse battery staple');
        $token = $this->tokenFromLink($reg['activation_link']);

        $this->assertTrue($this->auth->activate($token));
        // Re-using the same token must fail.
        $this->assertFalse($this->auth->activate($token));
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->assertFalse($this->auth->activate('deadbeef'));
    }

    public function testResendActivationReissuesAWorkingTokenForInactiveUser(): void
    {
        $reg = $this->registered('resend@example.com', 'Resender', 'correct horse battery staple');
        $userId = $reg['user']->user_id;

        $resent = $this->auth->resendActivation($userId);
        $this->assertTrue($resent['ok']);
        $this->assertArrayHasKey('activation_link', $resent);

        // The original token is invalidated; the freshly issued one activates.
        $this->assertFalse($this->auth->activate($this->tokenFromLink($reg['activation_link'])));
        $this->assertTrue($this->auth->activate($this->tokenFromLink($resent['activation_link'])));
    }

    public function testResendActivationFailsForActiveOrMissingUser(): void
    {
        $reg = $this->registered('active@example.com', 'Active', 'correct horse battery staple');
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));

        $this->assertFalse($this->auth->resendActivation($reg['user']->user_id)['ok']);
        $this->assertFalse($this->auth->resendActivation(99999)['ok']);
    }

    public function testPasswordResetFlowChangesThePassword(): void
    {
        $old = 'correct horse battery staple';
        $new = 'a different long passphrase';
        $reg = $this->registered('reset@example.com', 'Resetter', $old);
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));

        $req = $this->auth->requestPasswordReset('RESET@example.com'); // case-insensitive
        $this->assertTrue($req['ok']);
        $this->assertTrue($req['exists']);
        $this->assertArrayHasKey('reset_link', $req);
        $this->assertStringContainsString('/reset-password?token=', $req['reset_link']);

        $token = $this->tokenFromLink($req['reset_link']);
        $this->assertSame('ok', $this->auth->resetPassword($token, $new)['status']);

        // New password works; old one no longer does.
        $this->assertSame('ok', $this->auth->authenticate('reset@example.com', $new)['status']);
        $this->assertSame('invalid', $this->auth->authenticate('reset@example.com', $old)['status']);
    }

    public function testAPasswordResetEndsEverySessionOfThatAccount(): void
    {
        $reg = $this->registered('reset-out@example.com', 'ResetOut');
        $other = $this->registered('stays@example.com', 'Stays');
        $this->addSession('laptop', $reg['user']->user_id);
        $this->addSession('phone', $reg['user']->user_id);
        $this->addSession('elsewhere', $other['user']->user_id);
        $this->addSession('signed-out', null);
        $token = $this->tokenFromLink($this->resetLinkFor('reset-out@example.com'));

        $this->assertSame('weak', $this->auth->resetPassword($token, 'short')['status']);
        $this->assertCount(4, $this->sessionOwners(), 'a refused reset ends nothing');

        $this->assertSame('ok', $this->auth->resetPassword($token, 'a different long passphrase')['status']);
        $this->assertSame([$other['user']->user_id, null], $this->sessionOwners());
    }

    public function testResetTokenIsSingleUse(): void
    {
        $reg = $this->registered('once-reset@example.com', 'OnceReset', 'correct horse battery staple');
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));
        $token = $this->tokenFromLink($this->resetLinkFor('once-reset@example.com'));

        $this->assertSame('ok', $this->auth->resetPassword($token, 'a different long passphrase')['status']);
        $this->assertSame('invalid', $this->auth->resetPassword($token, 'yet another passphrase')['status']);
    }

    public function testWeakNewPasswordIsRejectedWithoutConsumingTheToken(): void
    {
        $reg = $this->registered('weakreset@example.com', 'WeakReset', 'correct horse battery staple');
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));
        $token = $this->tokenFromLink($this->resetLinkFor('weakreset@example.com'));

        // Weak password rejected...
        $this->assertSame('weak', $this->auth->resetPassword($token, 'short')['status']);
        // ...and the same token still works with a strong one.
        $this->assertSame('ok', $this->auth->resetPassword($token, 'a strong enough passphrase')['status']);
    }

    public function testRequestResetForUnknownEmailReportsNoExistenceButStillOk(): void
    {
        $res = $this->auth->requestPasswordReset('nobody@example.com');
        $this->assertTrue($res['ok']);
        $this->assertFalse($res['exists']);
    }

    public function testActivationTokenCannotBeUsedAsResetToken(): void
    {
        $reg = $this->registered('mix@example.com', 'Mixer', 'correct horse battery staple');
        // The activation token is not valid for a password reset.
        $this->assertSame('invalid', $this->auth->resetPassword($this->tokenFromLink($reg['activation_link']), 'a brand new passphrase')['status']);
    }

    public function testGetAllAndDelete(): void
    {
        $a = $this->registered('one@example.com', 'One', 'correct horse battery staple');
        $this->auth->register('two@example.com', 'Two', 'correct horse battery staple');

        $this->assertCount(2, $this->userRepo->getAll());

        $this->assertTrue($this->userRepo->delete($a['user']->user_id));
        $this->assertCount(1, $this->userRepo->getAll());
        $this->assertNull($this->userRepo->findById($a['user']->user_id));
    }

    public function testSetAdminFlag(): void
    {
        $reg = $this->registered('boss@example.com', 'Boss', 'correct horse battery staple');
        $userId = $reg['user']->user_id;

        $this->assertFalse($this->storedUser($userId)->is_admin);

        $this->userRepo->setAdmin($userId, true);
        $this->assertTrue($this->storedUser($userId)->is_admin);

        $this->userRepo->setAdmin($userId, false);
        $this->assertFalse($this->storedUser($userId)->is_admin);
    }

    public function testChangeDisplayName(): void
    {
        $a = $this->registered('a@example.com', 'Alpha', 'correct horse battery staple');
        $this->auth->register('b@example.com', 'Bravo', 'correct horse battery staple');

        // Taken name is rejected (case-insensitive).
        $this->assertFalse($this->auth->changeDisplayName($a['user']->user_id, 'bravo')['ok']);
        // Invalid characters rejected.
        $this->assertFalse($this->auth->changeDisplayName($a['user']->user_id, 'no@good')['ok']);
        // A free name succeeds.
        $this->assertTrue($this->auth->changeDisplayName($a['user']->user_id, 'Alphonse')['ok']);
        $this->assertSame('Alphonse', $this->storedUser($a['user']->user_id)->display_name);
    }

    public function testChangePassword(): void
    {
        $reg = $this->registered('pw@example.com', 'Pwuser', 'correct horse battery staple');
        $this->auth->activate($this->tokenFromLink($reg['activation_link']));
        $id = $reg['user']->user_id;

        $this->assertFalse($this->auth->changePassword($id, 'wrong-current', 'a new long passphrase')['ok']);
        $this->assertFalse($this->auth->changePassword($id, 'correct horse battery staple', 'short')['ok']);
        $this->assertTrue($this->auth->changePassword($id, 'correct horse battery staple', 'a new long passphrase')['ok']);

        $this->assertSame('ok', $this->auth->authenticate('pw@example.com', 'a new long passphrase')['status']);
    }

    public function testChangingThePasswordEndsTheOtherSessionsButNotTheOneKept(): void
    {
        $reg = $this->registered('pw-out@example.com', 'PwOut');
        $other = $this->registered('stays@example.com', 'Stays');
        $id = $reg['user']->user_id;
        $this->addSession('this-browser', $id);
        $this->addSession('old-laptop', $id);
        $this->addSession('elsewhere', $other['user']->user_id);

        $this->assertFalse($this->auth->changePassword($id, 'wrong-current', 'a new long passphrase', 'this-browser')['ok']);
        $this->assertSame([$id, $id, $other['user']->user_id], $this->sessionOwners(), 'a refused change ends nothing');

        $this->assertTrue($this->auth->changePassword($id, 'correct horse battery staple', 'a new long passphrase', 'this-browser')['ok']);
        $this->assertSame([$id, $other['user']->user_id], $this->sessionOwners());
        $kept = $this->pdo->prepare('SELECT id FROM sessions WHERE user_id = :id');
        $kept->execute([':id' => $id]);
        $this->assertSame([hash('sha256', 'this-browser')], $kept->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testEndingTheOtherSessionsKeepsThisOneAndCountsTheRest(): void
    {
        $reg = $this->registered('out@example.com', 'Outer');
        $other = $this->registered('else@example.com', 'Elsewhere');
        $id = $reg['user']->user_id;
        $this->addSession('this-browser', $id);
        $this->addSession('phone', $id);
        $this->addSession('laptop', $id);
        $this->addSession('theirs', $other['user']->user_id);

        $this->assertSame(2, $this->auth->endOtherSessions($id, 'this-browser'));
        $this->assertSame([$id, $other['user']->user_id], $this->sessionOwners());
        $this->assertSame(0, $this->auth->endOtherSessions($id, 'this-browser'));
    }

    public function testDeleteAccountRequiresCorrectPassword(): void
    {
        $reg = $this->registered('del@example.com', 'Deleter', 'correct horse battery staple');
        $id = $reg['user']->user_id;

        $this->assertFalse($this->auth->deleteAccount($id, 'nope nope nope')['ok']);
        $this->assertNotNull($this->userRepo->findById($id));

        $this->assertTrue($this->auth->deleteAccount($id, 'correct horse battery staple')['ok']);
        $this->assertNull($this->userRepo->findById($id));
    }

    public function testDeletingAnAccountEndsItsSessions(): void
    {
        $reg = $this->registered('gone@example.com', 'Gone');
        $other = $this->registered('stays@example.com', 'Stays');
        $this->addSession('laptop', $reg['user']->user_id);
        $this->addSession('elsewhere', $other['user']->user_id);

        $this->assertTrue($this->auth->deleteAccount($reg['user']->user_id, 'correct horse battery staple')['ok']);
        $this->assertSame([$other['user']->user_id], $this->sessionOwners());

        $this->auth->removeAccount($other['user']->user_id);
        $this->assertSame([], $this->sessionOwners());
        $this->assertSame([], $this->userRepo->getAll());
    }

    public function testAProtectedAccountCannotDeleteItself(): void
    {
        $reg = $this->registered('owner@example.com', 'Owner');
        $_ENV['PROTECTED_ADMIN_EMAILS'] = 'someone@example.com, OWNER@example.com';

        $this->assertSame(
            ['ok' => false, 'error' => "This account is protected and can't be deleted."],
            $this->auth->deleteAccount($reg['user']->user_id, 'correct horse battery staple')
        );
        $this->assertNotNull($this->userRepo->findById($reg['user']->user_id));
    }
}
