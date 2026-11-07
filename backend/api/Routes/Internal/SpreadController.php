<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\SpreadCopyService;
use Tarot\Structure\Spread;
use Tarot\Structure\UserSpread;
use Tarot\Utility\RateLimiter;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

class SpreadController extends AbstractController
{
    // Public submissions: max 5 per IP per hour.
    private const int SUBMIT_MAX_PER_WINDOW = 5;
    private const int SUBMIT_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly SpreadRepository $spreads,
        private readonly PendingSpreadRepository $pending,
        private readonly UserRepository $users,
        private readonly SpreadCopyService $copies,
    ) {}

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Get(
        path: '/spreads',
        summary: 'List all public spreads',
        tags: ['Spreads'],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of spreads',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Spread'))
            ),
        ]
    )]
    #[OAS\Operation\Get(
        path: '/spreads/{spread_id}',
        summary: 'A single public spread',
        tags: ['Spreads'],
        parameters: [
            new OAS\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The spread',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Spread')
            ),
            new OAS\Response(response: 404, description: 'InvalidSpreadID'),
        ]
    )]
    public function getSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spread_id = $args['spread_id'] ?? null;

        $status = 200;
        $data   = $this->spreads->get($spread_id !== null ? (int) $spread_id : null);

        if ($spread_id !== null && !($data instanceof Spread)) {
            $data   = ['error' => 'InvalidSpreadID'];
            $status = 404;
        }

        $response = $response->withJson($data, $status);

        if ($status === 200) {
            $response = $response->withHeader('Cache-Control', 'public, max-age=300');
        }

        return $response;
    }

    #[OAS\Operation\Post(
        path: '/spreads',
        summary: 'Submit a spread into the moderation queue (rate-limited: 5/IP/hour)',
        tags: ['Spreads'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name', 'positions'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'description', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'positions', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition'))),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Accepted into the pending queue',
                content: new OAS\MediaType\Json(required: ['success'], properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
            new OAS\Response(response: 400, description: 'Validation failure'),
            new OAS\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    /**
     * Public endpoint: accept a user-submitted spread into the pending queue.
     * Rate-limited per IP to prevent spam. Never exposes the stored row.
     */
    public function submitSpread(Request $request, Response $response): Response|ResponseInterface
    {
        $ip = $this->clientIp($request);

        $limiter = new RateLimiter('spread_submit', self::SUBMIT_MAX_PER_WINDOW, self::SUBMIT_WINDOW_SECONDS);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(
                ['error' => 'You have submitted too many spreads recently. Please try again later.'],
                429
            );
        }

        $params    = $this->parsedBody($request);
        $name      = trim((string) ($params['name'] ?? ''));
        $positions = $params['positions'] ?? [];

        if ($name === '' || !is_array($positions) || count($positions) === 0) {
            return $response->withJson(
                ['error' => 'A spread name and at least one card position are required.'],
                400
            );
        }

        $created = $this->pending->create($params, $this->currentUserId());

        if ($created === null) {
            return $response->withJson(['error' => 'Failed to submit the spread. Please try again.'], 500);
        }

        // Only count successful submissions against the limit.
        $limiter->hit($ip);

        return $response->withJson(['success' => true], 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/spreads/{spread_id}/copy',
        summary: "Copy a public spread into the signed-in user's own spreads (My Spreads)",
        description: 'The copy keeps the description and every position (with its description); its name gets " (copy)", or " (copy 2)" and on when the user already has that name. Only a published spread can be copied.',
        tags: ['Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 201,
                description: 'The new personal spread',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/UserSpread')
            ),
            new OAS\Response(response: 401, description: 'Not signed in'),
            new OAS\Response(response: 404, description: 'No such public spread'),
        ]
    )]
    public function copySpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $copy = $this->copies->copyToAccount((int) ($args['spread_id'] ?? 0), $this->authUserId($request));

        if (!($copy instanceof UserSpread)) {
            return $response->withJson(['error' => 'Spread not found.'], 404);
        }

        return $response->withJson($copy, 201);
    }

    /**
     * The logged-in user's id, or null for a guest submission. A deleted account
     * leaves its sessions behind, and its id would fail the foreign key: that
     * submission goes in as a guest's rather than being lost to a 500.
     */
    private function currentUserId(): ?int
    {
        Session::start();
        $userId = Session::userId();

        return $userId !== null && $this->users->findById($userId) !== null ? $userId : null;
    }
}
