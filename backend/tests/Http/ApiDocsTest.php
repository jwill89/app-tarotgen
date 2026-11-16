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

    public function testTheReferenceLoadsSwaggerUiFromThisOrigin(): void
    {
        $response = $this->get('/docs');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $page = (string) $response->getBody();
        $this->assertStringContainsString('<link rel="stylesheet" href="/swagger-ui/swagger-ui.css" />', $page);
        $this->assertStringContainsString('<script src="/swagger-ui/swagger-ui-bundle.js"></script>', $page);
        $this->assertStringContainsString('<script src="/swagger-ui/api-docs.js"></script>', $page);
        // Nothing inline: no <style>, no style="" and no script body.
        $this->assertStringNotContainsString('<style', $page);
        $this->assertStringNotContainsString('style=', $page);
        $this->assertDoesNotMatchRegularExpression('#<script[^>]*>[^<]#', $page);
        $this->assertStringNotContainsString('https://', $page);
    }

    public function testTheReferenceRunsUnderItsOwnStrictPolicy(): void
    {
        $first = $this->get('/docs');
        $second = $this->get('/docs');

        $policy = $first->getHeaderLine('Content-Security-Policy');
        $this->assertMatchesRegularExpression(
            "#^default-src 'none'; script-src 'self' 'nonce-[A-Za-z0-9+/]{22}==' https://static\\.cloudflareinsights\\.com; "
                . "style-src 'self'; img-src 'self' data:; connect-src 'self'; "
                . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'$#",
            $policy
        );
        $this->assertStringNotContainsString('unsafe-', $policy);

        // A fresh nonce each time, for the scripts Cloudflare injects.
        $this->assertNotSame($policy, $second->getHeaderLine('Content-Security-Policy'));
    }
}
