<?php

namespace Tarot\Utility;

/**
 * The Content-Security-Policy og.php sends with the HTML shell of every page.
 *
 * Scripts are 'self', Turnstile, the Cloudflare Web Analytics beacon, and a
 * per-response nonce — never 'unsafe-inline'. The nonce exists for Cloudflare,
 * which injects scripts into our pages: Bot Fight Mode's JavaScript detection is
 * an inline script that differs on every request (no hash could allow it), and
 * Cloudflare stamps the nonce it finds in this header onto what it injects. Our
 * own code needs none: the app is one external module script.
 *
 * /api/docs sends its own, narrower policy (Routes\Internal\ApiDocsController).
 */
final class ContentSecurityPolicy
{
    /** A fresh nonce for one response: 128 random bits, base64. */
    public static function nonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    public static function forShell(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://challenges.cloudflare.com https://static.cloudflareinsights.com",
            // Inline styles are allowed: Vue, Reka UI, FontAwesome and Turnstile set them.
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            "img-src 'self' data:",
            // Turnstile; the analytics beacon reports to our own /cdn-cgi/rum.
            "connect-src 'self' https://challenges.cloudflare.com",
            "frame-src https://challenges.cloudflare.com",
            "manifest-src 'self'",
            "worker-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
        ]);
    }

    /**
     * Enforced unless CSP_MODE=report-only — an escape hatch for the live .env
     * if a Cloudflare change ever trips the policy, flipped without a deploy.
     */
    public static function headerName(?string $mode): string
    {
        return $mode === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
    }
}
