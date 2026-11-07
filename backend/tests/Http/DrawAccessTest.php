<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Routes\Internal\ReadingController;
use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\DrawKeyData;
use Tarot\Repository\DrawKeyRepository;
use Tarot\Service\DrawKeyService;
use Tarot\Service\PluginTokenService;
use Tarot\Service\TurnstileService;
use Tarot\Utility\BearerToken;
use Tarot\Utility\Pkce;
use Tarot\Utility\RateLimiter;

/**
 * Who may make a reading (AUDIT §9.14), on both routes that make one — New Draw
 * (POST /readings/generate) and Recreate Draw (POST /readings). With Turnstile
 * configured: a signed-in user or a linked plugin needs nothing more, a
 * signed-out visitor a passed challenge, an unlinked plugin its draw key. With
 * it off, as in development, anyone draws.
 */
#[CoversClass(ReadingController::class)]
#[CoversClass(DrawKeyService::class)]
#[CoversClass(DrawKeyRepository::class)]
#[CoversClass(DrawKeyData::class)]
#[CoversClass(TurnstileService::class)]
#[CoversClass(BearerToken::class)]
final class DrawAccessTest extends HttpTestCase
{
    private const string PASSED = 'passed-the-challenge';

    private const string CAPTCHA_FAILED = 'Captcha verification failed. Please try again.';

    private int $deckId;

