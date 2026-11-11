<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Routes\Internal\DrawKeyController;
use Tarot\Data\DrawKeyData;
use Tarot\Repository\DrawKeyRepository;
use Tarot\Service\DrawKeyService;
use Tarot\Service\TurnstileService;
use Tarot\Utility\LoopbackUri;
use Tarot\Utility\Pkce;

/**
 * Getting a draw key for an unlinked plugin install, PKCE as the account link
 * does it: the key page, its Turnstile challenge passed, gets a
 * one-time code for the plugin's loopback listener; the plugin swaps code and
 * verifier for the key.
 */
#[CoversClass(DrawKeyController::class)]
#[CoversClass(DrawKeyService::class)]
#[CoversClass(DrawKeyRepository::class)]
#[CoversClass(DrawKeyData::class)]
#[CoversClass(LoopbackUri::class)]
#[CoversClass(Pkce::class)]
final class DrawKeyFlowTest extends HttpTestCase
{
    private const string REDIRECT = 'http://127.0.0.1:51234/callback';

    private const string PASSED = 'passed-the-challenge';

    private const string INVALID_CODE = 'Invalid or expired authorization code.';

    private string $verifier;

    private string $challenge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = bin2hex(random_bytes(32));
        $this->challenge = Pkce::challengeFor($this->verifier);
    }

    public function testPassingTheChallengeAndExchangingTheCodeGivesThePluginItsKey(): void
    {
        $this->configureTurnstile();

        $approved = $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/authorize', [
            'code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT, 'state' => 'xyz', 'turnstile_token' => self::PASSED,
        ]));
        $this->assertDocumented('post', '/plugin/draw-keys/authorize', 200, $approved);
        $this->assertIsString($approved['redirect_uri']);
        $this->assertStringStartsWith(self::REDIRECT . '?code=', $approved['redirect_uri']);
        parse_str((string) parse_url($approved['redirect_uri'], PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);
        $this->assertIsString($code = $query['code']);

        $issued = $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/token', ['code' => $code, 'code_verifier' => $this->verifier]));

        $this->assertDocumented('post', '/plugin/draw-keys/token', 200, $issued);
        $this->assertIsString($key = $issued['key']);
        // tg_dk_ and 256 random bits, in hex.
        $this->assertMatchesRegularExpression('/^tg_dk_[0-9a-f]{64}$/', $key);
        // Only hashes are kept: a database leak gives away no working code or key.
        $this->assertSame([[
            'code_hash' => hash('sha256', $code), 'key_hash' => hash('sha256', $key), 'issued' => 1, 'last_used_at' => null,
        ]], $this->rows('SELECT code_hash, key_hash, issued_at IS NOT NULL AS issued, last_used_at FROM plugin_draw_keys'));
    }

    public function testNoSessionIsNeededJustAPassedChallenge(): void
    {
        $this->configureTurnstile();
        $this->signInAs($this->createUser('reader@example.test')->user_id);

        // Signed in or not, the challenge is what the page asks for.
        $response = $this->post('/plugin/draw-keys/authorize', ['code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT]);

        $this->assertSame(['error' => 'Captcha verification failed. Please try again.'], $this->assertJsonResponse(400, $response));
    }

    /**
     * @return iterable<string,array{array<string,string>}>
     */
    public static function failedChallenges(): iterable
    {
        yield 'no challenge' => [[]];
        yield 'an empty one' => [['turnstile_token' => '']];
        yield 'one Cloudflare rejects' => [['turnstile_token' => 'rejected-by-cloudflare']];
    }

    /** @param array<string,string> $challenge */
    #[DataProvider('failedChallenges')]
    public function testWithoutAPassedChallengeThereIsNoCode(array $challenge): void
    {
        $this->configureTurnstile();

        $response = $this->post('/plugin/draw-keys/authorize', ['code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT] + $challenge);

        $this->assertSame(['error' => 'Captcha verification failed. Please try again.'], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('plugin_draw_keys'));
    }

    public function testWithoutTurnstileTheKeyPageAsksForNoChallenge(): void
    {
        $code = $this->authorize();

        $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/token', ['code' => $code, 'code_verifier' => $this->verifier]));
    }

    public function testTheCodeNeedsAnS256Challenge(): void
    {
        $required = ['error' => 'A valid S256 code_challenge is required.'];

        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/plugin/draw-keys/authorize', ['redirect_uri' => self::REDIRECT])));
        $this->assertSame($required, $this->assertJsonResponse(400, $this->post('/plugin/draw-keys/authorize', [
            'code_challenge' => $this->verifier, 'code_challenge_method' => 'plain', 'redirect_uri' => self::REDIRECT,
        ])));
        $this->assertSame(0, $this->rowCount('plugin_draw_keys'));

        $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/authorize', [
            'code_challenge' => $this->challenge, 'code_challenge_method' => 's256', 'redirect_uri' => self::REDIRECT,
        ]));
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function foreignRedirects(): iterable
    {
        yield 'another host' => ['http://evil.example/callback'];
        yield 'https on loopback' => ['https://127.0.0.1:51234/callback'];
        yield 'a loopback-looking subdomain' => ['http://127.0.0.1.evil.example/callback'];
        yield 'loopback as the userinfo' => ['http://127.0.0.1@evil.example/callback'];
        yield 'a backslash before the userinfo' => ['http://evil.example\@127.0.0.1/callback'];
        yield 'nothing' => [''];
    }

    #[DataProvider('foreignRedirects')]
    public function testTheCodeIsOnlyEverSentToALoopbackAddress(string $redirect): void
    {
        $this->configureTurnstile();

        $response = $this->post('/plugin/draw-keys/authorize', [
            'code_challenge' => $this->challenge, 'redirect_uri' => $redirect, 'turnstile_token' => self::PASSED,
        ]);

        $this->assertSame(['error' => 'redirect_uri must be a loopback address.'], $this->assertJsonResponse(400, $response));
        $this->assertSame(0, $this->rowCount('plugin_draw_keys'));
    }

    public function testALoopbackRedirectKeepsItsOwnQuery(): void
    {
        $approved = $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/authorize', [
            'code_challenge' => $this->challenge, 'redirect_uri' => 'http://[::1]:51234/callback?install=2',
        ]));

        $this->assertIsString($approved['redirect_uri']);
        $this->assertStringStartsWith('http://[::1]:51234/callback?install=2&code=', $approved['redirect_uri']);
    }

    public function testACodeWithTheWrongVerifierIsRefusedButNotSpent(): void
    {
        $code = $this->authorize();

        $wrong = $this->post('/plugin/draw-keys/token', ['code' => $code, 'code_verifier' => strrev($this->verifier)]);
        $this->assertSame(['error' => self::INVALID_CODE], $this->assertJsonResponse(400, $wrong));
        $this->assertSame([['key_hash' => null]], $this->rows('SELECT key_hash FROM plugin_draw_keys'));

        // The real plugin, with the right verifier, can still finish.
        $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/token', ['code' => $code, 'code_verifier' => $this->verifier]));
    }

    public function testACodeWorksOnlyOnce(): void
    {
        $code = $this->authorize();
        $first = $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/token', ['code' => $code, 'code_verifier' => $this->verifier]));

        $replayed = $this->post('/plugin/draw-keys/token', ['code' => $code, 'code_verifier' => $this->verifier]);

        $this->assertSame(['error' => self::INVALID_CODE], $this->assertJsonResponse(400, $replayed));
        $this->assertIsString($first['key']);
        $this->assertSame([['key_hash' => hash('sha256', $first['key'])]], $this->rows('SELECT key_hash FROM plugin_draw_keys'));
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $code = $this->authorize();
        $this->db->exec("UPDATE plugin_draw_keys SET code_expires_at = datetime('now', '-1 second')");

        $response = $this->post('/plugin/draw-keys/token', ['code' => $code, 'code_verifier' => $this->verifier]);

        $this->assertSame(['error' => self::INVALID_CODE], $this->assertJsonResponse(400, $response));
        $this->assertSame([['key_hash' => null]], $this->rows('SELECT key_hash FROM plugin_draw_keys'));
    }

    public function testAFreshCodeExpiresAfterFiveMinutes(): void
    {
        $this->authorize();

        $ttl = $this->rows("SELECT CAST(ROUND((julianday(code_expires_at) - julianday('now')) * 86400) AS INTEGER) AS ttl FROM plugin_draw_keys");

        $this->assertEqualsWithDelta(300, $ttl[0]['ttl'], 2);
    }

    public function testAMissingOrMadeUpCodeIsRefused(): void
    {
        $invalid = ['error' => self::INVALID_CODE];

        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/plugin/draw-keys/token', [])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/plugin/draw-keys/token', ['code' => $this->authorize()])));
        $this->assertSame($invalid, $this->assertJsonResponse(400, $this->post('/plugin/draw-keys/token', [
            'code' => bin2hex(random_bytes(32)), 'code_verifier' => $this->verifier,
        ])));
        // An account link's code is no draw key's.
        $this->assertSame(0, $this->rowCount('plugin_tokens'));
    }

    public function testTheKeyPageAllowsTenAttemptsAnHour(): void
    {
        $this->configureTurnstile();
        // Failed challenges count as much as passed ones.
        for ($i = 0; $i < 9; $i++) {
            $this->assertJsonResponse(400, $this->post('/plugin/draw-keys/authorize', ['code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT]));
        }
        $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/authorize', [
            'code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT, 'turnstile_token' => self::PASSED,
        ]));

        $refused = $this->post('/plugin/draw-keys/authorize', [
            'code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT, 'turnstile_token' => self::PASSED,
        ]);

        $this->assertSame(['error' => 'Too many attempts. Please try again later.'], $this->assertJsonResponse(429, $refused));
        $this->assertSame(1, $this->rowCount('plugin_draw_keys'));
    }

    public function testExchangingCodesIsLimitedToTenAttemptsAMinute(): void
    {
        $codes = [];
        for ($i = 0; $i < 2; $i++) {
            $codes[] = $this->authorize();
        }
        for ($i = 0; $i < 9; $i++) {
            $this->assertJsonResponse(400, $this->post('/plugin/draw-keys/token', ['code' => bin2hex(random_bytes(32)), 'code_verifier' => $this->verifier]));
        }
        $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/token', ['code' => $codes[0], 'code_verifier' => $this->verifier]));

        $refused = $this->post('/plugin/draw-keys/token', ['code' => $codes[1], 'code_verifier' => $this->verifier]);

        $this->assertSame(['error' => 'Too many attempts. Please try again later.'], $this->assertJsonResponse(429, $refused));
        $this->assertSame([['issued' => 1]], $this->rows('SELECT COUNT(*) AS issued FROM plugin_draw_keys WHERE key_hash IS NOT NULL'));
    }

    /** The key page's step, Turnstile off; returns the code. */
    private function authorize(): string
    {
        $approved = $this->assertJsonResponse(200, $this->post('/plugin/draw-keys/authorize', [
            'code_challenge' => $this->challenge, 'redirect_uri' => self::REDIRECT,
        ]));
        $this->assertIsString($approved['redirect_uri']);
        parse_str((string) parse_url($approved['redirect_uri'], PHP_URL_QUERY), $query);
        $this->assertIsString($query['code']);
        return $query['code'];
    }

    /** Turnstile switched on, with Cloudflare stood in: it passes only self::PASSED. */
    private function configureTurnstile(): void
    {
        $this->setEnv('CLOUDFLARE_TURNSTILE_SITEKEY', '1x00000000000000000000AA');
        $this->setEnv('CLOUDFLARE_TURNSTILE_SECRET', '1x0000000000000000000000000000000AA');
        $this->replaceService(TurnstileService::class, new class extends TurnstileService {
            #[\Override]
            protected function postSiteVerify(array $payload): array
            {
                return ['success' => $payload['response'] === 'passed-the-challenge'];
            }
        });
    }
}
