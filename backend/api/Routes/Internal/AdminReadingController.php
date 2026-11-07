<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\ReadingRepository;
use OpenApi\Spec as OAS;

/**
 * Stored readings: browsing, deleting one, and bulk-deleting old ones.
 */
class AdminReadingController extends AbstractController
{
    public function __construct(
        private readonly ReadingRepository $readings,
    ) {}

    #[OAS\Operation\Get(
        path: '/admin/readings',
        summary: 'List readings (paginated)',
        tags: ['Admin · Readings'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'limit', in: 'query', required: false, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'offset', in: 'query', required: false, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Paginated readings',
                content: new OAS\MediaType\Json(
                    required: ['rows', 'total'],
                    properties: [
                        new OAS\Property(property: 'rows', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/ReadingSummary'))),
                        new OAS\Property(property: 'total', schema: new OAS\Schema(type: 'integer', description: 'Every stored reading, across all pages.')),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function getReadings(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = max(1, (int) ($params['limit'] ?? 50));
        $offset = max(0, (int) ($params['offset'] ?? 0));

        return $response->withJson($this->readings->listAll($limit, $offset));
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/readings/{reading_id}',
        summary: 'Delete a single reading',
        tags: ['Admin · Readings'],
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

        if ($reading_id === '') {
            return $response->withJson(['error' => 'Invalid reading ID'], 400);
        }

        $deleted = $this->readings->delete($reading_id);

        if (!$deleted) {
            return $response->withJson(['error' => 'Reading not found'], 404);
        }

        return $response->withStatus(204);
    }

    /**
     * Bulk delete: purge guest readings older than the given age. The window is
     * an allow-listed number of days passed as the `older_than_days` query param.
     */
    #[OAS\Operation\Delete(
        path: '/admin/readings/all',
        summary: 'Purge guest readings older than a given age',
        tags: ['Admin · Readings'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(
                name: 'older_than_days',
                in: 'query',
                required: true,
                description: 'Allow-listed retention window in days',
                schema: new OAS\Schema(type: 'integer', enum: [7, 14, 30, 60, 90, 180, 365])
            ),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Number of deleted readings',
                content: new OAS\MediaType\Json(
                    required: ['deleted'],
                    properties: [new OAS\Property(property: 'deleted', schema: new OAS\Schema(type: 'integer'))]
                )
            ),
            new OAS\Response(response: 400, description: 'Invalid day range'),
        ]
    )]
    public function cleanReadings(Request $request, Response $response): Response|ResponseInterface
    {
        $days = (int) ($request->getQueryParams()['older_than_days'] ?? 0);

        $allowed = [7, 14, 30, 60, 90, 180, 365];
        if (!in_array($days, $allowed, true)) {
            return $response->withJson(['error' => 'Invalid day range.'], 400);
        }

        $deleted = $this->readings->cleanGuest($days);

        return $response->withJson(['deleted' => $deleted]);
    }
}
