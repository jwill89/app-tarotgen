<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\ConfigController;

/**
 * GET /config, which the SPA reads before rendering: only values any visitor
 * may see.
 */
#[CoversClass(ConfigController::class)]
final class ConfigTest extends HttpTestCase
{
    public function testWithoutTurnstileThereIsNoSiteKey(): void
    {
        $config = $this->assertJsonResponse(200, $this->get('/config'));

        $this->assertDocumented('get', '/config', 200, $config);
        $this->assertSame(['turnstile_sitekey' => null], $config);
    }

    public function testTheSiteKeyIsSharedButNeverTheSecret(): void
    {
        $this->setEnv('CLOUDFLARE_TURNSTILE_SITEKEY', '0x4AAAAsite');
        $this->setEnv('CLOUDFLARE_TURNSTILE_SECRET', '0x4AAAAsecret');

        $response = $this->get('/config');

        $this->assertSame(['turnstile_sitekey' => '0x4AAAAsite'], $this->assertJsonResponse(200, $response));
        $this->assertStringNotContainsString('0x4AAAAsecret', (string) $response->getBody());
    }
}
