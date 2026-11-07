<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\FavoriteSpreadRepository;
use Tarot\Repository\PendingSpreadRepository;
use Tarot\Repository\SpreadCategoryRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Structure\PendingSpread;
use Tarot\Structure\Spread;
use OpenApi\Spec as OAS;

/**
 * Public spreads, and the user submissions waiting to become one.
 */
class AdminSpreadController extends AbstractController
{
    private const string UNKNOWN_CATEGORY = 'Unknown spread category.';

    public function __construct(
        private readonly SpreadRepository $spreads,
        private readonly PendingSpreadRepository $pendingSpreads,
        private readonly FavoriteSpreadRepository $favorites,
        private readonly SpreadCategoryRepository $categories,
    ) {}

    #[OAS\Operation\Get(
        path: '/admin/spreads',
        summary: 'List all public spreads',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of spreads',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Spread'))
            ),
        ]
    )]
    public function getSpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->spreads->get());
    }

    /**
     * One spread for the admin editor: uncached, unlike GET /spreads/{id}, which
     * the browser keeps for five minutes and would hand back a spread as it was
     * before an edit.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Get(
        path: '/admin/spreads/{spread_id}',
        summary: 'Get a public spread',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The spread',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Spread')
            ),
            new OAS\Response(response: 404, description: 'Spread not found'),
        ]
    )]
    public function getSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spread = $this->spreads->get((int) ($args['spread_id'] ?? 0));

        if (!($spread instanceof Spread)) {
            return $response->withJson(['error' => 'Spread not found'], 404);
        }

        return $response->withJson($spread);
    }

    #[OAS\Operation\Post(
        path: '/admin/spreads',
        summary: 'Create a public spread',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'positions', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition'))),
                    new OAS\Property(property: 'category_id', schema: new OAS\Schema(description: 'Its library category; the first category when left out.', type: 'integer')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Created spread',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Spread')
            ),
            new OAS\Response(response: 400, description: 'Name is required, or an unknown category'),
            new OAS\Response(response: 500, description: 'Failed to create spread'),
        ]
    )]
    public function createSpread(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);

        if (trim((string) ($params['name'] ?? '')) === '') {
            return $response->withJson(['error' => 'Name is required'], 400);
        }

        if (!$this->knownCategory($params)) {
            return $response->withJson(['error' => self::UNKNOWN_CATEGORY], 400);
        }

        $spread = $this->spreads->create($params);

        if ($spread === null) {
            return $response->withJson(['error' => 'Failed to create spread'], 500);
        }

        return $response->withJson($spread, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Put(
        path: '/admin/spreads/{spread_id}',
        summary: 'Update a public spread',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'positions', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition'))),
                    new OAS\Property(property: 'category_id', schema: new OAS\Schema(description: 'Move it to another library category.', type: 'integer')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated spread',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Spread')
            ),
            new OAS\Response(response: 400, description: 'Name is required (a name sent blank), or an unknown category'),
            new OAS\Response(response: 404, description: 'Spread not found or no valid fields'),
        ]
    )]
    public function updateSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spread_id = (int) ($args['spread_id'] ?? 0);
        $params = $this->parsedBody($request);

        // A rename may not blank the name, any more than a create may leave it out.
        if (array_key_exists('name', $params) && trim((string) $params['name']) === '') {
            return $response->withJson(['error' => 'Name is required'], 400);
        }

        if (!$this->knownCategory($params)) {
            return $response->withJson(['error' => self::UNKNOWN_CATEGORY], 400);
        }

        $spread = $this->spreads->update($spread_id, $params);

        if ($spread === null) {
            return $response->withJson(['error' => 'Spread not found or no valid fields'], 404);
        }

        return $response->withJson($spread);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/spreads/{spread_id}',
        summary: 'Delete a public spread',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'spread_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
        ]
    )]
    public function deleteSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $spread_id = (int) ($args['spread_id'] ?? 0);

        $result = $this->spreads->delete($spread_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete spread'], 500);
        }

        // Remove any user favorites pointing to this deleted public spread.
        $this->favorites->removeBySpread('public', $spread_id);

        return $response->withStatus(204);
    }

    // ── Pending Spreads (user submissions) ──────────────────────

    #[OAS\Operation\Get(
        path: '/admin/pending-spreads',
        summary: 'List pending spread submissions',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Pending spread submissions',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/PendingSpread'))
            ),
        ]
    )]
    public function getPendingSpreads(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->pendingSpreads->get());
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/pending-spreads/{pending_id}/approve',
        summary: 'Approve a pending spread submission',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'pending_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: false,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'category_id', schema: new OAS\Schema(description: 'The library category to publish it in; the first category when left out.', type: 'integer')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Approved spread',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Spread')
            ),
            new OAS\Response(response: 400, description: 'Unknown category'),
            new OAS\Response(response: 404, description: 'Pending spread not found'),
            new OAS\Response(response: 500, description: 'Failed to approve spread'),
        ]
    )]
    public function approvePendingSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $pending_id = (int) ($args['pending_id'] ?? 0);

        if (!($this->pendingSpreads->get($pending_id) instanceof PendingSpread)) {
            return $response->withJson(['error' => 'Pending spread not found'], 404);
        }

        $params = $this->parsedBody($request);
        if (!$this->knownCategory($params)) {
            return $response->withJson(['error' => self::UNKNOWN_CATEGORY], 400);
        }

        $categoryId = $params['category_id'] ?? null;
        $spread = $this->pendingSpreads->approve(
            $pending_id,
            is_numeric($categoryId) ? (int) $categoryId : null
        );

        if ($spread === null) {
            return $response->withJson(['error' => 'Failed to approve spread'], 500);
        }

        return $response->withJson($spread, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/pending-spreads/{pending_id}',
        summary: 'Reject a pending spread submission',
        tags: ['Admin · Spreads'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'pending_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Rejected'),
            new OAS\Response(response: 500, description: 'Failed to reject spread'),
        ]
    )]
    public function rejectPendingSpread(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $pending_id = (int) ($args['pending_id'] ?? 0);

        $result = $this->pendingSpreads->reject($pending_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to reject spread'], 500);
        }

        return $response->withStatus(204);
    }

    /**
     * A category_id left out (or null) is fine: the spread keeps its category,
     * or a new one goes into the first. One that is sent must exist.
     *
     * @param array<string,mixed> $params
     */
    private function knownCategory(array $params): bool
    {
        $categoryId = $params['category_id'] ?? null;
        if ($categoryId === null) {
            return true;
        }

        return is_numeric($categoryId) && $this->categories->find((int) $categoryId) !== null;
    }
}
