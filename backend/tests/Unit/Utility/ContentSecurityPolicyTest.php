<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Utility\ContentSecurityPolicy;

#[CoversClass(ContentSecurityPolicy::class)]
final class ContentSecurityPolicyTest extends TestCase
{
    /** @return array<string,string> directive => its sources */
    private static function directives(string $policy): array
    {
        $out = [];
        foreach (explode(';', $policy) as $part) {
            $words = preg_split('/\s+/', trim($part));
            if ($words === false) {
                continue;
            }
            $name = array_shift($words);
            if ($name !== null && $name !== '') {
                $out[$name] = implode(' ', $words);
            }
        }
        return $out;
    }

    public function testNoncesAreFreshAndValidNonceSourceSyntax(): void
    {
        $a = ContentSecurityPolicy::nonce();
        $b = ContentSecurityPolicy::nonce();

        $this->assertNotSame($a, $b);
        // CSP nonce-source: base64-value, here 16 bytes → 24 chars.
        $this->assertMatchesRegularExpression('#^[A-Za-z0-9+/]{22}==$#', $a);
    }

    public function testScriptsAllowTheNonceButNeverInlineOrEval(): void
    {
        $d = self::directives(ContentSecurityPolicy::forShell('abc123=='));

        $this->assertStringContainsString("'nonce-abc123=='", $d['script-src']);
        $this->assertStringContainsString("'self'", $d['script-src']);
        $this->assertStringNotContainsString('unsafe-inline', $d['script-src']);
        $this->assertStringNotContainsString('unsafe-eval', $d['script-src']);
    }

    public function testStylesAllowTheNonceButNeverInline(): void
    {
        $d = self::directives(ContentSecurityPolicy::forShell('abc123=='));

        $this->assertSame("'self' 'nonce-abc123=='", $d['style-src']);
        $this->assertArrayNotHasKey('style-src-attr', $d);
        $this->assertArrayNotHasKey('style-src-elem', $d);
    }

    public function testTheResponsesNonceReplacesEveryPlaceholderTheBuildLeft(): void
    {
        $placeholder = 'nonce="' . ContentSecurityPolicy::NONCE_PLACEHOLDER . '"';
        $shell = "<style {$placeholder}>a{}</style><meta property=\"csp-nonce\" {$placeholder}>"
            . "<script type=\"module\" src=\"/_app/index.js\" {$placeholder}></script>"
            . '<p>' . ContentSecurityPolicy::NONCE_PLACEHOLDER . '</p>';

        $this->assertSame(
            '<style nonce="abc+/123==">a{}</style><meta property="csp-nonce" nonce="abc+/123==">'
                . '<script type="module" src="/_app/index.js" nonce="abc+/123=="></script>'
                . '<p>' . ContentSecurityPolicy::NONCE_PLACEHOLDER . '</p>',
            ContentSecurityPolicy::stampNonce($shell, 'abc+/123==')
        );
    }

    public function testAllowsExactlyTheThirdPartiesThePagesUse(): void
    {
        $d = self::directives(ContentSecurityPolicy::forShell('n'));

        // Turnstile (login) and the Cloudflare Web Analytics beacon.
        $this->assertStringContainsString('https://challenges.cloudflare.com', $d['script-src']);
        $this->assertStringContainsString('https://static.cloudflareinsights.com', $d['script-src']);
        $this->assertSame('https://challenges.cloudflare.com', $d['frame-src']);
        // Fonts are self-hosted; nothing else may be framed or frame us.
        $this->assertSame("'self'", $d['font-src']);
        $this->assertSame("'none'", $d['frame-ancestors']);
        $this->assertSame("'none'", $d['object-src']);
    }

    public function testEnforcedUnlessExplicitlyReportOnly(): void
    {
        $this->assertSame('Content-Security-Policy', ContentSecurityPolicy::headerName(null));
        $this->assertSame('Content-Security-Policy', ContentSecurityPolicy::headerName('enforce'));
        $this->assertSame('Content-Security-Policy', ContentSecurityPolicy::headerName('typo'));
        $this->assertSame(
            'Content-Security-Policy-Report-Only',
            ContentSecurityPolicy::headerName('report-only')
        );
    }
}
