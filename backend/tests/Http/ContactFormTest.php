<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use ArrayObject;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\ContactController;
use Tarot\Data\ContactData;
use Tarot\Repository\ContactRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\TurnstileService;

/**
 * POST /contacts, the public contact form: what it stores, what it refuses, its
 * per-visitor limit, and the Turnstile challenge it needs when Turnstile is
 * configured (as sign-in does; the other tests run without it).
 */
#[CoversClass(ContactController::class)]
#[CoversClass(ContactRepository::class)]
#[CoversClass(ContactData::class)]
#[CoversClass(TurnstileService::class)]
final class ContactFormTest extends HttpTestCase
{
    private const array MESSAGE = ['name' => 'Morgan', 'email' => 'morgan@example.test', 'message' => 'Hello'];

    private const string CAPTCHA_FAILED = 'Captcha verification failed. Please try again.';

    /** @var ArrayObject<int,array<string,string>> What was sent to Cloudflare's siteverify. */
    private ArrayObject $verified;

    /** Cloudflare's answer to every token it's sent. */
    private bool $cloudflarePasses = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verified = new ArrayObject();
    }

    public function testAGuestsMessageIsStoredAsTyped(): void
    {
        $response = $this->post('/contacts', ['name' => ' Morgan ', 'email' => ' morgan@example.test ', 'message' => " Hello there \n"]);

        $body = $this->assertJsonResponse(201, $response);
        $this->assertDocumented('post', '/contacts', 201, $body);
        $this->assertSame(['success' => true], $body);
        $this->assertSame(
            [['user_id' => null, 'name' => 'Morgan', 'email' => 'morgan@example.test', 'message' => 'Hello there', 'is_read' => 0]],
            $this->rows('SELECT user_id, name, email, message, is_read FROM contacts')
        );
    }

    public function testASignedInSendersMessageIsLinkedToTheirAccount(): void
    {
        $sender = $this->createUser('morgan@example.test');
        $this->signInAs($sender->user_id);

        $this->assertJsonResponse(201, $this->post('/contacts', ['name' => 'Morgan', 'email' => 'other@example.test', 'message' => 'Hello']));

        $this->assertSame([['user_id' => $sender->user_id]], $this->rows('SELECT user_id FROM contacts'));
    }

    public function testADeletedAccountsLeftoverSessionSendsAsAGuest(): void
    {
        $gone = $this->createUser('gone@example.test');
        $this->signInAs($gone->user_id);
        $this->service(UserRepository::class)->delete($gone->user_id);

        // The session's user is gone: the message is kept with no user, not lost to a 500 from the foreign key.
        $this->assertJsonResponse(201, $this->post('/contacts', ['name' => 'Morgan', 'email' => 'morgan@example.test', 'message' => 'Hello']));

        $this->assertSame([['user_id' => null, 'message' => 'Hello']], $this->rows('SELECT user_id, message FROM contacts'));
    }

    public function testEveryFieldIsRequired(): void
    {
        $required = ['error' => 'Name, email, and message are all required.'];
        $valid = ['name' => 'Morgan', 'email' => 'morgan@example.test', 'message' => 'Hello'];

        foreach (['name', 'email', 'message'] as $field) {
            $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/contacts', [$field => '  '] + $valid)), $field);
        }
        $this->assertSame(0, $this->rowCount('contacts'));
    }

    public function testTheEmailMustBeAnAddress(): void
    {
        $this->assertSame(
            ['error' => 'Please provide a valid email address.'],
            $this->assertJsonResponse(400, $this->post('/contacts', ['name' => 'Morgan', 'email' => 'morgan at example', 'message' => 'Hello']))
        );
        $this->assertSame(0, $this->rowCount('contacts'));
    }

    public function testWithTurnstileConfiguredAMessageNeedsAPassedChallenge(): void
    {
        $this->configureTurnstile(passes: false);

        $this->assertSame(['error' => self::CAPTCHA_FAILED], $this->assertJsonResponse(400, $this->post('/contacts', self::MESSAGE)));
        $this->assertSame(
            ['error' => self::CAPTCHA_FAILED],
            $this->assertJsonResponse(400, $this->post('/contacts', ['turnstile_token' => 'rejected-by-cloudflare'] + self::MESSAGE))
        );

        // A blank token never reaches Cloudflare; the one sent is what it checked.
        $this->assertSame(['rejected-by-cloudflare'], array_column($this->verified->getArrayCopy(), 'response'));
        $this->assertSame(0, $this->rowCount('contacts'));
    }

    public function testWithTurnstileConfiguredAPassedChallengeSendsTheMessage(): void
    {
        $this->configureTurnstile(passes: true);

        // Even when Cloudflare would pass anything, no token is no message.
        $this->assertSame(['error' => self::CAPTCHA_FAILED], $this->assertJsonResponse(400, $this->post('/contacts', self::MESSAGE)));

        $this->assertJsonResponse(201, $this->post('/contacts', ['turnstile_token' => 'passed-the-challenge'] + self::MESSAGE));

        $this->assertSame(['passed-the-challenge'], array_column($this->verified->getArrayCopy(), 'response'));
        $this->assertSame([['message' => 'Hello']], $this->rows('SELECT message FROM contacts'));
    }

    public function testAMalformedMessageIsRefusedBeforeTheChallengeIsChecked(): void
    {
        $this->configureTurnstile(passes: true);

        $this->assertSame(
            ['error' => 'Please provide a valid email address.'],
            $this->assertJsonResponse(400, $this->post('/contacts', ['email' => 'bad', 'turnstile_token' => 'passed-the-challenge'] + self::MESSAGE))
        );

        $this->assertCount(0, $this->verified);
    }

    public function testFailedChallengesDoNotUseUpTheAllowance(): void
    {
        $this->configureTurnstile(passes: false);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertJsonResponse(400, $this->post('/contacts', ['turnstile_token' => 'rejected-by-cloudflare'] + self::MESSAGE));
        }

        $this->cloudflarePasses = true;
        for ($i = 1; $i <= 5; $i++) {
            $this->assertJsonResponse(201, $this->post('/contacts', ['turnstile_token' => 'passed-the-challenge'] + self::MESSAGE));
        }

        $this->assertJsonResponse(429, $this->post('/contacts', ['turnstile_token' => 'passed-the-challenge'] + self::MESSAGE));
        $this->assertSame(5, $this->rowCount('contacts'));
    }

    public function testAVisitorCanSendFiveMessagesAnHour(): void
    {
        // Refused messages don't use up the allowance.
        $this->assertJsonResponse(400, $this->post('/contacts', ['name' => 'Morgan', 'email' => 'bad', 'message' => 'Hello']));
        for ($i = 1; $i <= 5; $i++) {
            $this->assertJsonResponse(201, $this->post('/contacts', ['name' => 'Morgan', 'email' => 'morgan@example.test', 'message' => "Message {$i}"]));
        }

        $this->assertSame(
            ['error' => 'You have submitted too many messages recently. Please try again later.'],
            $this->assertJsonResponse(429, $this->post('/contacts', ['name' => 'Morgan', 'email' => 'morgan@example.test', 'message' => 'Message 6']))
        );
        $this->assertSame(5, $this->rowCount('contacts'));
    }

    /**
     * Turnstile switched on, with Cloudflare stood in: it answers as
     * $cloudflarePasses says (changeable later, as the controller keeps the
     * service it was built with) and records what it was sent.
     */
    private function configureTurnstile(bool $passes): void
    {
        $this->cloudflarePasses = $passes;
        $this->setEnv('CLOUDFLARE_TURNSTILE_SITEKEY', '1x00000000000000000000AA');
        $this->setEnv('CLOUDFLARE_TURNSTILE_SECRET', '1x0000000000000000000000000000000AA');
        $this->replaceService(TurnstileService::class, new class (fn(): bool => $this->cloudflarePasses, $this->verified) extends TurnstileService {
            /**
             * @param Closure(): bool                         $passes
             * @param ArrayObject<int,array<string,string>> $verified
             */
            public function __construct(private readonly Closure $passes, private readonly ArrayObject $verified) {}

            #[\Override]
            protected function postSiteVerify(array $payload): array
            {
                $this->verified[] = $payload;
                return ['success' => ($this->passes)()];
            }
        });
    }
}
