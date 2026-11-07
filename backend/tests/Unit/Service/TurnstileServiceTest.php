<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Service\TurnstileService;

/**
 * Covers the deterministic parts of Turnstile: configuration detection and the
 * verify() decision logic. The live siteverify HTTP call is stubbed via a
 * subclass so no network is involved.
 */
#[CoversClass(TurnstileService::class)]
final class TurnstileServiceTest extends TestCase
{
    /** Env keys this service reads, so we can save/restore them around tests. */
    private const array KEYS = ['CLOUDFLARE_TURNSTILE_SITEKEY', 'CLOUDFLARE_TURNSTILE_SECRET'];

    /** @var array<string,?string> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
            putenv($k); // clear process env too (Env::get falls back to getenv)
        }
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
        $_ENV['CLOUDFLARE_TURNSTILE_SITEKEY'] = '1x00000000000000000000AA';
        $_ENV['CLOUDFLARE_TURNSTILE_SECRET'] = '1x0000000000000000000000000000000AA';
    }

    /**
     * A TurnstileService whose siteverify call returns a canned response.
     *
     * @param array<string, mixed>|null $siteVerifyResult
     */
    private function serviceReturning(?array $siteVerifyResult): TurnstileService
    {
        return new class ($siteVerifyResult) extends TurnstileService {
            /** @param array<string, mixed>|null $result */
            public function __construct(private readonly ?array $result) {}

            protected function postSiteVerify(array $payload): ?array
            {
                return $this->result;
            }
        };
    }

    public function testIsNotConfiguredWhenEnvIsMissing(): void
    {
        $service = new TurnstileService();
        $this->assertFalse($service->isConfigured());
        $this->assertNull($service->getSiteKey());
    }

    public function testIsNotConfiguredWhenOnlySiteKeyIsPresent(): void
    {
        $_ENV['CLOUDFLARE_TURNSTILE_SITEKEY'] = '1x00000000000000000000AA';
        $service = new TurnstileService();
        $this->assertFalse($service->isConfigured());
    }

    public function testIsConfiguredWhenBothKeysPresent(): void
    {
        $this->configure();
        $service = new TurnstileService();
        $this->assertTrue($service->isConfigured());
        $this->assertSame('1x00000000000000000000AA', $service->getSiteKey());
    }

    public function testVerifyFailsOnBlankTokenWithoutCallingCloudflare(): void
    {
        $this->configure();
        // Even a "success" stub must not flip the result for a blank token.
        $service = $this->serviceReturning(['success' => true]);
        $this->assertFalse($service->verify('   '));
    }

    public function testVerifyFailsWhenSecretMissing(): void
    {
        // No secret in env → cannot verify.
        $service = $this->serviceReturning(['success' => true]);
        $this->assertFalse($service->verify('some-token'));
    }

    public function testVerifySucceedsWhenCloudflareConfirms(): void
    {
        $this->configure();
        $service = $this->serviceReturning(['success' => true]);
        $this->assertTrue($service->verify('valid-token', '203.0.113.7'));
    }

    public function testVerifyFailsWhenCloudflareRejects(): void
    {
        $this->configure();
        $service = $this->serviceReturning(['success' => false, 'error-codes' => ['invalid-input-response']]);
        $this->assertFalse($service->verify('bad-token'));
    }

    public function testVerifyFailsOnTransportError(): void
    {
        $this->configure();
        $service = $this->serviceReturning(null);
        $this->assertFalse($service->verify('valid-token'));
    }
}
