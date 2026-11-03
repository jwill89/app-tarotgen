<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Routes\Internal\UserController;
use Tarot\Repository\UserRepository;
use Tarot\Service\AuthService;
use Tarot\Service\Mailer;
use Tarot\Service\PasswordPolicy;
use Tarot\Service\PluginTokenService;
use Tarot\Utility\AfterResponse;

/**
 * Forgot password → emailed link → new password: what an outsider can learn from
 * it (nothing, in production), the link's single use, expiry and limit, and the
 * account's sessions it ends.
 */
#[CoversClass(UserController::class)]
#[CoversClass(AuthService::class)]
#[CoversClass(PasswordPolicy::class)]
#[CoversClass(AfterResponse::class)]
final class PasswordResetTest extends HttpTestCase
{
    private const string EMAIL = 'reader@example.test';
    private const array REFUSED = ['error' => 'This reset link is invalid or has expired.'];

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = 'test-only-' . bin2hex(random_bytes(8));
    }

    public function testInProductionTheAnswerIsTheSameWhetherOrNotTheAccountExists(): void
    {
        $this->createUserWithPassword();
        $this->setEnv('APP_ENV', 'production');

        $known = $this->assertJsonResponse(200, $this->forgot(self::EMAIL));
        $unknown = $this->assertJsonResponse(200, $this->forgot('nobody@example.test'));

        $this->assertDocumented('post', '/auth/forgot-password', 200, $known);
        $this->assertSame([
            'success' => true,
            'message' => 'If an account exists for that email, a password-reset link has been sent.',
        ], $known);
        $this->assertSame($known, $unknown);
        $this->assertSame(1, $this->rowCount('user_tokens'));
    }

    public function testOutsideProductionTheLinkIsShownOnlyForAnAccountThatExists(): void
    {
        $this->createUserWithPassword();

        $this->assertArrayNotHasKey('reset_link', $this->assertJsonResponse(200, $this->forgot('nobody@example.test')));
        $shown = $this->assertJsonResponse(200, $this->forgot(self::EMAIL));
        $this->assertDocumented('post', '/auth/forgot-password', 200, $shown);
        $this->assertArrayHasKey('reset_link', $shown);
    }

    public function testOutsideProductionAnEmailedLinkIsNotAlsoShown(): void
    {
        $this->createUserWithPassword();
        $this->replaceService(Mailer::class, new class extends Mailer {
            #[\Override]
            public function sendPasswordReset(string $toEmail, string $toName, string $resetLink): bool
            {
                return true;
            }
        });

        $this->assertArrayNotHasKey('reset_link', $this->assertJsonResponse(200, $this->forgot(self::EMAIL)));
        $this->assertSame(1, $this->rowCount('user_tokens'));
    }

    public function testUnderPhpFpmTheEmailGoesOutOnlyAfterTheAnswer(): void
    {
        $this->createUserWithPassword();
        /** @var ArrayObject<int,string> $steps */
        $steps = new ArrayObject();
        $this->replaceService(AfterResponse::class, new AfterResponse(static function () use ($steps): void {
            $steps->append('answered');
        }));
        $this->replaceService(Mailer::class, new class ($steps) extends Mailer {
            /** @param ArrayObject<int,string> $steps */
            public function __construct(private readonly ArrayObject $steps)
            {
            }

            #[\Override]
            public function isConfigured(): bool
            {
                return true;
            }

            #[\Override]
            public function sendPasswordReset(string $toEmail, string $toName, string $resetLink): bool
            {
                $this->steps->append("emailed {$toEmail}");
                return true;
            }
        });
        $this->setEnv('APP_ENV', 'production');
        $after = $this->service(AfterResponse::class);

        // An unknown address: the same answer, and nothing waiting to be sent.
        $unknown = $this->assertJsonResponse(200, $this->forgot('nobody@example.test'));
        $after->run();
        $this->assertSame([], $steps->getArrayCopy());

        // An account's: answered with nothing sent yet (no SMTP wait to time), then emailed.
        $this->assertSame($unknown, $this->assertJsonResponse(200, $this->forgot(self::EMAIL)));
        $this->assertSame([], $steps->getArrayCopy());
        $after->run();
        $this->assertSame(['answered', 'emailed ' . self::EMAIL], $steps->getArrayCopy());
        $this->assertSame(1, $this->rowCount('user_tokens'));
    }

    public function testAResetLinkSetsANewPasswordOnce(): void
    {
        $this->createUserWithPassword();
        $token = $this->resetToken();
        $newPassword = 'new-' . $this->password;

        $reset = $this->assertJsonResponse(200, $this->reset($token, $newPassword));
        $this->assertDocumented('post', '/auth/reset-password', 200, $reset);
        $this->assertSame(['success' => true, 'message' => 'Your password has been updated. You can now log in.'], $reset);

        $this->assertJsonResponse(401, $this->signIn($this->password));
        $this->assertJsonResponse(200, $this->signIn($newPassword));

        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->reset($token, 'another-' . $this->password)));
        $this->assertJsonResponse(200, $this->signIn($newPassword));
    }

    public function testAResetSignsTheAccountOutEverywhere(): void
    {
        $this->createUserWithPassword();
        $user = $this->service(UserRepository::class)->findByEmail(self::EMAIL);
        $this->assertNotNull($user);
        $plugin = $this->service(PluginTokenService::class)->issueToken($user->user_id);
        $this->signInAs($user->user_id);
        $laptop = $this->sessionCookie();
        $this->useSessionCookie(null);
        $this->signInAs($this->createUser('other@example.test')->user_id);
        $someoneElse = $this->sessionCookie();
        // The link is opened in a browser that is signed in as the account too.
        $this->useSessionCookie(null);
        $this->signInAs($user->user_id);

        $this->assertJsonResponse(200, $this->reset($this->resetToken(), 'new-' . $this->password));

        $this->assertJsonResponse(401, $this->get('/auth/me'));
        $this->useSessionCookie($laptop);
        $this->assertJsonResponse(401, $this->get('/auth/me'));
        $this->useSessionCookie($someoneElse);
        $this->assertJsonResponse(200, $this->get('/auth/me'));
        // A linked plugin is revoked under Connected Apps, not by this.
        $this->useSessionCookie(null);
        $this->assertJsonResponse(200, $this->get('/account/readings', ['Authorization' => "Bearer {$plugin}"]));
    }

    public function testATooWeakPasswordIsRefusedAndTheLinkStillWorks(): void
    {
        $this->createUserWithPassword();
        $token = $this->resetToken();

        $this->assertSame(
            ['error' => 'Password must be at least 12 characters long. A memorable passphrase works well.'],
            $this->assertJsonResponse(422, $this->reset($token, 'short'))
        );
        $this->assertSame(
            ['error' => 'That password is too common. Please choose a less predictable password.'],
            $this->assertJsonResponse(422, $this->reset($token, 'Password1234'))
        );
        $this->assertSame(
            ['error' => 'Password cannot be only whitespace.'],
            $this->assertJsonResponse(422, $this->reset($token, str_repeat(' ', 16)))
        );

        $this->assertJsonResponse(200, $this->reset($token, 'new-' . $this->password));
    }

    public function testAMadeUpTokenIsRefusedBeforeThePasswordIsJudged(): void
    {
        $this->createUserWithPassword();

        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->post('/auth/reset-password')));
        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->reset(str_repeat('ab', 32), 'short')));
        $this->assertJsonResponse(200, $this->signIn($this->password));
    }

    public function testAnExpiredLinkLeavesThePasswordAsItWas(): void
    {
        $this->createUserWithPassword();
        $token = $this->resetToken();
        $this->db->exec("UPDATE user_tokens SET expires_at = '2000-01-01 00:00:00'");

        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->reset($token, 'new-' . $this->password)));
        $this->assertJsonResponse(200, $this->signIn($this->password));
    }

    public function testAskingAgainRevokesTheEarlierLink(): void
    {
        $this->createUserWithPassword();
        $first = $this->resetToken();
        $second = $this->resetToken();

        $this->assertSame(self::REFUSED, $this->assertJsonResponse(400, $this->reset($first, 'new-' . $this->password)));
        $this->assertJsonResponse(200, $this->reset($second, 'new-' . $this->password));
    }

    public function testResettingAlsoActivatesAnAccountThatNeverWas(): void
    {
        // The link arrived by email, which proves the inbox as well as activation would.
        $this->createUserWithPassword(active: false);
        $newPassword = 'new-' . $this->password;

        $this->assertJsonResponse(200, $this->reset($this->resetToken(), $newPassword));

        $this->assertJsonResponse(200, $this->signIn($newPassword));
    }

    public function testOneAddressCanAskFiveTimesAnHour(): void
    {
        $this->createUserWithPassword();
        for ($i = 0; $i < 5; $i++) {
            $this->assertJsonResponse(200, $this->forgot("nobody{$i}@example.test"));
        }

        $this->assertSame(
            ['error' => 'Too many requests. Please try again later.'],
            $this->assertJsonResponse(429, $this->forgot(self::EMAIL))
        );
        $this->assertSame(0, $this->rowCount('user_tokens'));
    }

    private function createUserWithPassword(bool $active = true): void
    {
        $user = $this->createUser(self::EMAIL, active: $active);
        $this->service(UserRepository::class)->updatePasswordHash($user->user_id, password_hash($this->password, AuthService::passwordAlgo()));
    }

    private function forgot(string $email): ResponseInterface
    {
        return $this->post('/auth/forgot-password', ['email' => $email]);
    }

    /** Ask for a reset link for self::EMAIL; returns its token (shown outside production). */
    private function resetToken(): string
    {
        $link = $this->assertJsonResponse(200, $this->forgot(self::EMAIL))['reset_link'] ?? null;
        $this->assertIsString($link);
        parse_str((string)parse_url($link, PHP_URL_QUERY), $query);
        $this->assertIsString($query['token'] ?? null);
        return $query['token'];
    }

    private function reset(string $token, string $password): ResponseInterface
    {
        return $this->post('/auth/reset-password', ['token' => $token, 'password' => $password]);
    }

    private function signIn(string $password): ResponseInterface
    {
        return $this->post('/auth/login', ['email' => self::EMAIL, 'password' => $password]);
    }
}
