<?php

namespace Routes\Internal;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthnException;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Config\Env;
use Tarot\Repository\PasskeyRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\PasskeyDecoys;
use Tarot\Service\SignInMethods;
use Tarot\Structure\Passkey;
use Tarot\Utility\Clock;
use Tarot\Utility\Input;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

/**
 * WebAuthn/Passkey endpoints. The two login routes are public; the rest sit
 * behind UserAuth (a signed-in, active account).
 *
 *  POST   /auth/passkeys/register/options → Get credential creation options
 *  POST   /auth/passkeys/register         → Complete passkey registration
 *  POST   /auth/passkeys/login/options    → Get assertion options (public, optional email)
 *  POST   /auth/passkeys/login            → Authenticate with passkey (public)
 *  GET    /auth/passkeys                  → List current user's passkeys
 *  PATCH  /auth/passkeys/{id}             → Rename a passkey
 *  DELETE /auth/passkeys/{id}             → Delete a passkey
 *  PATCH  /auth/passkeys/password-login   → Toggle password login on/off
 */
class PasskeyController extends AbstractController
{
    public function __construct(
        private readonly PasskeyRepository $passkeys,
        private readonly UserRepository $users,
        private readonly SignInMethods $signInMethods,
        private readonly PasskeyDecoys $decoys,
    ) {}

    // ── Registration ─────────────────────────────────────────────────

