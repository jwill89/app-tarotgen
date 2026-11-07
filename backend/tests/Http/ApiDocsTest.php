<?php

declare(strict_types=1);

namespace Tarot\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Routes\Internal\ApiDocsController;

#[CoversClass(ApiDocsController::class)]
final class ApiDocsTest extends HttpTestCase
{
    public function testTheSpecIsServed(): void
    {
        $spec = $this->assertJsonResponse(200, $this->get('/openapi.json'));

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/card-reports', $spec['paths'] ?? []);
    }

    public function testTheReferenceLoadsOnlyThePinnedBundleUnderItsOwnPolicy(): void
    {
        $first = $this->get('/docs');
        $second = $this->get('/docs');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertStringContainsString(
            'src="' . ApiDocsController::SCALAR_BUNDLE . '" integrity="' . ApiDocsController::SCALAR_SRI . '"',
            (string) $first->getBody()
        );

        $policy = $first->getHeaderLine('Content-Security-Policy');
        $this->assertMatchesRegularExpression(
            '/; script-src ' . preg_quote(ApiDocsController::SCALAR_BUNDLE, '/')
                . " 'nonce-[A-Za-z0-9+\/]{22}==' https:\/\/static\.cloudflareinsights\.com;/",
            $policy
        );

        // A fresh nonce each time, for the scripts Cloudflare injects.
        $this->assertNotSame($policy, $second->getHeaderLine('Content-Security-Policy'));
    }
}
