<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Routes\Internal\UserController;
use Tarot\Service\AuthService;
use Tarot\Service\Mailer;
use Tarot\Data\UserData;
use Tarot\Utility\AfterResponse;

/**
 * Sign-up's refusals, what a new account starts as, and an email that already
 * has an account (answered like a new one). SignInFlowTest covers a sign-up
 * that goes through and an exact duplicate.
 */
#[CoversClass(UserController::class)]
#[CoversClass(AuthService::class)]
#[CoversClass(Mailer::class)]
#[CoversClass(UserData::class)]
#[CoversClass(AfterResponse::class)]
final class AuthRegistrationTest extends HttpTestCase
{
    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = 'test-only-' . bin2hex(random_bytes(8));
    }

    public function testEveryProblemWithTheFormIsReportedAtOnce(): void
    {
        $refused = $this->assertJsonResponse(422, $this->post('/auth/register', [
            'email' => 'not-an-email', 'display_name' => 'ab', 'password' => 'short',
        ]));

        $this->assertSame(['errors' => [
            'Please enter a valid email address.',
            'Display name must be between 3 and 30 characters.',
            'Password must be at least 12 characters long. A memorable passphrase works well.',
        ]], $refused);
        $this->assertSame(0, $this->rowCount('users'));
    }

    public function testAMissingFieldIsAProblemWithTheFormNotAnError(): void
    {
        $refused = $this->assertJsonResponse(422, $this->post('/auth/register', []));

        $this->assertIsArray($refused['errors']);
        $this->assertCount(3, $refused['errors']);
    }

    public function testADisplayNameIsLettersNumbersSpacesAndDotsDashesUnderscores(): void
    {
        $this->assertSame(
            ['errors' => ['Display name may only contain letters, numbers, spaces, and . _ -']],
            $this->assertJsonResponse(422, $this->register('reader@example.test', 'Reader<script>'))
        );

        // Letters from any alphabet count.
        $this->assertJsonResponse(201, $this->register('zoe@example.test', 'Zoë_the.Reader-2'));
    }

    public function testCaseVariantsOfATakenDisplayNameAreTaken(): void
    {
        $this->assertJsonResponse(201, $this->register('  Reader@Example.TEST ', 'Reader'));
        $this->assertSame([['email' => 'reader@example.test']], $this->rows('SELECT email FROM users'));

        // Names are public, so saying one is taken gives nothing away.
        $taken = ['errors' => ['That display name is already taken.']];
        $this->assertSame($taken, $this->assertJsonResponse(422, $this->register('someone@example.test', 'READER')));
        $this->assertSame($taken, $this->assertJsonResponse(422, $this->register('READER@example.test', 'reader')));
        $this->assertSame(1, $this->rowCount('users'));
    }

    public function testATakenEmailIsAnsweredLikeANewAccountAndItsOwnerIsTold(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $notices = $this->recordSignUpAttemptNotices();
        $this->assertJsonResponse(201, $this->register('reader@example.test', 'Reader'));
        $before = $this->rows('SELECT * FROM users');
        $tokens = $this->rowCount('user_tokens');

        $fresh = $this->assertJsonResponse(201, $this->register('newcomer@example.test', 'Newcomer'));
        $taken = $this->assertJsonResponse(201, $this->register('  READER@example.test', 'Someone Else'));

        $this->assertDocumented('post', '/auth/register', 201, $taken);
        $this->assertSame($fresh, $taken);
        $this->assertSame($before, $this->rows("SELECT * FROM users WHERE email = 'reader@example.test'"));
        $this->assertSame([], $this->rows("SELECT 1 FROM users WHERE display_name = 'Someone Else'"));
        $this->assertSame($tokens + 1, $this->rowCount('user_tokens'), "only the newcomer's activation link");
        $this->assertSame([['reader@example.test', 'Reader', 'https://tarotgen.io/login', 'https://tarotgen.io/forgot-password']], $notices->getArrayCopy());
    }

    public function testOutsideProductionATakenEmailHasNoActivationLinkToShow(): void
    {
        $this->assertJsonResponse(201, $this->register('reader@example.test', 'Reader'));

        $taken = $this->assertJsonResponse(201, $this->register('reader@example.test', 'Someone Else'));

        $this->assertSame([
            'success' => true,
            'message' => 'Account created. Check your email for an activation link to finish signing up.',
        ], $taken);
    }

    public function testSigningUpLeavesTheAccountInactiveAndSignedOut(): void
    {
        $this->assertJsonResponse(201, $this->register('reader@example.test', 'Reader'));

        $this->assertSame([['is_active' => 0, 'is_admin' => 0]], $this->rows('SELECT is_active, is_admin FROM users'));
        $this->assertJsonResponse(401, $this->get('/auth/me'));
    }

    public function testTheActivationLinkPointsAtTheConfiguredSite(): void
    {
        $this->setEnv('APP_URL', 'https://staging.tarotgen.test/');

        $registered = $this->assertJsonResponse(201, $this->register('reader@example.test', 'Reader'));

        $this->assertDocumented('post', '/auth/register', 201, $registered);
        $this->assertIsString($registered['activation_link']);
        $this->assertStringStartsWith('https://staging.tarotgen.test/activate?token=', $registered['activation_link']);
    }

    public function testProductionNeverPutsTheActivationLinkInTheResponse(): void
    {
        $this->setEnv('APP_ENV', 'production');

        $registered = $this->assertJsonResponse(201, $this->register('reader@example.test', 'Reader'));

        $this->assertDocumented('post', '/auth/register', 201, $registered);
        $this->assertSame([
            'success' => true,
            'message' => 'Account created. Check your email for an activation link to finish signing up.',
        ], $registered);
    }

    public function testOutsideProductionTheLinkIsShownOnlyWhenItCouldNotBeEmailed(): void
    {
        $this->replaceService(Mailer::class, new class extends Mailer {
            #[\Override]
            public function sendActivation(string $toEmail, string $toName, string $activationLink): bool
            {
                return true;
            }
        });

        $registered = $this->assertJsonResponse(201, $this->register('reader@example.test', 'Reader'));

        $this->assertArrayNotHasKey('activation_link', $registered);
    }

    public function testUnderPhpFpmBothSignUpEmailsGoOutOnlyAfterTheAnswer(): void
    {
        $this->createUser('taken@example.test');
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
            public function sendActivation(string $toEmail, string $toName, string $activationLink): bool
            {
                $this->steps->append("activation to {$toEmail}");
                return true;
            }

            #[\Override]
            public function sendRegistrationAttempt(string $toEmail, string $toName, string $signInLink, string $resetLink): bool
            {
                $this->steps->append("attempt notice to {$toEmail}");
                return true;
            }
        });
        $after = $this->service(AfterResponse::class);

        // A new address and a taken one: the same answer, neither waiting on SMTP.
        $new = $this->assertJsonResponse(201, $this->register('reader@example.test', 'Reader'));
        $this->assertSame([], $steps->getArrayCopy());
        $after->run();
        $taken = $this->assertJsonResponse(201, $this->register('taken@example.test', 'Someone'));
        $this->assertSame(['answered', 'activation to reader@example.test'], $steps->getArrayCopy());
        $after->run();

        $this->assertSame($new, $taken);
        // Sent, as far as anyone can tell: so not shown outside production either.
        $this->assertArrayNotHasKey('activation_link', $new);
        $this->assertSame(
            ['answered', 'activation to reader@example.test', 'answered', 'attempt notice to taken@example.test'],
            $steps->getArrayCopy()
        );
    }

    public function testOneAddressCanSignUpFiveTimesAnHour(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->assertJsonResponse(201, $this->register("reader{$i}@example.test", "Reader {$i}"));
        }

        $this->assertSame(
            ['error' => 'Too many sign-up attempts. Please try again later.'],
            $this->assertJsonResponse(429, $this->register('reader6@example.test', 'Reader 6'))
        );
        $this->assertSame(5, $this->rowCount('users'));
    }

    public function testRefusedSignUpsCountTowardsTheLimitToo(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertJsonResponse(422, $this->post('/auth/register', ['email' => 'not-an-email']));
        }

        $this->assertJsonResponse(429, $this->register('reader@example.test', 'Reader'));
        $this->assertSame(0, $this->rowCount('users'));
    }

    private function register(string $email, string $displayName): ResponseInterface
    {
        return $this->post('/auth/register', ['email' => $email, 'display_name' => $displayName, 'password' => $this->password]);
    }

    /**
     * Stand in for SMTP's sign-up attempt notices: each one "sent" lands in the
     * list returned, as [to, name, sign-in link, reset link].
     *
     * @return ArrayObject<int,array{string,string,string,string}>
     */
    private function recordSignUpAttemptNotices(): ArrayObject
    {
        /** @var ArrayObject<int,array{string,string,string,string}> $sent */
        $sent = new ArrayObject();
        $this->replaceService(Mailer::class, new class ($sent) extends Mailer {
            /** @param ArrayObject<int,array{string,string,string,string}> $sent */
            public function __construct(private readonly ArrayObject $sent)
            {
            }

            #[\Override]
            public function sendRegistrationAttempt(string $toEmail, string $toName, string $signInLink, string $resetLink): bool
            {
                $this->sent->append([$toEmail, $toName, $signInLink, $resetLink]);
                return true;
            }
        });

        return $sent;
    }
}