    #[OAS\Operation\Post(
        path: '/auth/passkeys/register/options',
        summary: 'Credential-creation options (requires session)',
        tags: ['Passkeys'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'WebAuthn creation options',
                content: new OAS\MediaType\Json(
                    required: ['publicKey'],
                    properties: [
                        new OAS\Property(property: 'publicKey', schema: new OAS\Schema(
                            description: 'The PublicKeyCredentialCreationOptions for navigator.credentials.create(), with its binary fields (challenge, user.id, excludeCredentials[].id) base64url-encoded. excludeCredentials lists the passkeys already on the account.',
                            type: 'object',
                            additionalProperties: true
                        )),
                    ]
                )
            ),
            new OAS\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    /**
     * Generate WebAuthn credential creation options for the logged-in user.
     */
    public function registerOptions(Request $request, Response $response): Response|ResponseInterface
    {
        $userId = $this->authUserId($request);

        $user = $this->users->findById($userId);
        if ($user === null) {
            // UserAuth found the account a moment ago, so it was deleted since.
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        $webAuthn = $this->createWebAuthn();

        // Exclude already-registered credentials to prevent re-registration.
        $existingIds = $this->passkeys->getCredentialIds($userId);
        $excludeCredentials = array_map(
            fn(string $id) => new ByteBuffer(self::base64UrlDecode($id)),
            $existingIds
        );

        $createArgs = $webAuthn->getCreateArgs(
            (string) hex2bin(str_pad(dechex($userId), 16, '0', STR_PAD_LEFT)), // user id as binary
            $user->email,
            $user->display_name,
            60, // timeout seconds
            'preferred', // resident key
            'preferred', // user verification
            null, // cross-platform (allow both)
            $excludeCredentials
        );

        // Store challenge in session for verification.
        $_SESSION['webauthn_challenge'] = $webAuthn->getChallenge()->getBinaryString();

        return $response->withJson($createArgs);
    }

    #[OAS\Operation\Post(
        path: '/auth/passkeys/register',
        summary: 'Complete passkey registration',
        tags: ['Passkeys'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['clientDataJSON', 'attestationObject'],
                properties: [
                    new OAS\Property(property: 'clientDataJSON', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'attestationObject', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Passkey registered',
                content: new OAS\MediaType\Json(
                    required: ['success', 'passkey'],
                    properties: [
                        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                        new OAS\Property(property: 'passkey', schema: new OAS\Schema(ref: '#/components/schemas/RegisteredPasskey')),
                    ]
                )
            ),
            new OAS\Response(response: 400, description: 'Missing/invalid attestation'),
            new OAS\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    /**
     * Complete passkey registration: verify attestation and store credential.
     */
    public function register(Request $request, Response $response): Response|ResponseInterface
    {
        $userId = $this->authUserId($request);

        $challenge = $_SESSION['webauthn_challenge'] ?? null;
        unset($_SESSION['webauthn_challenge']);

        if ($challenge === null) {
            return $response->withJson(['error' => 'No pending registration challenge.'], 400);
        }

        $body = $this->parsedBody($request);
        $clientDataJSON   = self::base64UrlDecode((string) ($body['clientDataJSON'] ?? ''));
        $attestationObject = self::base64UrlDecode((string) ($body['attestationObject'] ?? ''));
        $name = Input::string($body['name'] ?? 'My Passkey', 50);
        if ($name === '') {
            $name = 'My Passkey';
        }

        if ($clientDataJSON === '' || $attestationObject === '') {
            return $response->withJson(['error' => 'Missing attestation data.'], 400);
        }

        try {
            $webAuthn = $this->createWebAuthn();
            $data = $webAuthn->processCreate(
                $clientDataJSON,
                $attestationObject,
                new ByteBuffer($challenge),
                false, // requireUserVerification
                true,  // requireUserPresent
                false  // failIfRootMismatch
            );
        } catch (\Throwable $e) {
            return $response->withJson(['error' => 'Registration failed: ' . $e->getMessage()], 400);
        }

        // Store credential.
        // credentialId is raw binary from the library — encode it to base64url for storage and lookup.
        $credentialId = $data->credentialId instanceof ByteBuffer
            ? self::base64UrlEncode($data->credentialId->getBinaryString())
            : self::base64UrlEncode((string) $data->credentialId);
        $signCount = $data->signatureCounter ?? 0;

        // A credential already on file (WebAuthn §7.1 step 22) would otherwise hit the
        // unique index as a 500. Browsers skip the excluded ones, but a client needn't.
        if ($this->passkeys->findByCredentialId($credentialId) !== null) {
            return $response->withJson(['error' => 'This passkey is already registered.'], 400);
        }

        $passkeyId = $this->passkeys->create(
            $userId,
            $credentialId,
            $data->credentialPublicKey,
            $name,
            $signCount
        );

        return $response->withJson([
            'success' => true,
            'passkey' => [
                'passkey_id'  => $passkeyId,
                'name'        => $name,
                'created_at'  => Clock::now(),
                'last_used_at' => null,
            ],
        ], 201);
    }

    // ── Authentication ───────────────────────────────────────────────

    /**
     * Generate assertion options for passkey login.
     * Accepts optional email to scope to a specific user's credentials,
     * or no email for a discoverable-credential (resident key) flow.
     */
    #[OAS\Operation\Post(
        path: '/auth/passkeys/login/options',
        summary: "Assertion options; optional email scopes to a user's credentials",
        description: "With an email, allowCredentials always lists credentials: the account's passkeys, or for an email with none (no account, or an account without passkeys) stand-ins that look the same and stay the same, so the answer doesn't say which emails have accounts. Without one, any discoverable passkey may answer.",
        tags: ['Passkeys'],
        requestBody: new OAS\RequestBody(
            content: new OAS\MediaType\Json(properties: [new OAS\Property(property: 'email', schema: new OAS\Schema(type: 'string', format: 'email'))])
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'WebAuthn assertion options',
                content: new OAS\MediaType\Json(
                    required: ['publicKey'],
                    properties: [
                        new OAS\Property(property: 'publicKey', schema: new OAS\Schema(
                            description: "The PublicKeyCredentialRequestOptions for navigator.credentials.get(), with its binary fields (challenge, allowCredentials[].id) base64url-encoded. allowCredentials is present whenever an email was given (the account's passkeys, or stand-ins); without one, any discoverable passkey may answer.",
                            type: 'object',
                            additionalProperties: true
                        )),
                    ]
                )
            ),
        ]
    )]
    public function loginOptions(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();

        $body  = $this->parsedBody($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        $credentialIds = [];

        if ($email !== '') {
            // The account's passkeys, or stand-ins when there are none to name —
            // worked out either way, so neither the answer nor its timing says
            // whether the email has an account. The stand-ins are deterministic,
            // like real ids, and no authenticator holds them.
            $decoys = $this->decoys->forEmail($email);
            $user   = $this->users->findByEmail($email);
            $real   = $user === null ? [] : array_map(self::base64UrlDecode(...), $this->passkeys->getCredentialIds($user->user_id));

            $credentialIds = array_map(
                static fn(string $id): ByteBuffer => new ByteBuffer($id),
                $real !== [] ? $real : $decoys
            );
        }

        $webAuthn = $this->createWebAuthn();
        $getArgs = $webAuthn->getGetArgs(
            $credentialIds,
            60,   // timeout
            true, // USB
            true, // NFC
            true, // BLE
            true, // Hybrid
            true, // Internal
            'preferred' // user verification
        );

        $_SESSION['webauthn_challenge'] = $webAuthn->getChallenge()->getBinaryString();

        return $response->withJson($getArgs);
    }

    #[OAS\Operation\Post(
        path: '/auth/passkeys/login',
        summary: 'Verify an assertion and sign in',
        tags: ['Passkeys'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['id', 'clientDataJSON', 'authenticatorData', 'signature'],
                properties: [
                    new OAS\Property(property: 'id', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'clientDataJSON', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'authenticatorData', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'signature', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Signed in',
                content: new OAS\MediaType\Json(required: ['success', 'user'], properties: [
                    new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                    new OAS\Property(property: 'user', schema: new OAS\Schema(ref: '#/components/schemas/User')),
                ])
            ),
            new OAS\Response(response: 400, description: 'Missing assertion data'),
            new OAS\Response(response: 401, description: 'Authentication failed'),
            new OAS\Response(response: 403, description: 'Account not activated'),
        ]
    )]
    /**
     * Verify a passkey assertion and log the user in.
     */
    public function login(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();

        $challenge = $_SESSION['webauthn_challenge'] ?? null;
        unset($_SESSION['webauthn_challenge']);

        if ($challenge === null) {
            return $response->withJson(['error' => 'No pending authentication challenge.'], 400);
        }

        $body = $this->parsedBody($request);
        $credentialIdB64  = (string) ($body['id'] ?? '');
        $clientDataJSON   = self::base64UrlDecode((string) ($body['clientDataJSON'] ?? ''));
        $authenticatorData = self::base64UrlDecode((string) ($body['authenticatorData'] ?? ''));
        $signature        = self::base64UrlDecode((string) ($body['signature'] ?? ''));

        if ($credentialIdB64 === '' || $clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
            return $response->withJson(['error' => 'Missing assertion data.'], 400);
        }

        // Look up credential in database.
        $passkey = $this->passkeys->findByCredentialId($credentialIdB64);
        if ($passkey === null) {
            return $response->withJson(['error' => 'Unknown passkey credential.'], 401);
        }

        try {
            $webAuthn = $this->createWebAuthn();
            $webAuthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $passkey->public_key_pem,
                new ByteBuffer($challenge),
                $passkey->sign_count > 0 ? $passkey->sign_count : null,
                false, // requireUserVerification
                true   // requireUserPresent
            );
        } catch (WebAuthnException $e) {
            return $response->withJson(['error' => 'Passkey authentication failed: ' . $e->getMessage()], 401);
        }

