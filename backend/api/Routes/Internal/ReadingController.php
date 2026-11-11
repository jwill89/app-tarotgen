<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Routes\Middleware\AdminAuth;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use JsonException;
use Tarot\Exception\ApiException;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\DrawKeyService;
use Tarot\Service\PluginTokenService;
use Tarot\Service\ReadingService;
use Tarot\Service\TurnstileService;
use Tarot\Structure\Reading;
use Tarot\Utility\BearerToken;
use Tarot\Utility\Input;
use Tarot\Utility\RateLimiter;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

class ReadingController extends AbstractController
{
    // Public reading endpoints are IP rate-limited (keyed on the real client IP,
    // resolved through Cloudflare) to blunt scripted abuse without hurting humans.
    private const int GENERATE_MAX_PER_WINDOW = 30;   // new readings / IP / window
    private const int READ_MAX_PER_WINDOW     = 120;  // fetches by id / IP / window
    private const int UNLOCK_MAX_PER_WINDOW   = 10;   // password attempts (brute-force guard)
    private const int RATE_WINDOW_SECONDS     = 60;

    // Readings made with an unlinked plugin's draw key (when Turnstile guards
    // drawing): per key, a person's pace in a minute and a busy evening's worth
    // in a day, so a key lifted from an install can't feed a bot; per IP, the 30
    // a minute New Draw allows, counted across both routes (POST /readings has no
    // IP limit of its own).
    private const int KEY_DRAWS_PER_MINUTE    = 10;
    private const int KEY_DRAWS_PER_DAY       = 300;
    private const int KEY_DRAWS_PER_IP_MINUTE = 30;
    private const int DAY_SECONDS             = 86400;

    private const string CAPTCHA_FAILED = 'Captcha verification failed. Please try again.';
    private const string PLUGIN_NEEDS_KEY = 'This version of the TarotGen plugin can no longer draw without an account. '
        . 'Update the plugin to get a draw key, or link your account.';
    private const string KEY_INVALID = 'This draw key is no longer valid. Get a new one from the plugin.';
    private const string ACCOUNT_LINK_INVALID = 'This account link is no longer valid. Link your account again in the plugin.';
    private const string KEY_LIMITED = 'Too many readings with this draw key. Please try again later.';

    // Who may make a reading, as the two routes that make one document it.
    private const string DRAW_ACCESS_DOC = 'When Turnstile is configured, a request with no signed-in user (a browser '
        . 'session, or a linked plugin\'s account token) needs either a passed challenge in `turnstile_token` (a signed-out '
        . 'visitor), or an unlinked plugin\'s draw key as `Authorization: Bearer tg_dk_…` (POST /plugin/draw-keys/token). '
        . 'When it isn\'t, nothing beyond the reading is required.';
    private const string DRAW_REFUSED_DOC = 'or, with Turnstile on, neither a signed-in user nor a draw key nor a passed '
        . 'challenge ("' . self::CAPTCHA_FAILED . '"; from a plugin too old for draw keys: "' . self::PLUGIN_NEEDS_KEY . '")';
    private const string DRAW_CREDENTIAL_DOC = 'With Turnstile on: a draw key that is unknown or revoked ("' . self::KEY_INVALID
        . '"), or a plugin account token that no longer is ("' . self::ACCOUNT_LINK_INVALID . '")';
    private const string KEY_LIMITS_DOC = 'with a draw key, 10/key/minute, 300/key/day and 30/IP/minute ("' . self::KEY_LIMITED . '")';

