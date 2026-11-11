<?php

namespace Routes\Internal;

use Psr\Http\Message\ServerRequestInterface;
use Tarot\Utility\ClientIp;
use Tarot\Utility\Session;

/**
 * Base for HTTP controllers. Dependencies are injected directly into each
 * concrete controller's constructor, so this base only carries shared
 * request/session helpers.
 */
abstract class AbstractController
{
    /**
     * The request body as a normalized associative array.
     *
     * Slim's `getParsedBody()` is typed `array|object|null` (it can yield a
     * decoded JSON object or `null` for an empty/unparseable body). Controllers
     * only ever read JSON request bodies as `field => value` maps, so this funnels
     * every body through one place that guarantees an `array<string,mixed>` —
     * removing the repeated `?? []` dance and giving the static analyzer a real
     * offset-accessible type at every call site.
     *
     * @return array<string,mixed>
     */
    protected function parsedBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /**
     * The signed-in user's id on a route behind UserAuth or AccountAuth, which
     * resolve it (from the session or a plugin token) and check the account.
     */
    protected function authUserId(ServerRequestInterface $request): int
    {
        $id = $request->getAttribute('auth_user_id');
        return is_int($id) && $id > 0 ? $id : 0;
    }

    /**
     * The real client IP for this request. Resolved through Cloudflare
     * (CF-Connecting-IP, trusted only from Cloudflare edge ranges) so that
     * IP rate-limiting keys on the visitor, not the CDN edge. See {@see ClientIp}.
     */
    protected function clientIp(ServerRequestInterface $request): string
    {
        return ClientIp::resolve($request);
    }

    /**
     * Start a session with "remember me" persistence support.
     */
    protected function startSessionWithPersistence(): void
    {
        Session::start();
    }

    /**
     * Mark the current session for 30-day persistence ("remember me").
     */
    protected function persistSession(): void
    {
        Session::persist();
    }
}
