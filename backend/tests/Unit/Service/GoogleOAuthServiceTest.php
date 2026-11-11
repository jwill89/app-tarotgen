<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tarot\Service\GoogleOAuthService;

/**
 * Covers the deterministic, side-effect-free parts of the Google OAuth flow:
 * configuration detection, authorization-URL construction, and reading Google's
 * userinfo answer (with send(), the one method that talks to Google, answered
 * in its place).
 */
#[CoversClass(GoogleOAuthService::class)]
final class GoogleOAuthServiceTest extends TestCase
{
    private GoogleOAuthService $service;

    /** Env keys this service reads, so we can save/restore them around tests. */
    private const array KEYS = ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI'];

    /** @var array<string,?string> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
            putenv($k); // clear process env too (Env::get falls back to getenv)
        }
        $this->service = new GoogleOAuthService();
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $k) {
            if ($this->saved[$k] === null) {
                unset($_ENV[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $this->saved[$k];
                putenv("$k={$this->saved[$k]}");
            }
        }
    }

    private function configure(): void
    {
        $_ENV['GOOGLE_CLIENT_ID']     = 'client-123.apps.googleusercontent.com';
        $_ENV['GOOGLE_CLIENT_SECRET'] = 'secret-xyz';
        $_ENV['GOOGLE_REDIRECT_URI']  = 'https://tarotgen.io/api/auth/google/callback';
    }

    public function testIsNotConfiguredWhenEnvIsMissing(): void
    {
        $this->assertFalse($this->service->isConfigured());
        $this->assertNull($this->service->getClientId());
    }

    public function testIsNotConfiguredWhenOnlySomeKeysArePresent(): void
    {
        $_ENV['GOOGLE_CLIENT_ID'] = 'client-123';
        // secret + redirect still missing
        $this->assertFalse($this->service->isConfigured());
    }

    public function testIsConfiguredWhenAllKeysPresent(): void
    {
        $this->configure();
        $this->assertTrue($this->service->isConfigured());
        $this->assertSame('client-123.apps.googleusercontent.com', $this->service->getClientId());
    }

    public function testBuildAuthUrlContainsTheRequiredParameters(): void
    {
        $this->configure();
        $url = $this->service->buildAuthUrl('state-token-abc');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('client-123.apps.googleusercontent.com', $q['client_id']);
        $this->assertSame('https://tarotgen.io/api/auth/google/callback', $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('openid email profile', $q['scope']);
        $this->assertSame('state-token-abc', $q['state']);
        $this->assertSame('select_account', $q['prompt']);
    }

    /**
     * @return iterable<string,array{mixed,bool}>
     */
    public static function emailVerifiedClaims(): iterable
    {
        yield 'true' => [true, true];
        yield 'the string "true"' => ['true', true];
        yield 'false' => [false, false];
        // A (bool) cast would read this one as verified.
        yield 'the string "false"' => ['false', false];
        yield 'one' => [1, false];
        yield 'null' => [null, false];
    }

    #[DataProvider('emailVerifiedClaims')]
    public function testOnlyATrueEmailVerifiedClaimCountsAsVerified(mixed $claim, bool $verified): void
    {
        $info = $this->answeredBy(['sub' => 'google-sub-1', 'email' => 'reader@example.test', 'email_verified' => $claim])
            ->fetchUserInfo('access-token');

        $this->assertNotNull($info);
        $this->assertSame($verified, $info['email_verified'] ?? null);
    }

    public function testAMissingEmailVerifiedClaimIsUnverified(): void
    {
        $info = $this->answeredBy(['sub' => 'google-sub-1', 'email' => 'reader@example.test'])->fetchUserInfo('access-token');

        $this->assertNotNull($info);
        $this->assertFalse($info['email_verified'] ?? null);
    }

    /**
     * The service with Google's every answer replaced by $body, as JSON.
     *
     * @param array<string,mixed> $body
     */
    private function answeredBy(array $body): GoogleOAuthService
    {
        return new class (json_encode($body, JSON_THROW_ON_ERROR)) extends GoogleOAuthService {
            public function __construct(private readonly string $body) {}

            #[\Override]
            protected function send(string $url, array $options): string
            {
                return $this->body;
            }
        };
    }

    public function testBuildAuthUrlUrlEncodesTheState(): void
    {
        $this->configure();
        $state = 'a b/c+d=e';
        $url = $this->service->buildAuthUrl($state);

        // The raw URL must not contain the unencoded reserved characters...
        $this->assertStringNotContainsString('state=a b/c+d=e', $url);
        // ...but it must decode back to exactly what we passed in.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame($state, $q['state']);
    }
}
