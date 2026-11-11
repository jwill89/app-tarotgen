<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Exception\ApiException;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\FavoriteDeckRepository;
use Tarot\Repository\FavoriteSpreadRepository;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\UserRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Service\AuthService;
use Tarot\Service\JournalExport;
use Tarot\Service\ReadingInput;
use Tarot\Structure\Deck;
use Tarot\Utility\Clock;
use Tarot\Utility\Input;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

/**
 * A signed-in user's self-service area (behind UserAuth): their readings,
 * per-reading options, spreads, account settings, and account deletion.
 */
class AccountController extends AbstractController
{
    /** Largest page GET /account/readings serves when ?limit is given. */
    private const int READINGS_PAGE_MAX = 100;

    public function __construct(
        private readonly ReadingRepository $readings,
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly UserSpreadRepository $userSpreads,
        private readonly PendingSpreadRepository $pendingSpreads,
        private readonly FavoriteSpreadRepository $favorites,
        private readonly FavoriteDeckRepository $favoriteDecks,
        private readonly DeckRepository $decks,
        private readonly JournalExport $journal,
    ) {}

    #[OAS\Operation\Get(
        path: '/account/readings',
        summary: "The current user's readings, newest first (one page with ?limit, else all), optionally filtered",
        description: 'Every filter is optional and they combine; a blank one is no filter.',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie'), new OAS\Security\Requirement(scheme: 'pluginToken')],
        parameters: [
            new OAS\Parameter(
                name: 'limit',
                in: 'query',
                required: false,
                description: 'Page size (1–' . self::READINGS_PAGE_MAX . '). Omit to get every reading.',
                schema: new OAS\Schema(type: 'integer')
            ),
            new OAS\Parameter(name: 'offset', in: 'query', required: false, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'q', in: 'query', required: false, description: 'Text in the name, notes, question or querent (ignoring case for unaccented letters).', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'deck_id', in: 'query', required: false, description: 'Drawn from this deck.', schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'spread', in: 'query', required: false, description: 'Laid out in a spread of this name, as the reading recorded it (personal spreads have no public id); "Free Draw" also matches a free draw never placed.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'tag', in: 'query', required: false, description: 'Carrying this tag.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'from', in: 'query', required: false, description: 'Made on or after this day (YYYY-MM-DD) in `tz`.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'to', in: 'query', required: false, description: 'Made on or before this day (YYYY-MM-DD) in `tz`.', schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'tz', in: 'query', required: false, description: "The reader's time zone for `from` and `to` (IANA, e.g. Europe/London); UTC when absent.", schema: new OAS\Schema(type: 'string')),
            new OAS\Parameter(name: 'daily', in: 'query', required: false, description: 'only: daily cards alone; exclude: everything but.', schema: new OAS\Schema(type: 'string', enum: ['only', 'exclude'])),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of readings',
                headers: [
                    new OAS\Header(
                        header: 'X-Total-Count',
                        description: "How many of the user's readings the filters match (sent when paging with ?limit)",
                        schema: new OAS\Schema(type: 'integer')
                    ),
                ],
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Reading'))
            ),
            new OAS\Response(response: 400, description: 'A filter that cannot be one (a bad date, deck id, time zone or daily value; from after to)'),
        ]
    )]
    /**
     * The current user's readings, newest first. The site pages with ?limit and
     * reads the total from X-Total-Count; without ?limit every reading comes back
     * as a plain array, the shape the Dalamud plugin's List<Reading> expects (it
     * doesn't page).
     */
    public function myReadings(Request $request, Response $response): Response|ResponseInterface
    {
        $userId = $this->userId($request);
        $params = $request->getQueryParams();

        try {
            $filter = ReadingInput::filter($params);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }

        if (!isset($params['limit'])) {
            return $response->withJson($this->readings->listByUser($userId, filter: $filter));
        }

        $limit  = min(self::READINGS_PAGE_MAX, max(1, (int) $params['limit']));
        $offset = max(0, (int) ($params['offset'] ?? 0));

        return $response
            ->withJson($this->readings->listByUser($userId, $limit, $offset, $filter))
            ->withHeader('X-Total-Count', (string) $this->readings->countByUser($userId, $filter));
    }

    #[OAS\Operation\Get(
        path: '/account/tags',
        summary: "The tags on the user's readings, with how many readings carry each",
        description: 'Alphabetical. For My Readings\' tag filter and tag suggestions while writing one.',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of tags',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/ReadingTag'))
            ),
        ]
    )]
    public function myTags(Request $request, Response $response): Response|ResponseInterface
    {
        return $response
            ->withJson($this->readings->tagCounts($this->userId($request)))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    #[OAS\Operation\Get(
        path: '/account/reading-spreads',
        summary: "The spreads the user's readings were laid out in, with how many readings each",
        description: 'Alphabetical, by the name the readings recorded; names that differ only in case are one spread. Each is a value for GET /account/readings?spread= that finds exactly that many readings, so My Readings\' spread filter never offers a spread with none.',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of spreads',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/ReadingSpread'))
            ),
        ]
    )]
    public function myReadingSpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response
            ->withJson($this->readings->spreadCounts($this->userId($request)))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Update a reading the current user owns: name, notes, hidden author, the
     * journal fields, and who can open it.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Patch(
        path: '/account/readings/{reading_id}',
        summary: "Update a reading's name, notes, hidden-author flag, journal fields, or who can open it",
        description: 'Only the fields sent change. `visibility`: link (anyone with the link) and private (only you) '
            . 'drop any password; password needs `password` unless the reading has one already. Without '
            . '`visibility`, `password` sets a password (and makes it a password reading) and `remove_password` drops it.',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OAS\Schema(type: 'string')),
        ],
        requestBody: new OAS\RequestBody(
            content: new OAS\MediaType\Json(properties: [
                new OAS\Property(property: 'reading_name', schema: new OAS\Schema(type: 'string', nullable: true)),
                new OAS\Property(property: 'reading_notes', schema: new OAS\Schema(type: 'string', nullable: true)),
                new OAS\Property(property: 'question', schema: new OAS\Schema(description: 'Up to ' . ReadingInput::QUESTION_MAX . ' characters; blank or null clears it.', type: 'string', nullable: true)),
                new OAS\Property(property: 'querent', schema: new OAS\Schema(description: 'Up to ' . ReadingInput::QUERENT_MAX . ' characters; blank or null clears it.', type: 'string', nullable: true)),
                new OAS\Property(property: 'tags', schema: new OAS\Schema(description: 'Replaces every tag: at most ' . ReadingInput::TAGS_MAX . ', of at most ' . ReadingInput::TAG_MAX . ' characters each; stored lower-case, a leading # dropped, repeats merged. [] clears them.', type: 'array', items: new OAS\Schema\Items(type: 'string'))),
                new OAS\Property(property: 'visibility', schema: new OAS\Schema(type: 'string', enum: ['link', 'password', 'private'])),
                new OAS\Property(property: 'hide_user', schema: new OAS\Schema(type: 'boolean')),
                new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'remove_password', schema: new OAS\Schema(type: 'boolean')),
            ])
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The updated reading',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Reading')
            ),
            new OAS\Response(response: 400, description: 'No changes provided, an unknown visibility, or tags over the limits'),
            new OAS\Response(response: 404, description: 'Reading not found'),
            new OAS\Response(response: 422, description: 'Password too short, or Password chosen without one'),
        ]
    )]
    public function updateReadingMeta(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = $args['reading_id'] ?? '';
        $userId     = $this->userId($request);
        $body       = $this->parsedBody($request);
        $fields     = [];

        // Read first: whether a password is already set decides what Password
        // without a new one means, and someone else's reading is a 404 either way.
        $reading = $this->readings->get($reading_id);
        if ($reading === null || !$reading->isOwnedBy($userId)) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        if (array_key_exists('reading_name', $body)) {
            $fields['reading_name'] = Input::nullableString($body['reading_name'], 100);
        }

        if (array_key_exists('reading_notes', $body)) {
            // Notes keep internal whitespace (only the empty check is trimmed).
            $fields['reading_notes'] = Input::nullableString($body['reading_notes'], 20000, trim: false);
        }

        if (array_key_exists('hide_user', $body)) {
            $fields['hide_user'] = Input::bool($body['hide_user']);
        }

        if (array_key_exists('question', $body)) {
            $fields['question'] = ReadingInput::question($body['question']);
        }

        if (array_key_exists('querent', $body)) {
            $fields['querent'] = ReadingInput::querent($body['querent']);
        }

        try {
            if (array_key_exists('tags', $body)) {
                $fields['tags'] = ReadingInput::tags($body['tags']);
            }
            $fields += ReadingInput::access($body, $reading->password_protected, 422);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }

        if ($fields === []) {
            return $response->withJson(['error' => 'No changes provided.'], 400);
        }

        $updated = $this->readings->updateMeta($reading_id, $userId, $fields);
        if ($updated === null) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        return $response->withJson($updated);
    }

    /**
     * Delete one of the current user's readings.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/account/readings/{reading_id}',
        summary: 'Delete one of the user\'s readings',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'reading_id', in: 'path', required: true, schema: new OAS\Schema(type: 'string')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'Reading not found'),
        ]
    )]
    public function deleteReading(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reading_id = $args['reading_id'] ?? '';

        if (!$this->readings->deleteOwned($reading_id, $this->userId($request))) {
            return $response->withJson(['error' => 'Reading not found.'], 404);
        }

        return $response->withStatus(204);
    }

    #[OAS\Operation\Get(
        path: '/account/readings/export',
        summary: "Download the user's whole journal, every reading, as JSON or CSV",
        description: 'A file download (Content-Disposition: attachment), newest reading first. JSON keeps everything: the spread snapshot with its positions, the cards, the notes, the question, querent and tags, who can open it, and the flags (never a view password). CSV has one row per drawn card, so a reading\'s name, journal fields and notes repeat on each of its rows; it is UTF-8 with a byte-order mark (for Excel), and a cell that starts like a formula (= + - @, tab, CR) is prefixed with an apostrophe.',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(
                name: 'format',
                in: 'query',
                required: false,
                description: 'json (the default) or csv.',
                schema: new OAS\Schema(type: 'string', enum: ['json', 'csv'])
            ),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The journal',
                headers: [
                    new OAS\Header(
                        header: 'Content-Disposition',
                        description: 'attachment; filename="tarotgen-journal-{Y-m-d}.json" (or .csv), dated in UTC',
                        schema: new OAS\Schema(type: 'string')
                    ),
                ],
                content: [
                    new OAS\MediaType\Json(ref: '#/components/schemas/JournalExport'),
                    new OAS\MediaType(
                        mediaType: 'text/csv',
                        schema: new OAS\Schema(
                            description: 'Columns: Reading ID, Reading Time (UTC), Reading Name, Question, Querent, Tags (comma-separated), Privacy (Anyone with the link, Password or Private), Deck, Spread, Position, Position Title, Card, Reversed (Yes/No), Notes.',
                            type: 'string'
                        )
                    ),
                ]
            ),
            new OAS\Response(response: 400, description: 'Unknown format'),
        ]
    )]
    public function exportReadings(Request $request, Response $response): Response|ResponseInterface
    {
        $format = $request->getQueryParams()['format'] ?? 'json';
        if ($format !== 'json' && $format !== 'csv') {
            return $response->withJson(['error' => 'Choose an export format: json or csv.'], 400);
        }

        $userId = $this->userId($request);
        $body   = $response->getBody();
        foreach ($format === 'csv' ? $this->journal->csv($userId) : $this->journal->json($userId) as $chunk) {
            $body->write($chunk);
        }

        return $response
            ->withHeader('Content-Type', $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="tarotgen-journal-' . Clock::today() . '.' . $format . '"')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Partial update of the current account's profile. The display name is the
     * only editable field.
     */
    #[OAS\Operation\Patch(
        path: '/account',
        summary: 'Update the account profile (currently the display name)',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            content: new OAS\MediaType\Json(properties: [new OAS\Property(property: 'display_name', schema: new OAS\Schema(type: 'string'))])
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The updated account',
                content: new OAS\MediaType\Json(required: ['success', 'user'], properties: [
                    new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                    new OAS\Property(property: 'user', schema: new OAS\Schema(ref: '#/components/schemas/User')),
                ])
            ),
            new OAS\Response(response: 422, description: 'Policy/uniqueness failure'),
        ]
    )]
    public function updateProfile(Request $request, Response $response): Response|ResponseInterface
    {
        $body = $this->parsedBody($request);

        if (array_key_exists('display_name', $body)) {
            $result = $this->auth->changeDisplayName($this->userId($request), (string) $body['display_name']);
            if (!$result['ok']) {
                return $response->withJson(['error' => $result['error'] ?? 'Could not update display name.'], 422);
            }
        }

        return $response->withJson(['success' => true, 'user' => $this->users->findById($this->userId($request))]);
    }

    #[OAS\Operation\Post(
        path: '/account/change-password',
        summary: 'Change the account password',
        description: "Ends every other session of the account; the browser making the change stays signed in, under a new session id. Linked plugins keep their tokens (they're revoked under Connected Apps).",
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['current_password', 'new_password'],
                properties: [
                    new OAS\Property(property: 'current_password', schema: new OAS\Schema(type: 'string', format: 'password')),
                    new OAS\Property(property: 'new_password', schema: new OAS\Schema(type: 'string', format: 'password')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Password updated',
                content: new OAS\MediaType\Json(
                    required: ['success', 'message'],
                    properties: [
                        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                        new OAS\Property(property: 'message', schema: new OAS\Schema(description: 'A confirmation to show the user.', type: 'string')),
                    ]
                )
            ),
            new OAS\Response(response: 422, description: 'Wrong current password or weak new password'),
        ]
    )]
    public function changePassword(Request $request, Response $response): Response|ResponseInterface
    {
        $body    = $this->parsedBody($request);
        $current = (string) ($body['current_password'] ?? '');
        $new     = (string) ($body['new_password'] ?? '');

        $result = $this->auth->changePassword($this->userId($request), $current, $new, Session::id());
        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not change password.'], 422);
        }

        // Every other session just ended; this one carries on under a new id, so
        // a copy of its old cookie is no good either.
        Session::rotate();

        return $response->withJson(['success' => true, 'message' => 'Your password has been updated.']);
    }

    #[OAS\Operation\Post(
        path: '/account/sessions/end-others',
        summary: 'Sign out of every other device',
        description: "Ends every session of the account but the browser asking, which stays signed in under a new session id, so a copy of its old cookie is signed out too. Linked plugins keep their tokens (they're revoked under Connected Apps).",
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The other sessions ended',
                content: new OAS\MediaType\Json(
                    required: ['success', 'ended'],
                    properties: [
                        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                        new OAS\Property(property: 'ended', schema: new OAS\Schema(description: 'How many other sessions were signed out; 0 when none was.', type: 'integer')),
                    ]
                )
            ),
        ]
    )]
    public function endOtherSessions(Request $request, Response $response): Response|ResponseInterface
    {
        $ended = $this->auth->endOtherSessions($this->userId($request), Session::id());

        // Rotated as after a password change: when it's this browser's cookie that
        // was copied, "everywhere else" has to include the copy.
        Session::rotate();

        return $response->withJson(['success' => true, 'ended' => $ended]);
    }

    #[OAS\Operation\Delete(
        path: '/account',
        summary: 'Delete the account after re-entering the password (cascades readings)',
        description: 'Ends every session of the account. An account without a password (made by signing in with Google) sets one first, through a password-reset link. A protected account (PROTECTED_ADMIN_EMAILS) cannot be deleted.',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['password'], properties: [new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', format: 'password'))])
        ),
        responses: [
            new OAS\Response(response: 204, description: 'Account deleted'),
            new OAS\Response(response: 403, description: 'Wrong password, or a protected account'),
        ]
    )]
    public function deleteAccount(Request $request, Response $response): Response|ResponseInterface
    {
        $password = (string) (($this->parsedBody($request))['password'] ?? '');

        $result = $this->auth->deleteAccount($this->userId($request), $password);
        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not delete account.'], 403);
        }

        // End the session, whose account is gone.
        unset($_SESSION['user_id']);
        Session::regenerate();

        return $response->withStatus(204);
    }

    // ── User Spreads ─────────────────────────────────────────────────────────

    #[OAS\Operation\Get(
        path: '/account/spreads',
        summary: "The user's personal spreads",
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of personal spreads',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/UserSpread'))
            ),
        ]
    )]
    /** List all of the current user's personal spreads. */
    public function mySpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->userSpreads->listByUser($this->userId($request)));
    }

    #[OAS\Operation\Post(
        path: '/account/spreads',
        summary: 'Create a personal spread',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name', 'positions'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'positions', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition'))),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'The created spread (card_count is the number of positions)',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/UserSpread')
            ),
            new OAS\Response(response: 400, description: 'Name and positions required, or a position that is not an object'),
        ]
    )]
    /** Create a new personal spread. */
    public function createSpread(Request $request, Response $response): Response|ResponseInterface
    {
        $params    = $this->parsedBody($request);
        $name      = trim((string) ($params['name'] ?? ''));
        $positions = $params['positions'] ?? [];

        if ($name === '' || !is_array($positions) || count($positions) === 0) {
            return $response->withJson(
                ['error' => 'A spread name and at least one card position are required.'],
                400
            );
        }

        if (self::hasNonObjectPosition($positions)) {
            return $response->withJson(['error' => 'Invalid position data.'], 400);
        }

        $created = $this->userSpreads->create($this->userId($request), $params);

        if ($created === null) {
            return $response->withJson(['error' => 'Failed to save the spread.'], 500);
        }

        return $response->withJson($created, 201);
    }

    /**
     * Update one of the current user's personal spreads.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Put(
        path: '/account/spreads/{user_spread_id}',
        summary: 'Update a personal spread',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(content: new OAS\MediaType\Json(type: 'object')),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The updated spread (card_count is the number of positions)',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/UserSpread')
            ),
            new OAS\Response(response: 400, description: 'No changes provided, a blank name, or positions that are empty or not objects'),
            new OAS\Response(response: 404, description: 'Spread not found'),
        ]
    )]
    public function updateSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadId = (int) ($args['user_spread_id'] ?? 0);
        $changes  = array_intersect_key($this->parsedBody($request), array_flip(['name', 'description', 'positions']));

        if ($changes === []) {
            return $response->withJson(['error' => 'No changes provided.'], 400);
        }

        // Saved trimmed, so a blank name would leave the spread nameless — which
        // creating one refuses.
        if (array_key_exists('name', $changes) && (!is_scalar($changes['name']) || trim((string) $changes['name']) === '')) {
            return $response->withJson(['error' => 'A spread needs a name.'], 400);
        }

        if (array_key_exists('positions', $changes)) {
            $positions = $changes['positions'];
            if (!is_array($positions) || $positions === []) {
                return $response->withJson(['error' => 'A spread needs at least one card position.'], 400);
            }
            if (self::hasNonObjectPosition($positions)) {
                return $response->withJson(['error' => 'Invalid position data.'], 400);
            }
        }

        $updated = $this->userSpreads->update($this->userId($request), $spreadId, $changes);
        if ($updated === null) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        return $response->withJson($updated);
    }

    /**
     * Delete one of the current user's personal spreads.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/account/spreads/{user_spread_id}',
        summary: 'Delete a personal spread (and any favorites pointing to it)',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'Spread not found'),
        ]
    )]
    public function deleteSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadId = (int) ($args['user_spread_id'] ?? 0);

        if (!$this->userSpreads->delete($this->userId($request), $spreadId)) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        // Remove any favorites pointing to this deleted personal spread.
        $this->favorites->removeBySpread('personal', $spreadId);

        return $response->withStatus(204);
    }

    /**
     * Submit one of the user's personal spreads as a public spread (copies into
     * pending queue; the personal copy remains untouched).
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/account/spreads/{user_spread_id}/submit',
        summary: 'Submit a personal spread to the public pending queue',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Submitted for review',
                content: new OAS\MediaType\Json(required: ['success'], properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
            new OAS\Response(response: 404, description: 'Spread not found'),
        ]
    )]
    public function submitSpreadAsPublic(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadId = (int) ($args['user_spread_id'] ?? 0);
        $spread   = $this->userSpreads->get($this->userId($request), $spreadId);

        if ($spread === null) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        $pending = $this->pendingSpreads->create([
            'name'        => $spread->name,
            'description' => $spread->description,
            'card_count'  => $spread->card_count,
            'positions'   => $spread->positions,
        ], $this->userId($request));

        if ($pending === null) {
            return $response->withJson(['error' => 'Failed to submit the spread for review.'], 500);
        }

        return $response->withJson(['success' => true], 201);
    }

    // ── Favorite Spreads ────────────────────────────────────────────────────

    #[OAS\Operation\Get(
        path: '/account/favorites',
        summary: 'Favorited spreads',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [new OAS\Response(response: 200, description: 'Array of favorited spreads', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/FavoriteSpread')))]
    )]
    /** List the current user's favorited spreads. */
    public function myFavorites(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->favorites->listByUser($this->userId($request)));
    }

    #[OAS\Operation\Post(
        path: '/account/favorites',
        summary: 'Add a favorite spread',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['spread_type', 'spread_id'],
                properties: [
                    new OAS\Property(property: 'spread_type', schema: new OAS\Schema(type: 'string', enum: ['public', 'personal'])),
                    new OAS\Property(property: 'spread_id', schema: new OAS\Schema(type: 'integer')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Added (or already a favorite)',
                content: new OAS\MediaType\Json(required: ['success'], properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
            new OAS\Response(response: 400, description: 'Invalid spread type or ID'),
        ]
    )]
    /** Add a spread to the current user's favorites. */
    public function addFavorite(Request $request, Response $response): Response|ResponseInterface
    {
        $params     = $this->parsedBody($request);
        $spreadType = (string) ($params['spread_type'] ?? '');
        $spreadId   = (int) ($params['spread_id'] ?? 0);

        if (!in_array($spreadType, ['public', 'personal'], true) || $spreadId < 1) {
            return $response->withJson(['error' => 'Invalid spread type or ID.'], 400);
        }

        $this->favorites->add($this->userId($request), $spreadType, $spreadId);

        return $response->withJson(['success' => true], 201);
    }

    /**
     * Remove a spread from the current user's favorites. The favorite is
     * identified by its type and id in the path.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/account/favorites/{spread_type}/{spread_id}',
        summary: 'Remove a favorite spread',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'spread_type', in: 'path', required: true, schema: new OAS\Schema(type: 'string', enum: ['public', 'personal'])),
            new OAS\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Removed'),
            new OAS\Response(response: 400, description: 'Invalid spread type or ID'),
        ]
    )]
    public function removeFavorite(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spreadType = $args['spread_type'] ?? '';
        $spreadId   = (int) ($args['spread_id'] ?? 0);

        if (!in_array($spreadType, ['public', 'personal'], true) || $spreadId < 1) {
            return $response->withJson(['error' => 'Invalid spread type or ID.'], 400);
        }

        $this->favorites->remove($this->userId($request), $spreadType, $spreadId);

        return $response->withStatus(204);
    }

    // ── Favorite Decks ───────────────────────────────────────────

    #[OAS\Operation\Get(
        path: '/account/favorite-decks',
        summary: 'Favorited decks',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The ids of the favorited decks, oldest favorite first',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(type: 'integer'))
            ),
        ]
    )]
    public function myFavoriteDecks(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->favoriteDecks->listByUser($this->userId($request)));
    }

    #[OAS\Operation\Post(
        path: '/account/favorite-decks',
        summary: 'Add a favorite deck',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['deck_id'], properties: [new OAS\Property(property: 'deck_id', schema: new OAS\Schema(type: 'integer'))])
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Added (or already a favorite)',
                content: new OAS\MediaType\Json(required: ['success'], properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
            new OAS\Response(response: 400, description: 'Invalid deck ID: no such deck, or one not yet approved'),
        ]
    )]
    public function addFavoriteDeck(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        $deckId = (int) ($params['deck_id'] ?? 0);

        // An unknown deck (e.g. one deleted while the page was open) would fail the
        // favourite's foreign key as a 500. A pending submission
        // gets the same answer: only admins may learn that its id exists.
        $deck = $deckId < 1 ? null : $this->decks->get($deckId);
        if (!$deck instanceof Deck || !$deck->approved) {
            return $response->withJson(['error' => 'Invalid deck ID.'], 400);
        }

        $this->favoriteDecks->add($this->userId($request), $deckId);

        return $response->withJson(['success' => true], 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/account/favorite-decks/{deck_id}',
        summary: 'Remove a favorite deck',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Removed'),
            new OAS\Response(response: 400, description: 'Invalid deck ID'),
        ]
    )]
    public function removeFavoriteDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deckId = (int) ($args['deck_id'] ?? 0);

        if ($deckId < 1) {
            return $response->withJson(['error' => 'Invalid deck ID.'], 400);
        }

        $this->favoriteDecks->remove($this->userId($request), $deckId);

        return $response->withStatus(204);
    }

    /**
     * A spread position is an object; anything else in the list would be dropped
     * on save, leaving a spread with fewer cards (or none) than the client sent.
     *
     * @param array<mixed> $positions
     */
    private static function hasNonObjectPosition(array $positions): bool
    {
        return array_any($positions, static fn(mixed $position): bool => !is_array($position));
    }

    private function userId(Request $request): int
    {
        // Set by AccountAuth (session or plugin token) or UserAuth (session).
        $id = $request->getAttribute('auth_user_id');
        if (is_int($id) && $id > 0) {
            return $id;
        }

        Session::start();
        return Session::userId() ?? 0;
    }
}