        // Update signature counter.
        $newCount = $webAuthn->getSignatureCounter();
        if ($newCount !== null) {
            $this->passkeys->updateSignCount($passkey->passkey_id, $newCount);
        }

        // Load user and establish session.
        $user = $this->users->findById($passkey->user_id);
        if ($user === null) {
            return $response->withJson(['error' => 'Account not found.'], 401);
        }

        if (!$user->is_active) {
            return $response->withJson(['error' => 'Your account is not activated.'], 403);
        }

        $this->users->touchLogin($user->user_id);

        Session::regenerate(persistent: true);
        $_SESSION['user_id'] = $user->user_id;

        // Passkey login implies strong intent — always persist the session.
        $this->persistSession();

        // Read again, so last_login_at is this sign-in (as the password login answers).
        return $response->withJson(['success' => true, 'user' => $this->users->findById($user->user_id) ?? $user]);
    }

    // ── Management ───────────────────────────────────────────────────

    #[OAS\Operation\Get(
        path: '/auth/passkeys',
        summary: "List the current user's passkeys",
        tags: ['Passkeys'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of passkeys',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Passkey'))
            ),
            new OAS\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    /**
     * List the logged-in user's passkeys.
     */
    public function list(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->passkeys->getByUser($this->authUserId($request)));
    }

    /**
     * Rename a passkey.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Patch(
        path: '/auth/passkeys/{id}',
        summary: 'Rename a passkey',
        tags: ['Passkeys'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['name'], properties: [new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string'))])
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Renamed',
                content: new OAS\MediaType\Json(required: ['success'], properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
            new OAS\Response(response: 401, description: 'Not authenticated'),
            new OAS\Response(response: 404, description: 'Passkey not found'),
            new OAS\Response(response: 422, description: 'Name is required'),
        ]
    )]
    public function rename(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $userId = $this->authUserId($request);

        $passkeyId = (int) ($args['id'] ?? 0);
        $body = $this->parsedBody($request);
        $name = Input::string($body['name'] ?? '', 50);

        if ($name === '') {
            return $response->withJson(['error' => 'Name is required.'], 422);
        }

        if (!$this->passkeys->rename($passkeyId, $userId, $name)) {
            return $response->withJson(['error' => 'Passkey not found.'], 404);
        }

        return $response->withJson(['success' => true]);
    }

    /**
     * Delete a passkey.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/auth/passkeys/{id}',
        summary: 'Delete a passkey (refused when it is the last way to sign in)',
        description: 'The last passkey can go only while the account can still sign in another way: a password (set, with password login on) or a linked Google account.',
        tags: ['Passkeys'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 401, description: 'Not authenticated'),
            new OAS\Response(response: 404, description: 'Passkey not found'),
            new OAS\Response(response: 409, description: 'The last way to sign in; the message says what to set up first'),
        ]
    )]
    public function delete(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $userId    = $this->authUserId($request);
        $passkeyId = (int) ($args['id'] ?? 0);
        $mine      = array_map(static fn(Passkey $passkey): int => $passkey->passkey_id, $this->passkeys->getByUser($userId));

        // Ownership first: another account's passkey is simply not found,
        // whatever this account's own settings are.
        if (!in_array($passkeyId, $mine, true)) {
            return $response->withJson(['error' => 'Passkey not found.'], 404);
        }

        $refusal = $this->signInMethods->refuseDeletingPasskey($userId);
        if ($refusal !== null) {
            return $response->withJson(['error' => $refusal], 409);
        }

        if (!$this->passkeys->delete($passkeyId, $userId)) {
            return $response->withJson(['error' => 'Passkey not found.'], 404);
        }

        return $response->withStatus(204);
    }

    #[OAS\Operation\Patch(
        path: '/auth/passkeys/password-login',
        summary: 'Toggle password login on/off (turning it off needs a passkey or a linked Google account)',
        tags: ['Passkeys'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(properties: [new OAS\Property(property: 'disable', schema: new OAS\Schema(type: 'boolean'))])
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated',
                content: new OAS\MediaType\Json(
                    required: ['success', 'password_login_disabled', 'user'],
                    properties: [
                        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                        new OAS\Property(property: 'password_login_disabled', schema: new OAS\Schema(description: 'The setting now in force.', type: 'boolean')),
                        new OAS\Property(property: 'user', schema: new OAS\Schema(ref: '#/components/schemas/User', description: 'The account as it now stands.')),
                    ]
                )
            ),
            new OAS\Response(response: 401, description: 'Not authenticated'),
            new OAS\Response(response: 409, description: 'Turning it off would leave no way to sign in; the message says what to set up first'),
        ]
    )]
    /**
     * Toggle password login enabled/disabled.
     */
    public function togglePasswordLogin(Request $request, Response $response): Response|ResponseInterface
    {
        $userId = $this->authUserId($request);

        $body = $this->parsedBody($request);
        $disable = Input::bool($body['disable'] ?? null);

        $refusal = $disable ? $this->signInMethods->refuseDisablingPasswordLogin($userId) : null;
        if ($refusal !== null) {
            return $response->withJson(['error' => $refusal], 409);
        }

        $this->users->setPasswordLoginDisabled($userId, $disable);

        return $response->withJson([
            'success' => true,
            'password_login_disabled' => $disable,
            'user' => $this->users->findById($userId),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * @throws WebAuthnException
     */
    private function createWebAuthn(): WebAuthn
    {
        $rpName = Env::get('APP_NAME', 'Tarot Generator');
        $host   = parse_url(Env::get('APP_URL', 'https://tarotgen.io'), PHP_URL_HOST);
        $rpId   = Env::get('WEBAUTHN_RP_ID', is_string($host) ? $host : 'tarotgen.io');

        return new WebAuthn($rpName, $rpId, ['none', 'packed', 'fido-u2f', 'android-key', 'apple'], true);
    }

    private function startSession(): void
    {
        Session::start();
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }
}
