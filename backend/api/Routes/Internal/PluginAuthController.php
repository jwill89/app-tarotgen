<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\UserRepository;
use Tarot\Service\PluginTokenService;
use Tarot\Utility\LoopbackUri;
use Tarot\Utility\Pkce;
use Tarot\Utility\RateLimiter;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

/**
 * The Dalamud plugin account-linking endpoints (OAuth-style, PKCE public client).
 * See plugin/docs/auth.md. TarotGen.io is its own identity provider: the browser
 * consent step ({@see authorize}) mints a short-lived code, and the plugin
 * exchanges it ({@see token}) for a long-lived Bearer token. Tokens are listed
 * and revoked from the account's "Connected Apps" screen ({@see listTokens},
 * {@see revokeToken}).
 */
class PluginAuthController extends AbstractController
{
    // Code exchanges: 10 per IP per minute, every attempt counted, as a reading's
    // password attempts are. Linking makes one exchange; a run of them is guessing.
    private const int TOKEN_MAX_PER_WINDOW = 10;
    private const int TOKEN_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly PluginTokenService $pluginTokens,
        private readonly UserRepository $users,
    ) {}

    #[OAS\Operation\Post(
        path: '/plugin/authorize',
        summary: 'Approve a plugin link and mint a PKCE authorization code (browser, session-authed)',
        tags: ['Plugin'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['code_challenge', 'redirect_uri'],
                properties: [
                    new OAS\Property(property: 'code_challenge', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'code_challenge_method', schema: new OAS\Schema(type: 'string', default: 'S256')),
                    new OAS\Property(property: 'redirect_uri', schema: new OAS\Schema(type: 'string', description: 'Loopback URI, e.g. http://127.0.0.1:<port>/callback')),
                    new OAS\Property(property: 'state', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Loopback redirect target carrying the authorization code',
                content: new OAS\MediaType\Json(required: ['redirect_uri'], properties: [new OAS\Property(property: 'redirect_uri', schema: new OAS\Schema(type: 'string'))])
            ),
            new OAS\Response(response: 400, description: 'Invalid challenge or non-loopback redirect_uri'),
            new OAS\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function authorize(Request $request, Response $response): Response|ResponseInterface
    {
        Session::start();
        $userId = Session::userId();
        if ($userId === null) {
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        $body        = $this->parsedBody($request);
        $challenge   = trim((string) ($body['code_challenge'] ?? ''));
        $method      = strtoupper(trim((string) ($body['code_challenge_method'] ?? 'S256')));
        $redirectUri = trim((string) ($body['redirect_uri'] ?? ''));
        $state       = (string) ($body['state'] ?? '');

        if ($challenge === '' || $method !== Pkce::METHOD) {
            return $response->withJson(['error' => 'A valid S256 code_challenge is required.'], 400);
        }

        // The code is handed back to a loopback listener the plugin controls;
        // refusing any non-loopback target stops a crafted link from exfiltrating it.
        if (!LoopbackUri::isLoopback($redirectUri)) {
            return $response->withJson(['error' => 'redirect_uri must be a loopback address.'], 400);
        }

        $code = $this->pluginTokens->createAuthorizationCode($userId, $challenge);

        return $response->withJson(['redirect_uri' => LoopbackUri::withQuery($redirectUri, ['code' => $code, 'state' => $state])]);
    }

    #[OAS\Operation\Post(
        path: '/plugin/token',
        summary: 'Exchange a PKCE authorization code for a plugin Bearer token. Rate-limited: 10 attempts/IP/minute.',
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
                description: 'The issued token (returned once)',
                content: new OAS\MediaType\Json(required: ['token', 'token_type', 'scope', 'display_name'], properties: [
                    new OAS\Property(property: 'token', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'token_type', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'scope', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'display_name', schema: new OAS\Schema(type: 'string')),
                ])
            ),
            new OAS\Response(response: 400, description: 'Invalid or expired authorization code'),
            new OAS\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function token(Request $request, Response $response): Response|ResponseInterface
    {
        $limiter = new RateLimiter('plugin_token', self::TOKEN_MAX_PER_WINDOW, self::TOKEN_WINDOW_SECONDS);
        $ip      = $this->clientIp($request);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(['error' => 'Too many attempts. Please try again later.'], 429);
        }
        $limiter->hit($ip);

        $body     = $this->parsedBody($request);
        $code     = (string) ($body['code'] ?? '');
        $verifier = (string) ($body['code_verifier'] ?? '');

        $token = $this->pluginTokens->exchangeCode($code, $verifier);
        if ($token === null) {
            return $response->withJson(['error' => 'Invalid or expired authorization code.'], 400);
        }

        // Resolve the just-issued token to its owner so the plugin can show
        // "Linked as ‹name›" without a separate identity endpoint.
        $displayName = '';
        $userId = $this->pluginTokens->ownerOf($token);
        if ($userId !== null) {
            $user = $this->users->findById($userId);
            if ($user !== null) {
                $displayName = $user->display_name;
            }
        }

        return $response->withJson([
            'token'        => $token,
            'token_type'   => 'Bearer',
            'scope'        => 'account',
            'display_name' => $displayName,
        ]);
    }

    #[OAS\Operation\Get(
        path: '/account/tokens',
        summary: "The signed-in user's linked plugin tokens (Connected Apps)",
        tags: ['Plugin'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of linked tokens (never includes the token value)',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/PluginToken'))
            ),
        ]
    )]
    public function listTokens(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->pluginTokens->listForUser($this->authUserId($request)));
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/account/tokens/{id}',
        summary: 'Revoke a linked plugin token (forces the plugin to re-link)',
        tags: ['Plugin'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Revoked'),
            new OAS\Response(response: 404, description: 'Token not found'),
        ]
    )]
    public function revokeToken(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $tokenId = (int) ($args['id'] ?? 0);

        if (!$this->pluginTokens->revoke($this->authUserId($request), $tokenId)) {
            return $response->withJson(['error' => 'Token not found.'], 404);
        }

        return $response->withStatus(204);
    }
}
