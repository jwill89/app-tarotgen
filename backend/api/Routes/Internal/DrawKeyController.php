<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Service\DrawKeyService;
use Tarot\Service\TurnstileService;
use Tarot\Utility\LoopbackUri;
use Tarot\Utility\Pkce;
use Tarot\Utility\RateLimiter;
use OpenApi\Spec as OAS;

/**
 * Minting a draw key for an unlinked plugin install: the browser
 * passes the key page's Turnstile challenge and gets a one-time code for the
 * plugin's loopback listener ({@see authorize}); the plugin swaps code and PKCE
 * verifier for the key ({@see token}). The account link's flow, with Turnstile
 * where it has a signed-in user, so a bot can't mint keys without solving it.
 */
class DrawKeyController extends AbstractController
{
    // Key pages: 10 per IP per hour, every attempt counted. A person needs one
    // key per install; the challenge is the real guard, this caps the retries.
    private const int AUTHORIZE_MAX_PER_WINDOW = 10;
    private const int AUTHORIZE_WINDOW_SECONDS = 3600;

    // Code exchanges: 10 per IP per minute, every attempt counted, as the account
    // link's are. Getting a key makes one; a run of them is guessing.
    private const int TOKEN_MAX_PER_WINDOW = 10;
    private const int TOKEN_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly DrawKeyService $drawKeys,
        private readonly TurnstileService $turnstile,
    ) {}

    #[OAS\Operation\Post(
        path: '/plugin/draw-keys/authorize',
        summary: 'Mint a one-time code for a plugin draw key, once the key page\'s challenge is passed. Rate-limited: 10 attempts/IP/hour.',
        tags: ['Plugin'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['code_challenge', 'redirect_uri'],
                properties: [
                    new OAS\Property(property: 'code_challenge', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'code_challenge_method', schema: new OAS\Schema(type: 'string', default: 'S256')),
                    new OAS\Property(property: 'redirect_uri', schema: new OAS\Schema(type: 'string', description: 'Loopback URI, e.g. http://127.0.0.1:<port>/callback')),
                    new OAS\Property(property: 'state', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'turnstile_token', schema: new OAS\Schema(type: 'string', description: 'Required when Turnstile is configured')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Loopback redirect target carrying the code (valid 5 minutes, once)',
                content: new OAS\MediaType\Json(required: ['redirect_uri'], properties: [new OAS\Property(property: 'redirect_uri', schema: new OAS\Schema(type: 'string'))])
            ),
            new OAS\Response(response: 400, description: 'Invalid challenge, non-loopback redirect_uri, or captcha failed'),
            new OAS\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function authorize(Request $request, Response $response): Response|ResponseInterface
    {
        $limiter = new RateLimiter('draw_key_authorize', self::AUTHORIZE_MAX_PER_WINDOW, self::AUTHORIZE_WINDOW_SECONDS);
        $ip      = $this->clientIp($request);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(['error' => 'Too many attempts. Please try again later.'], 429);
        }
        $limiter->hit($ip);

        $body        = $this->parsedBody($request);
        $challenge   = trim((string) ($body['code_challenge'] ?? ''));
        $method      = strtoupper(trim((string) ($body['code_challenge_method'] ?? Pkce::METHOD)));
        $redirectUri = trim((string) ($body['redirect_uri'] ?? ''));
        $state       = (string) ($body['state'] ?? '');

        if ($challenge === '' || $method !== Pkce::METHOD) {
            return $response->withJson(['error' => 'A valid S256 code_challenge is required.'], 400);
        }

        // The code goes only to the listener the plugin opened on this machine.
        if (!LoopbackUri::isLoopback($redirectUri)) {
            return $response->withJson(['error' => 'redirect_uri must be a loopback address.'], 400);
        }

        // The point of the page: a person, not a script, asks for the key. Checked
        // after the cheap checks, so a malformed request costs no call to Cloudflare.
        if ($this->turnstile->isConfigured()) {
            $token = (string) ($body['turnstile_token'] ?? '');
            if (!$this->turnstile->verify($token, $ip)) {
                return $response->withJson(['error' => 'Captcha verification failed. Please try again.'], 400);
            }
        }

        $code = $this->drawKeys->createCode($challenge);

        return $response->withJson(['redirect_uri' => LoopbackUri::withQuery($redirectUri, ['code' => $code, 'state' => $state])]);
    }

    #[OAS\Operation\Post(
        path: '/plugin/draw-keys/token',
        summary: 'Exchange a draw-key code and its PKCE verifier for the draw key. Rate-limited: 10 attempts/IP/minute.',
        tags: ['Plugin'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['code', 'code_verifier'],
                properties: [
                    new OAS\Property(property: 'code', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'code_verifier', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The key (returned once): sent on draws as `Authorization: Bearer tg_dk_…`',
                content: new OAS\MediaType\Json(required: ['key'], properties: [new OAS\Property(property: 'key', schema: new OAS\Schema(type: 'string'))])
            ),
            new OAS\Response(response: 400, description: 'Invalid, expired or already used code, or the wrong verifier'),
            new OAS\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function token(Request $request, Response $response): Response|ResponseInterface
    {
        $limiter = new RateLimiter('draw_key_token', self::TOKEN_MAX_PER_WINDOW, self::TOKEN_WINDOW_SECONDS);
        $ip      = $this->clientIp($request);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(['error' => 'Too many attempts. Please try again later.'], 429);
        }
        $limiter->hit($ip);

        $body = $this->parsedBody($request);
        $key  = $this->drawKeys->exchangeCode((string) ($body['code'] ?? ''), (string) ($body['code_verifier'] ?? ''));
        if ($key === null) {
            return $response->withJson(['error' => 'Invalid or expired authorization code.'], 400);
        }

        return $response->withJson(['key' => $key]);
    }
}
