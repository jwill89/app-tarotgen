<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Config\Env;
use Tarot\Repository\UserRepository;
use Tarot\Service\AuthService;
use Tarot\Service\TurnstileService;
use Tarot\Utility\RateLimiter;
use Tarot\Utility\Input;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

/**
 * Public account endpoints: register, activate, forgotten password, login,
 * logout, and "me". A session is signed in by $_SESSION['user_id'].
 */
class UserController extends AbstractController
{
    // Registration: max 5 per IP per hour (spam/abuse guard).
    private const int REGISTER_MAX    = 5;
    private const int REGISTER_WINDOW = 3600;

    // Login: max 10 failed attempts per IP per 15 minutes.
    private const int LOGIN_MAX    = 10;
    private const int LOGIN_WINDOW = 900;

    // Forgot-password requests: max 5 per IP per hour (anti email-bombing).
    private const int FORGOT_MAX    = 5;
    private const int FORGOT_WINDOW = 3600;

    public function __construct(
        private readonly AuthService $auth,
        private readonly UserRepository $users,
        private readonly TurnstileService $turnstile,
    ) {}

    #[OAS\Operation\Post(
        path: '/auth/register',
        summary: 'Create an account (inactive until email activation). Rate-limited: 5/IP/hour.',
        description: 'An email that already has an account gets the same 201 and message as a new one (no account enumeration): nothing is created, and that address is emailed a notice that someone tried to sign up with it. A display name that is taken is still refused (names are public).',
        tags: ['Authentication'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['email', 'display_name', 'password'],
                properties: [
                    new OAS\Property(property: 'email', schema: new OAS\Schema(type: 'string', format: 'email')),
                    new OAS\Property(property: 'display_name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', format: 'password', minLength: 12)),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Account created (or, for an email that already has one, answered as if it had been)',
                content: new OAS\MediaType\Json(
                    required: ['success', 'message'],
                    properties: [
                        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                        new OAS\Property(property: 'message', schema: new OAS\Schema(description: 'What to do next, to show the user.', type: 'string')),
                        new OAS\Property(property: 'activation_link', schema: new OAS\Schema(description: 'The link that activates the new account. Sent only outside production, only for an account just created, and only when the activation email could not be sent.', type: 'string')),
                    ]
                )
            ),
            new OAS\Response(response: 422, description: 'Validation failure, or a display name that is taken'),
            new OAS\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function register(Request $request, Response $response): Response|ResponseInterface
    {
        $limiter = new RateLimiter('user_register', self::REGISTER_MAX, self::REGISTER_WINDOW);
        $ip      = $this->clientIp($request);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(['error' => 'Too many sign-up attempts. Please try again later.'], 429);
        }

        $params      = $this->parsedBody($request);
        $email       = (string) ($params['email'] ?? '');
        $displayName = (string) ($params['display_name'] ?? '');
        $password    = (string) ($params['password'] ?? '');

        $result = $this->auth->register($email, $displayName, $password);

        if (!$result['ok']) {
            $limiter->hit($ip);
            return $response->withJson(['errors' => $result['errors'] ?? ['Registration failed.']], 422);
        }

        $limiter->hit($ip);

        $payload = [
            'success' => true,
            'message' => 'Account created. Check your email for an activation link to finish signing up.',
        ];

        // In non-production, when no SMTP is configured the email can't be sent,
        // so surface a new account's activation link directly to keep dev/testing
        // usable. (An email that already had an account has no link to show.)
        if (!Env::isProduction() && isset($result['activation_link']) && !($result['emailed'] ?? false)) {
            $payload['activation_link'] = $result['activation_link'];
            $payload['message'] .= ' (Email is not configured in this environment — use the activation link below.)';
        }

