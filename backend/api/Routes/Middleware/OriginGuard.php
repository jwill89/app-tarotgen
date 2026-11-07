<?php

namespace Routes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use Tarot\Config\Env;

/**
 * CSRF defense-in-depth via Origin/Referer verification.
 *
 * The session cookie is SameSite=Lax (required so the cross-site Google OAuth
 * callback navigation carries it). Lax already blocks the cookie on cross-site
 * POST/PUT/DELETE, so the real CSRF surface is covered — this middleware adds a
 * second, stateless layer: every state-changing request must originate from our
 * own host.
 *
 * A forged cross-site request from a browser always carries an Origin header the
 * attacker cannot spoof or strip, so an Origin that doesn't match us is rejected.
 * Safe methods (GET/HEAD/OPTIONS) are exempt — including the OAuth callback,
 * which is a GET already protected by its `state` token. When neither Origin nor
 * Referer is present (non-browser clients with no ambient cookies, hence not a
 * CSRF vector) the request is allowed so legitimate tooling isn't broken.
 */
final class OriginGuard
{
    /** Methods that don't mutate state and so need no Origin check. */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __invoke(Request $request, Handler $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $sourceHost = $this->sourceHost($request);

        // Only block when we have a source host that isn't us. No header at all
        // → not a browser CSRF vector (SameSite=Lax covers browsers); allow.
        if ($sourceHost !== null && !$this->isAllowedHost($sourceHost, $request)) {
            $response = new Response();
            $response->getBody()->write((string) json_encode(['error' => 'Cross-origin request blocked.']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }

    /** Host from the Origin header, falling back to Referer; null when neither is usable. */
    private function sourceHost(Request $request): ?string
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== 'null') {
            return $this->hostOf($origin);
        }

        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') {
            return $this->hostOf($referer);
        }

        return null;
    }

    private function isAllowedHost(string $host, Request $request): bool
    {
        $allowed = [];

        // The host the browser actually connected to.
        $self = $request->getHeaderLine('Host');
        if ($self !== '') {
            $allowed[] = $this->stripPort(strtolower($self));
        }

        // Plus any explicitly configured app origin (e.g. a separate SPA host),
        // matching what the CORS layer in index.php permits.
        foreach (['APP_URL', 'APP_ORIGIN'] as $key) {
            $configured = Env::get($key);
            if ($configured !== null) {
                $configuredHost = $this->hostOf($configured);
                if ($configuredHost !== null) {
                    $allowed[] = $configuredHost;
                }
            }
        }

        return in_array($host, $allowed, true);
    }

    /** Parse the (lower-cased) host out of a URL or origin string. */
    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** Strip a trailing :port from a Host header value (leaves IPv6 literals alone). */
    private function stripPort(string $host): string
    {
        if (str_contains($host, ']')) {
            return $host; // [::1]:8080 and similar — rare; leave untouched
        }
        $pos = strpos($host, ':');
        return $pos === false ? $host : substr($host, 0, $pos);
    }
}