    public function __construct(
        private readonly ReadingRepository $readings,
        private readonly ReadingService $readingService,
        private readonly UserRepository $users,
        private readonly PluginTokenService $pluginTokens,
        private readonly DrawKeyService $drawKeys,
        private readonly TurnstileService $turnstile,
        private readonly AdminAuth $admin,
    ) {}

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Get(
        path: '/readings/{reading_id}',
        summary: 'Fetch a reading (password-protected readings return a locked stub to non-owners)',
        tags: ['Readings'],
        parameters: [
            new OAS\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OAS\Schema(type: 'string')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The reading, or — when it has a password this requester has not given — the locked stub',
                content: new OAS\MediaType\Json(schema: new OAS\Schema(oneOf: [
                    new OAS\Schema(ref: '#/components/schemas/ReadingView'),
                    new OAS\Schema(ref: '#/components/schemas/LockedReading'),
                ]))
            ),
            new OAS\Response(response: 404, description: "InvalidReadingID: no such reading, one past its expiry, or someone else's private reading"),
            new OAS\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function getReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $ip      = $this->clientIp($request);
        $limiter = new RateLimiter('reading_read', self::READ_MAX_PER_WINDOW, self::RATE_WINDOW_SECONDS);
        if ($limiter->isLimited($ip)) {
            return $response->withJson(
                ['error' => 'Too many requests. Please slow down and try again shortly.'],
                429
            );
        }
        $limiter->hit($ip);

        $reading_id = $args['reading_id'] ?? '';
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        Session::start();
        $viewerId = $this->currentUserId($request);

        // A private reading is unknown to everyone but its owner (and their plugin).
        if (!($reading instanceof Reading) || !$reading->isVisibleTo($viewerId)) {
            return $response->withJson(['error' => 'InvalidReadingID'], 404);
        }

        $isOwner = $reading->isOwnedBy($viewerId);

        // Password gate: anyone who isn't the owner and hasn't already unlocked
        // this reading in their session gets only the locked stub.
        if ($reading->password_protected && !$isOwner && ($_SESSION['unlocked_readings'][$reading_id] ?? false) !== true) {
            return $response
                ->withJson(['locked' => true, 'reading_name' => $reading->reading_name])
                ->withHeader('Cache-Control', 'private, no-store');
        }

        return $response
            ->withJson($this->accessiblePayload($reading, $isOwner))
            // The response depends on the viewer's session, so it must not be cached.
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/readings/{reading_id}/unlock',
        summary: 'Unlock a password-protected reading for this session',
        tags: ['Readings'],
        parameters: [
            new OAS\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OAS\Schema(type: 'string')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['password'],
                properties: [new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string'))]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The unlocked reading',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/ReadingView')
            ),
            new OAS\Response(response: 401, description: 'Incorrect password'),
            new OAS\Response(response: 404, description: "InvalidReadingID: no such reading, one past its expiry, or someone else's private reading"),
            new OAS\Response(response: 429, description: 'Too many password attempts'),
        ]
    )]
    public function unlockReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        // Guard against password brute-forcing: count every attempt, not just failures.
        $ip      = $this->clientIp($request);
        $limiter = new RateLimiter('reading_unlock', self::UNLOCK_MAX_PER_WINDOW, self::RATE_WINDOW_SECONDS);
        if ($limiter->isLimited($ip)) {
            return $response->withJson(
                ['error' => 'Too many password attempts. Please wait a minute and try again.'],
                429
            );
        }
        $limiter->hit($ip);

        $reading_id = $args['reading_id'] ?? '';
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        Session::start();
        $viewerId = $this->currentUserId($request);

        if (!($reading instanceof Reading) || !$reading->isVisibleTo($viewerId)) {
            return $response->withJson(['error' => 'InvalidReadingID'], 404);
        }

        $isOwner = $reading->isOwnedBy($viewerId);

        if (!$reading->password_protected || $isOwner) {
            // Nothing to unlock — just return the reading.
            return $response->withJson($this->accessiblePayload($reading, $isOwner))
                ->withHeader('Cache-Control', 'private, no-store');
        }

        $password = (string) (($this->parsedBody($request))['password'] ?? '');
        if (!$this->readings->verifyPassword($reading_id, $password)) {
            return $response->withJson(['error' => 'Incorrect password.'], 401);
        }

        // Remember the unlock for this session so refreshes keep working.
        $_SESSION['unlocked_readings'][$reading_id] = true;

        return $response->withJson($this->accessiblePayload($reading, $isOwner))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/readings/generate',
        summary: 'Generate a random reading from a draw spec',
        tags: ['Readings'],
        requestBody: new OAS\RequestBody(
            required: true,
            description: 'Draw spec: deck, spread or card count, and options. A `user_spread_id` counts only for '
                . 'its owner (signed in, or by plugin token): anyone else\'s is ignored, like an id that does not exist. '
                . 'A signed-in reader may also send `reading_name`, `reading_notes`, `hide_user`, `question` (up to 500 '
                . 'characters), `querent` (up to 100), `tags` (a list, or one comma-separated string: at most 10, of at most '
                . '30 characters each; stored lower-case) and `visibility` (link, password with `password`, or private); a '
                . 'guest\'s are ignored. A guest may send `expires_in_days` (1, 7 or 30) to have the reading deleted then '
                . 'instead of after the usual year; a signed-in reader\'s is ignored. ' . self::DRAW_ACCESS_DOC,
            content: new OAS\MediaType\Json(type: 'object')
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'The created reading',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/ReadingView')
            ),
            new OAS\Response(response: 400, description: 'Invalid draw spec (InvalidDeckID also for a deck awaiting approval, unless an admin draws); ' . self::DRAW_REFUSED_DOC),
            new OAS\Response(response: 401, description: self::DRAW_CREDENTIAL_DOC),
            new OAS\Response(response: 429, description: 'Rate limit exceeded: 30 draws/IP/minute; ' . self::KEY_LIMITS_DOC),
        ]
    )]
    public function newReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $ip      = $this->clientIp($request);
        $limiter = new RateLimiter('reading_generate', self::GENERATE_MAX_PER_WINDOW, self::RATE_WINDOW_SECONDS);
        if ($limiter->isLimited($ip)) {
            return $response->withJson(
                ['error' => 'You are generating readings too quickly. Please wait a moment.'],
                429
            );
        }
        $limiter->hit($ip);

        try {
            $userId  = $this->currentUserId($request);
            $refusal = $this->refuseDraw($request, $response, $userId);
            if ($refusal !== null) {
                return $refusal;
            }

            $reading = $this->readingService->generate($this->parsedBody($request), $userId, $this->isAdmin($userId));
            // Return the viewer-aware payload (like getReading) so a linked owner
            // sees is_owner=true and can immediately finalize their new reading.
            $isOwner = $userId !== null && $reading->user_id === $userId;
            return $response->withJson($this->accessiblePayload($reading, $isOwner), 201);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/readings',
        summary: 'Create a custom reading from explicitly chosen cards/positions',
        tags: ['Readings'],
        requestBody: new OAS\RequestBody(
            required: true,
            description: 'The deck, a name and the cards with their positions, plus the same owner options as '
                . 'POST /readings/generate (a signed-in reader\'s) or expiry (a guest\'s). ' . self::DRAW_ACCESS_DOC,
            content: new OAS\MediaType\Json(type: 'object')
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'The created reading, as stored (not the viewer payload the other reading commands return)',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Reading')
            ),
            new OAS\Response(response: 400, description: 'Invalid input (InvalidDeckID also for a deck awaiting approval, unless an admin records it); ' . self::DRAW_REFUSED_DOC),
            new OAS\Response(response: 401, description: self::DRAW_CREDENTIAL_DOC),
            new OAS\Response(response: 429, description: 'Rate limit exceeded: ' . self::KEY_LIMITS_DOC),
        ]
    )]
    public function customReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        try {
            $userId  = $this->currentUserId($request);
            $refusal = $this->refuseDraw($request, $response, $userId);
            if ($refusal !== null) {
                return $refusal;
            }

            $reading = $this->readingService->createCustom($this->parsedBody($request), $userId, $this->isAdmin($userId));
            return $response->withJson($reading, 201);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * Update a reading's spread/placement information after a "Free Draw With Placement"
     * has been generated. The caller provides position data which is merged into the
     * existing reading_info JSON as a spread snapshot.
     *
     * @param array<string,string> $args
     * @throws JsonException
     */
    #[OAS\Operation\Put(
        path: '/readings/{reading_id}/placement',
        summary: 'Save spread/placement positions onto an existing reading',
        tags: ['Readings'],
        parameters: [
            new OAS\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OAS\Schema(type: 'string')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['positions'],
                properties: [
                    new OAS\Property(property: 'positions', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition'))),
                    new OAS\Property(property: 'spread_name', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Saved',
                content: new OAS\MediaType\Json(required: ['success', 'reading_id'], properties: [
                    new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                    new OAS\Property(property: 'reading_id', schema: new OAS\Schema(type: 'string')),
                ])
            ),
            new OAS\Response(response: 400, description: 'Position count mismatch or invalid data'),
            new OAS\Response(response: 403, description: 'Not the owner'),
            new OAS\Response(response: 404, description: "Reading not found: no such reading, one past its expiry, or someone else's private reading"),
            new OAS\Response(response: 409, description: 'Reading is final'),
        ]
    )]
    public function updatePlacement(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = $args['reading_id'] ?? '';
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;

        // Allow guests (by reading_id alone) or the owner — placement is done
        // immediately after generation, before the user navigates away.
        $userId = $this->currentUserId($request);

        if (!($reading instanceof Reading) || !$reading->isVisibleTo($userId)) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        $isOwner = $reading->isOwnedBy($userId);
        $isGuest = $reading->user_id === null;

        if (!$isOwner && !$isGuest) {
            return $response->withJson(['error' => 'You do not own this reading.'], 403);
        }

        if ($reading->is_final) {
            return $response->withJson(['error' => 'This reading is final and can no longer be changed.'], 409);
        }

        $params = $this->parsedBody($request);
        $positions = $params['positions'] ?? [];

        if (!is_array($positions) || count($positions) === 0) {
            return $response->withJson(['error' => 'At least one position is required.'], 400);
        }

        // Decode existing reading_info.
        $info = json_decode($reading->reading_info, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            return $response->withJson(['error' => 'Invalid reading data.'], 500);
        }

        $drawCount = count($info['draw'] ?? []);
        if (count($positions) !== $drawCount) {
            return $response->withJson(['error' => 'Position count must match the number of drawn cards.'], 400);
        }

        // Build spread snapshot from the placement data. When the reading
        // already had a spread (e.g. drawing more cards into an existing spread),
        // preserve its identity — only the positions are being rewritten.
        $existingSpread = is_array($info['spread'] ?? null) ? $info['spread'] : null;
        $spreadName = Input::string($params['spread_name'] ?? null, 100);
        if ($spreadName === '') {
            $existingName = (string) ($existingSpread['name'] ?? '');
            $spreadName = $existingName !== '' ? $existingName : 'Free Draw Placement';
        }
        $snappedPositions = [];

        foreach ($positions as $idx => $pos) {
            if (!is_array($pos)) {
                return $response->withJson(['error' => 'Invalid position data.'], 400);
            }

            $rotation = (int) ($pos['rotation'] ?? 0) % 360;
            if ($rotation < 0) {
                $rotation += 360;
            }

            $snapped = [
                'order'    => $idx + 1,
                'title'    => Input::string($pos['title'] ?? null, 100),
                'x'        => max(0.0, min(100.0, round((float) ($pos['x'] ?? 50), 2))),
                'y'        => max(0.0, min(100.0, round((float) ($pos['y'] ?? 50), 2))),
                'rotation' => $rotation,
            ];

            // A position that keeps its place and title keeps its description:
            // re-placing after drawing more cards mustn't lose the spread's own.
            $before = $existingSpread['positions'][$idx] ?? null;
            if (is_array($before) && ($before['title'] ?? null) === $snapped['title'] && is_string($before['description'] ?? null)) {
                $snapped['description'] = $before['description'];
            }

            $snappedPositions[] = $snapped;
        }

        $info['spread'] = [
            'spread_id'   => (int) ($existingSpread['spread_id'] ?? 0),
            'name'        => $spreadName,
            'description' => (string) ($existingSpread['description'] ?? ''),
            'positions'   => $snappedPositions,
        ];

        $updatedJson = json_encode($info, JSON_THROW_ON_ERROR);
        $ownerId = $isOwner ? $userId : null;
        $updated = $this->readings->updateReadingInfo($reading_id, $updatedJson, $ownerId);

        if ($updated === null) {
            return $response->withJson(['error' => 'Failed to save placement.'], 500);
        }

        return $response->withJson(['success' => true, 'reading_id' => $reading_id]);
    }

    /**
     * Draw additional cards into an existing generated reading owned by the
     * caller. The new cards are appended to the draw; for a spread reading the
     * client then opens the placement editor to position them.
     *
     * @param array<string,string> $args
     * @throws JsonException
     */
    #[OAS\Operation\Post(
        path: '/readings/{reading_id}/draw',
        summary: 'Draw additional cards into a non-final, non-custom reading you own',
        tags: ['Readings'],
        parameters: [
            new OAS\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OAS\Schema(type: 'string')),
        ],
        requestBody: new OAS\RequestBody(content: new OAS\MediaType\Json(type: 'object')),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The updated reading',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/ReadingView')
            ),
            new OAS\Response(response: 400, description: 'Custom readings cannot draw'),
            new OAS\Response(response: 403, description: 'Not the owner'),
            new OAS\Response(response: 404, description: "Reading not found: no such reading, one past its expiry, or someone else's private reading"),
            new OAS\Response(response: 409, description: 'Reading is final'),
        ]
    )]
    public function drawCards(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = $args['reading_id'] ?? '';
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;
        $userId     = $this->currentUserId($request);

        if (!($reading instanceof Reading) || !$reading->isVisibleTo($userId)) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        if ($userId === null || !$reading->isOwnedBy($userId)) {
            return $response->withJson(['error' => 'You do not own this reading.'], 403);
        }

        if ($reading->is_final) {
            return $response->withJson(['error' => 'This reading is final and can no longer be changed.'], 409);
        }

        $info = json_decode($reading->reading_info, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            return $response->withJson(['error' => 'Invalid reading data.'], 500);
        }

        if (($info['origin'] ?? null) === 'custom') {
            return $response->withJson(['error' => 'Custom readings cannot draw additional cards.'], 400);
        }

        try {
            $updated = $this->readingService->drawAdditional($info, $this->parsedBody($request));
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }

        $saved = $this->readings->updateReadingInfo(
            $reading_id,
            json_encode($updated, JSON_THROW_ON_ERROR),
            $userId
        );

        if (!($saved instanceof Reading)) {
            return $response->withJson(['error' => 'Failed to save the new cards.'], 500);
        }

        return $response->withJson($this->accessiblePayload($saved, true))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Mark a reading as final, permanently locking it against further draws.
     * One-way: there is no endpoint to undo it. Owner-only.
     *
     * @param array<string,string> $args
     * @throws JsonException
     */
    #[OAS\Operation\Post(
        path: '/readings/{reading_id}/finalize',
        summary: 'Permanently lock a reading against further draws (owner-only, one-way)',
        tags: ['Readings'],
        parameters: [
            new OAS\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OAS\Schema(type: 'string')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The finalized reading',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/ReadingView')
            ),
            new OAS\Response(response: 403, description: 'Not the owner'),
            new OAS\Response(response: 404, description: "Reading not found: no such reading, one past its expiry, or someone else's private reading"),
        ]
    )]
    public function finalizeReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = $args['reading_id'] ?? '';
        $reading    = $reading_id !== '' ? $this->readings->get($reading_id) : null;
        $userId     = $this->currentUserId($request);

        if (!($reading instanceof Reading) || !$reading->isVisibleTo($userId)) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        if ($userId === null || !$reading->isOwnedBy($userId)) {
            return $response->withJson(['error' => 'You do not own this reading.'], 403);
        }

        $updated = $this->readings->markFinal($reading_id, $userId);
        if (!($updated instanceof Reading)) {
            return $response->withJson(['error' => 'Failed to finalize the reading.'], 500);
        }

        return $response->withJson($this->accessiblePayload($updated, true))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Build the full reading payload for a viewer allowed to see it, including
     * the resolved author label (respecting hide_user) and whether the viewer
     * is the owner. The journal fields (question, querent, tags) are the
     * owner's alone: anyone else's payload doesn't carry the keys at all.
     *
     * @return array<string,mixed>
     * @throws JsonException
     */
    private function accessiblePayload(Reading $reading, bool $isOwner): array
    {
        // Decode reading_info so it's sent as a proper JSON object, not a string.
        $info = json_decode($reading->reading_info, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($info)) {
            $info = [];
        }

        // Only the owner of a randomly-generated (non-custom) reading that hasn't
        // been finalized may draw additional cards into it.
        $origin      = $info['origin'] ?? null;
        $canDrawMore = $isOwner && !$reading->is_final && $origin !== 'custom';

        $payload = [
            'reading_id'    => $reading->reading_id,
            'reading_info'  => $info,
            'reading_time'  => $reading->reading_time,
            'reading_name'  => $reading->reading_name,
            'reading_notes' => $reading->reading_notes,
            'reader'        => $this->resolveReader($reading),
            'is_owner'      => $isOwner,
            'is_final'      => $reading->is_final,
            'can_draw_more' => $canDrawMore,
            'locked'        => false,
            'visibility'    => $reading->visibility,
            'expires_at'    => $reading->expires_at,
        ];

        if ($isOwner) {
            $payload += [
                'question'  => $reading->question,
                'querent'   => $reading->querent,
                'tags'      => $reading->tags,
                // So the owner's reading page can edit it as My Readings does.
                'hide_user' => $reading->hide_user,
            ];
        }

        return $payload;
    }

    /**
     * The author label shown in the reading details: "Guest" when there's no
     * owner or the owner hid their name, otherwise the owner's display name.
     */
    private function resolveReader(Reading $reading): string
    {
        if ($reading->user_id === null || $reading->hide_user) {
            return 'Guest';
        }

        $owner = $this->users->findById($reading->user_id);
        return $owner->display_name ?? 'Guest';
    }

    /**
     * Who may make a reading, on the two routes that make one. With
     * Turnstile configured, a request with no signed-in user needs a draw key (an
     * unlinked plugin, which can't show a browser challenge) or a passed challenge
     * (a signed-out visitor). Without Turnstile, as in development, nothing more.
     * Null when the reading may be made; otherwise the refusal.
     */
    private function refuseDraw(Request $request, Response $response, ?int $userId): ?ResponseInterface
    {
        if ($userId !== null || !$this->turnstile->isConfigured()) {
            return null;
        }

        $authorization = $request->getHeaderLine('Authorization');
        if ($this->drawKeys->presentsKey($authorization)) {
            $fingerprint = $this->drawKeys->resolve($authorization);
            if ($fingerprint === null) {
                return $response->withJson(['error' => self::KEY_INVALID], 401);
            }

            return $this->limitKeyDraw($request, $response, $fingerprint);
        }

        // Any other Bearer credential is an account token that resolves to no one:
        // revoked from Connected Apps, or its account deleted.
        if (BearerToken::from($authorization) !== null) {
            return $response->withJson(['error' => self::ACCOUNT_LINK_INVALID], 401);
        }

        $ip = $this->clientIp($request);
        if ($this->turnstile->verify((string) ($this->parsedBody($request)['turnstile_token'] ?? ''), $ip)) {
            return null;
        }

        // A plugin from before draw keys sends neither: tell its player what to do,
        // in the game, rather than about a captcha they never saw. Only the message
        // depends on the User-Agent; the refusal is the same.
        $error = str_starts_with($request->getHeaderLine('User-Agent'), 'TarotGenPlugin/')
            ? self::PLUGIN_NEEDS_KEY
            : self::CAPTCHA_FAILED;

        return $response->withJson(['error' => $error], 400);
    }

    /** The draw-key limits (see KEY_DRAWS_*): null within them, else the 429. Every attempt counts. */
    private function limitKeyDraw(Request $request, Response $response, string $fingerprint): ?ResponseInterface
    {
        $ip        = $this->clientIp($request);
        $perMinute = new RateLimiter('draw_key_minute', self::KEY_DRAWS_PER_MINUTE, self::RATE_WINDOW_SECONDS);
        $perDay    = new RateLimiter('draw_key_day', self::KEY_DRAWS_PER_DAY, self::DAY_SECONDS);
        $perIp     = new RateLimiter('draw_key_ip', self::KEY_DRAWS_PER_IP_MINUTE, self::RATE_WINDOW_SECONDS);

        if ($perMinute->isLimited($fingerprint) || $perDay->isLimited($fingerprint) || $perIp->isLimited($ip)) {
            return $response->withJson(['error' => self::KEY_LIMITED], 429);
        }

        $perMinute->hit($fingerprint);
        $perDay->hit($fingerprint);
        $perIp->hit($ip);

        return null;
    }

    /**
     * Whether the acting user is an admin, who may use a deck still awaiting
     * approval. A guest can't be, so the lookup is skipped for them; and it is
     * the admin routes' own check, on the session only (a plugin token, which
     * also gives a user id, never carries admin rights).
     */
    private function isAdmin(?int $userId): bool
    {
        return $userId !== null && $this->admin->sessionIsAdmin();
    }

    private function currentUserId(Request $request): ?int
    {
        // A linked plugin authenticates with a Bearer token; the browser with a
        // session. Either resolves the acting user (null for an anonymous guest).
        $viaToken = $this->pluginTokens->resolveBearer($request->getHeaderLine('Authorization'));
        if ($viaToken !== null) {
            return $viaToken;
        }

        Session::start();
        return Session::userId();
    }
}