        return $response->withJson($payload, 201);
    }

    #[OAS\Operation\Post(
        path: '/auth/activate',
        summary: 'Activate an account from the emailed token',
        tags: ['Authentication'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['token'], properties: [new OAS\Property(property: 'token', schema: new OAS\Schema(type: 'string'))])
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Account activated',
                content: new OAS\MediaType\Json(
                    required: ['success', 'message'],
                    properties: [
                        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                        new OAS\Property(property: 'message', schema: new OAS\Schema(description: 'A confirmation to show the user.', type: 'string')),
                    ]
                )
            ),
            new OAS\Response(response: 400, description: 'Invalid/expired token'),
        ]
    )]
    public function activate(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        $token  = (string) ($params['token'] ?? '');

        if ($this->auth->activate($token)) {
            return $response->withJson([
                'success' => true,
                'message' => 'Your account is now active. You can log in.',
            ]);
        }

        return $response->withJson([
            'error' => 'This activation link is invalid or has expired.',
        ], 400);
    }

    #[OAS\Operation\Post(
        path: '/auth/forgot-password',
        summary: 'Begin a password reset (always answers 200 — no account enumeration). Rate-limited: 5/IP/hour.',
        tags: ['Authentication'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['email'], properties: [new OAS\Property(property: 'email', schema: new OAS\Schema(type: 'string', format: 'email'))])
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Identical response whether or not the account exists',
                content: new OAS\MediaType\Json(
                    required: ['success', 'message'],
                    properties: [
                        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                        new OAS\Property(property: 'message', schema: new OAS\Schema(description: 'The same wording either way, to show the user.', type: 'string')),
                        new OAS\Property(property: 'reset_link', schema: new OAS\Schema(description: 'The password-reset link. Sent only outside production, only for an account that exists, and only when the email could not be sent.', type: 'string')),
                    ]
                )
            ),
            new OAS\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function forgotPassword(Request $request, Response $response): Response|ResponseInterface
    {
        $limiter = new RateLimiter('user_forgot', self::FORGOT_MAX, self::FORGOT_WINDOW);
        $ip      = $this->clientIp($request);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(['error' => 'Too many requests. Please try again later.'], 429);
        }

        $params = $this->parsedBody($request);
        $email  = (string) ($params['email'] ?? '');

        $result = $this->auth->requestPasswordReset($email);
        $limiter->hit($ip);

        // Identical response whether or not the account exists (no enumeration).
        $payload = [
            'success' => true,
            'message' => 'If an account exists for that email, a password-reset link has been sent.',
        ];

        // Dev convenience only: surface the link when the account exists but no
        // SMTP is configured, so resets are testable without email.
        if (
            !Env::isProduction()
            && $result['exists']
            && !($result['emailed'] ?? false)
        ) {
            $payload['reset_link'] = $result['reset_link'] ?? null;
        }

        return $response->withJson($payload);
    }

    #[OAS\Operation\Post(
        path: '/auth/reset-password',
        summary: 'Complete a password reset',
        description: "Sets the new password, marks the account active, and ends every session of the account, so whoever knew the old password is signed out everywhere. Linked plugins keep their tokens (they're revoked under Connected Apps).",
        tags: ['Authentication'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['token', 'password'],
                properties: [
                    new OAS\Property(property: 'token', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', format: 'password')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Password updated; account marked active; its sessions ended',
                content: new OAS\MediaType\Json(
                    required: ['success', 'message'],
                    properties: [
                        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                        new OAS\Property(property: 'message', schema: new OAS\Schema(description: 'A confirmation to show the user.', type: 'string')),
                    ]
                )
            ),
            new OAS\Response(response: 400, description: 'Invalid/expired token'),
            new OAS\Response(response: 422, description: 'New password too weak (token not consumed)'),
        ]
    )]
    public function resetPassword(Request $request, Response $response): Response|ResponseInterface
    {
        $params   = $this->parsedBody($request);
        $token    = (string) ($params['token'] ?? '');
        $password = (string) ($params['password'] ?? '');

        $result = $this->auth->resetPassword($token, $password);

        if ($result['status'] === 'weak') {
            return $response->withJson(['error' => $result['error'] ?? 'Password is too weak.'], 422);
        }

        if ($result['status'] !== 'ok') {
            return $response->withJson(['error' => 'This reset link is invalid or has expired.'], 400);
        }

        return $response->withJson([
            'success' => true,
            'message' => 'Your password has been updated. You can now log in.',
        ]);
    }

    #[OAS\Operation\Post(
        path: '/auth/login',
        summary: 'Password sign-in. Rate-limited: 10 failed attempts/IP/15 min.',
        tags: ['Authentication'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['email', 'password'],
                properties: [
                    new OAS\Property(property: 'email', schema: new OAS\Schema(type: 'string', format: 'email')),
                    new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', format: 'password')),
                    new OAS\Property(property: 'remember_me', schema: new OAS\Schema(type: 'boolean')),
                    new OAS\Property(property: 'turnstile_token', schema: new OAS\Schema(type: 'string', description: 'Required when Turnstile is configured')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Signed in; sets the session cookie',
                content: new OAS\MediaType\Json(required: ['success', 'user'], properties: [
                    new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                    new OAS\Property(property: 'user', schema: new OAS\Schema(ref: '#/components/schemas/User')),
                ])
            ),
            new OAS\Response(response: 400, description: 'Captcha failed'),
            new OAS\Response(response: 401, description: 'Invalid credentials (an unknown email, or an account without a password, takes as long to answer as a wrong password)'),
            new OAS\Response(response: 403, description: 'Inactive or password-login disabled'),
            new OAS\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function login(Request $request, Response $response): Response|ResponseInterface
    {
        $limiter = new RateLimiter('user_login', self::LOGIN_MAX, self::LOGIN_WINDOW);
        $ip      = $this->clientIp($request);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(['error' => 'Too many attempts. Please try again later.'], 429);
        }

        $params     = $this->parsedBody($request);
        $email      = (string) ($params['email'] ?? '');
        $password   = (string) ($params['password'] ?? '');
        $rememberMe = Input::bool($params['remember_me'] ?? null);

        // Bot/abuse guard: when Turnstile is configured, a valid challenge token
        // is required before we even check credentials.
        if ($this->turnstile->isConfigured()) {
            $token = (string) ($params['turnstile_token'] ?? '');
            if (!$this->turnstile->verify($token, $ip)) {
                $limiter->hit($ip);
                return $response->withJson(['error' => 'Captcha verification failed. Please try again.'], 400);
            }
        }

        $result = $this->auth->authenticate($email, $password);

        if ($result['status'] === 'inactive') {
            $limiter->hit($ip);
            return $response->withJson([
                'error' => 'Your account is not activated yet. Please use the activation link sent to your email.',
            ], 403);
        }

        if ($result['status'] === 'password_disabled') {
            $limiter->hit($ip);
            return $response->withJson([
                'error' => 'Password login is disabled for this account. Please sign in with your passkey or Google.',
            ], 403);
        }

        if ($result['status'] !== 'ok' || !isset($result['user'])) {
            $limiter->hit($ip);
            return $response->withJson(['error' => 'Invalid email or password.'], 401);
        }

        $limiter->clear($ip);

        $this->startSessionWithPersistence();
        // Rotate the session ID on privilege change to prevent fixation.
        Session::regenerate(persistent: $rememberMe);
        $_SESSION['user_id'] = $result['user']->user_id;

        // Extend session cookie lifetime when "Remember Me" is checked.
        if ($rememberMe) {
            $this->persistSession();
        }

        return $response->withJson(['success' => true, 'user' => $result['user']]);
    }

    #[OAS\Operation\Post(
        path: '/auth/logout',
        summary: 'Clear the session',
        tags: ['Authentication'],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Signed out (also when there was no session)',
                content: new OAS\MediaType\Json(required: ['success'], properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
        ]
    )]
    public function logout(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSessionWithPersistence();

        Session::clearUser();
        Session::regenerate();

        return $response->withJson(['success' => true]);
    }

    #[OAS\Operation\Get(
        path: '/auth/me',
        summary: 'The current signed-in user',
        tags: ['Authentication'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The current user',
                content: new OAS\MediaType\Json(required: ['user'], properties: [new OAS\Property(property: 'user', schema: new OAS\Schema(ref: '#/components/schemas/User'))])
            ),
            new OAS\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function me(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSessionWithPersistence();

        $userId = Session::userId();
        if ($userId === null) {
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            // Stale session (account removed) — clear it.
            Session::clearUser();
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        return $response->withJson(['user' => $user]);
    }
}