    /** @var ArrayObject<int,array<string,string>> What the stand-in Cloudflare was asked to verify. */
    private ArrayObject $verified;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verified = new ArrayObject();
        $systems = $this->service(DeckSystemData::class);
        $system = $systems->store(['name' => 'Little Arcana', 'short_name' => 'LA', 'total_cards' => 5]);
        $this->assertNotNull($system);
        $systems->storeCards($system->deck_system_id, array_map(
            static fn(int $id): array => ['card_id' => $id, 'name' => "Card {$id}"],
            range(1, 5)
        ));
        $deck = $this->service(DeckData::class)->store(['name' => 'Test Deck', 'deck_system_id' => $system->deck_system_id]);
        $this->assertNotNull($deck);
        $this->deckId = $deck->deck_id;
    }

    /**
     * The two routes that make a reading, each with a body that makes one.
     *
     * @return iterable<string,array{string}>
     */
    public static function routes(): iterable
    {
        yield 'New Draw' => ['/readings/generate'];
        yield 'Recreate Draw' => ['/readings'];
    }

    #[DataProvider('routes')]
    public function testWithoutTurnstileAnyoneDrawsWithNothingMore(string $route): void
    {
        $this->assertJsonResponse(201, $this->draw($route));
        // Not even a key is looked at: one nobody issued doesn't matter.
        $this->assertJsonResponse(201, $this->draw($route, [], ['Authorization' => 'Bearer tg_dk_' . str_repeat('0', 64)]));

        $this->assertSame(2, $this->rowCount('readings'));
    }

    #[DataProvider('routes')]
    public function testASignedInReaderNeedsNoChallenge(string $route): void
    {
        $this->configureTurnstile();
        $user = $this->createUser('reader@example.test');
        $this->signInAs($user->user_id);

        $created = $this->assertJsonResponse(201, $this->draw($route));

        $this->assertDocumentedDraw($route, $created);
        $this->assertSame([['user_id' => $user->user_id]], $this->rows('SELECT user_id FROM readings'));
        $this->assertCount(0, $this->verified);
    }

    #[DataProvider('routes')]
    public function testALinkedPluginNeedsNoChallenge(string $route): void
    {
        $this->configureTurnstile();
        $user = $this->createUser('reader@example.test');
        $token = $this->service(PluginTokenService::class)->issueToken($user->user_id);

        $this->assertJsonResponse(201, $this->draw($route, [], ['Authorization' => "Bearer {$token}"]));

        $this->assertSame([['user_id' => $user->user_id]], $this->rows('SELECT user_id FROM readings'));
        $this->assertCount(0, $this->verified);
    }

    #[DataProvider('routes')]
    public function testASignedOutVisitorDrawsWithAPassedChallenge(string $route): void
    {
        $this->configureTurnstile();

        $created = $this->assertJsonResponse(201, $this->draw($route, ['turnstile_token' => self::PASSED]));

        $this->assertDocumentedDraw($route, $created);
        $this->assertSame([['user_id' => null]], $this->rows('SELECT user_id FROM readings'));
        $this->assertSame([self::PASSED], array_column($this->verified->getArrayCopy(), 'response'));
    }

    public function testNewDrawTakesTheChallengeFromAFormPostToo(): void
    {
        $this->configureTurnstile();

        // The site's New Draw form posts form-encoded fields, not JSON.
        $refused = $this->postForm('/readings/generate', ['deck_id' => (string) $this->deckId]);
        $this->assertSame(['error' => self::CAPTCHA_FAILED], $this->assertJsonResponse(400, $refused));

        $this->assertJsonResponse(201, $this->postForm('/readings/generate', ['deck_id' => (string) $this->deckId, 'turnstile_token' => self::PASSED]));
    }

    /**
     * @return iterable<string,array{string,array<string,string>}>
     */
    public static function failedChallenges(): iterable
    {
        foreach (self::routes() as $name => [$route]) {
            yield "{$name}, no challenge" => [$route, []];
            yield "{$name}, an empty one" => [$route, ['turnstile_token' => '']];
            yield "{$name}, one Cloudflare rejects" => [$route, ['turnstile_token' => 'rejected-by-cloudflare']];
        }
    }

    /** @param array<string,string> $challenge */
    #[DataProvider('failedChallenges')]
    public function testASignedOutVisitorWithoutAPassedChallengeIsRefused(string $route, array $challenge): void
    {
        $this->configureTurnstile();

        $response = $this->draw($route, $challenge);

        $this->assertSame(['error' => self::CAPTCHA_FAILED], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    #[DataProvider('routes')]
    public function testAPluginFromBeforeDrawKeysIsToldToUpdate(string $route): void
    {
        $this->configureTurnstile();

        $response = $this->draw($route, [], ['User-Agent' => 'TarotGenPlugin/0.7.0.0 (+https://tarotgen.io)']);

        $this->assertSame(['error' => 'This version of the TarotGen plugin can no longer draw without an account. '
            . 'Update the plugin to get a draw key, or link your account.'], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    #[DataProvider('routes')]
    public function testAnUnlinkedPluginDrawsWithItsKey(string $route): void
    {
        $this->configureTurnstile();
        $key = $this->issueKey();

        $created = $this->assertJsonResponse(201, $this->draw($route, [], ['Authorization' => "Bearer {$key}"]));

        $this->assertDocumentedDraw($route, $created);
        // A key names no account: the reading is a guest's.
        $this->assertSame([['user_id' => null]], $this->rows('SELECT user_id FROM readings'));
        $this->assertCount(0, $this->verified);
        $this->assertSame([['just_now' => 1]], $this->rows(
            "SELECT last_used_at BETWEEN datetime('now', '-10 seconds') AND datetime('now') AS just_now FROM plugin_draw_keys"
        ));
    }

    /**
     * @return iterable<string,array{string,bool}>
     */
    public static function deadKeys(): iterable
    {
        foreach (self::routes() as $name => [$route]) {
            yield "{$name}, a key nobody issued" => [$route, false];
            yield "{$name}, a revoked key" => [$route, true];
        }
    }

    #[DataProvider('deadKeys')]
    public function testAKeyThatIsNotLiveIsRefusedSoThePluginGetsANewOne(string $route, bool $revoked): void
    {
        $this->configureTurnstile();
        $key = $revoked ? $this->issueKey() : 'tg_dk_' . bin2hex(random_bytes(32));
        $this->db->exec("UPDATE plugin_draw_keys SET revoked_at = datetime('now')");

        // A passed challenge alongside doesn't rescue a dead key: the key is what was offered.
        $response = $this->draw($route, ['turnstile_token' => self::PASSED], ['Authorization' => "Bearer {$key}"]);

        $this->assertSame(
            ['error' => 'This draw key is no longer valid. Get a new one from the plugin.'],
            $this->assertJsonResponse(401, $response)
        );
        $this->assertSame(0, $this->rowCount('readings'));
    }

    #[DataProvider('routes')]
    public function testARevokedAccountLinkIsRefusedRatherThanTakenForAGuest(string $route): void
    {
        $this->configureTurnstile();
        $tokens = $this->service(PluginTokenService::class);
        $user = $this->createUser('reader@example.test');
        $token = $tokens->issueToken($user->user_id);
        $tokens->revoke($user->user_id, 1);

        $response = $this->draw($route, [], ['Authorization' => "Bearer {$token}"]);

        $this->assertSame(
            ['error' => 'This account link is no longer valid. Link your account again in the plugin.'],
            $this->assertJsonResponse(401, $response)
        );
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testAKeyOpensNothingButDrawing(): void
    {
        $this->configureTurnstile();
        $bearer = ['Authorization' => 'Bearer ' . $this->issueKey()];

        // Not the account reads a linked plugin may make…
        $this->assertJsonResponse(401, $this->get('/account/readings', $bearer));
        $this->assertJsonResponse(401, $this->get('/account/favorites', $bearer));
        $this->assertJsonResponse(401, $this->get('/account/favorite-decks', $bearer));
        // …nor anything the session alone may do, linking a plugin included.
        $this->assertJsonResponse(401, $this->get('/account/tokens', $bearer));
        $this->assertJsonResponse(401, $this->post('/plugin/authorize', [
            'code_challenge' => Pkce::challengeFor('verifier'), 'redirect_uri' => 'http://127.0.0.1:5000/cb',
        ], $bearer));
        $this->assertJsonResponse(401, $this->get('/admin/auth-check', $bearer));

        // And a reading it draws is a guest's: the key can't finalize it as an owner.
        $created = $this->assertJsonResponse(201, $this->draw('/readings/generate', [], $bearer));
        $this->assertFalse($created['is_owner']);
        $this->assertIsString($id = $created['reading_id']);
        $this->assertJsonResponse(403, $this->post("/readings/{$id}/finalize", [], $bearer));
    }

    public function testAKeyDrawsTenReadingsAMinuteAcrossBothRoutes(): void
    {
        $this->configureTurnstile();
        $bearer = ['Authorization' => 'Bearer ' . $this->issueKey()];
        for ($i = 0; $i < 10; $i++) {
            $this->assertJsonResponse(201, $this->draw($i % 2 === 0 ? '/readings/generate' : '/readings', [], $bearer));
        }

        $this->assertKeyLimited($this->draw('/readings/generate', [], $bearer));
        $this->assertKeyLimited($this->draw('/readings', [], $bearer));
        $this->assertSame(10, $this->rowCount('readings'));

        // Another install's key has its own count.
        $this->assertJsonResponse(201, $this->draw('/readings', [], ['Authorization' => 'Bearer ' . $this->issueKey()]));
    }

    public function testAKeyDrawsThreeHundredReadingsADay(): void
    {
        $this->configureTurnstile();
        $key = $this->issueKey();
        // The day's count, run up as 300 draws spread over the day would have.
        $perDay = new RateLimiter('draw_key_day', 300, 86400);
        for ($i = 0; $i < 300; $i++) {
            $perDay->hit(hash('sha256', $key));
        }

        $this->assertKeyLimited($this->draw('/readings', [], ['Authorization' => "Bearer {$key}"]));
        $this->assertSame(0, $this->rowCount('readings'));
    }

    public function testKeysDrawThirtyReadingsAMinuteFromOneAddress(): void
    {
        $this->configureTurnstile();
        // Recreate Draw has no address limit of its own, so a handful of keys
        // would otherwise multiply the per-key one.
        for ($key = 0; $key < 3; $key++) {
            $bearer = ['Authorization' => 'Bearer ' . $this->issueKey()];
            for ($i = 0; $i < 10; $i++) {
                $this->assertJsonResponse(201, $this->draw('/readings', [], $bearer));
            }
        }

        $this->assertKeyLimited($this->draw('/readings', [], ['Authorization' => 'Bearer ' . $this->issueKey()]));
        $this->assertSame(30, $this->rowCount('readings'));
    }

    /**
     * Make a reading on $route: a free draw of one card, or the hand-picked card a
     * Recreate Draw records.
     *
     * @param array<string,mixed>  $extra
     * @param array<string,string> $headers
     */
    private function draw(string $route, array $extra = [], array $headers = []): ResponseInterface
    {
        $body = $route === '/readings'
            ? ['deck_id' => $this->deckId, 'cards' => [['card_id' => 2, 'title' => 'Here', 'x' => 50, 'y' => 50]]]
            : ['deck_id' => $this->deckId];

        return $this->post($route, $extra + $body, $headers);
    }

    /** @param array<mixed> $created */
    private function assertDocumentedDraw(string $route, array $created): void
    {
        $this->assertDocumented('post', $route, 201, $created);
    }

    private function assertKeyLimited(ResponseInterface $response): void
    {
        $this->assertSame(
            ['error' => 'Too many readings with this draw key. Please try again later.'],
            $this->assertJsonResponse(429, $response)
        );
    }

    /** A live draw key, minted as the key page and the plugin would. */
    private function issueKey(): string
    {
        $keys = $this->service(DrawKeyService::class);
        $verifier = bin2hex(random_bytes(32));
        $key = $keys->exchangeCode($keys->createCode(Pkce::challengeFor($verifier)), $verifier);
        $this->assertIsString($key);
        return $key;
    }

    /**
     * Turnstile switched on, with Cloudflare stood in: it passes only
     * self::PASSED, and records what it was asked.
     */
    private function configureTurnstile(): void
    {
        $this->setEnv('CLOUDFLARE_TURNSTILE_SITEKEY', '1x00000000000000000000AA');
        $this->setEnv('CLOUDFLARE_TURNSTILE_SECRET', '1x0000000000000000000000000000000AA');
        $this->replaceService(TurnstileService::class, new class ($this->verified) extends TurnstileService {
            /** @param ArrayObject<int,array<string,string>> $verified */
            public function __construct(private readonly ArrayObject $verified) {}

            #[\Override]
            protected function postSiteVerify(array $payload): array
            {
                $this->verified[] = $payload;
                return ['success' => $payload['response'] === 'passed-the-challenge'];
            }
        });
    }
}
