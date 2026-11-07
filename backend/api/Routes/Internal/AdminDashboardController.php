<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Service\StatsService;
use OpenApi\Spec as OAS;

/**
 * The admin dashboard: usage stats, entity counts, and the session check the
 * admin pages make on load.
 */
class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly StatsService $stats,
    ) {}

    #[OAS\Operation\Get(
        path: '/admin/stats',
        summary: 'Usage statistics',
        tags: ['Admin · Dashboard'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Usage statistics',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/UsageStats')
            ),
        ]
    )]
    public function getStats(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->stats->overview());
    }

    /**
     * One-shot dashboard payload: entity counts (cheap COUNT(*) queries) plus the
     * usage stats overview — so the dashboard loads with a single request.
     */
    #[OAS\Operation\Get(
        path: '/admin/summary',
        summary: 'Dashboard counts + stats',
        tags: ['Admin · Dashboard'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Dashboard counts + stats',
                content: new OAS\MediaType\Json(
                    required: ['counts', 'stats'],
                    properties: [
                        new OAS\Property(property: 'counts', schema: new OAS\Schema(
                            description: 'Row counts for the dashboard tiles.',
                            type: 'object',
                            required: ['deckSystems', 'decks', 'specialCards', 'spreads', 'readings', 'users', 'unreadContacts', 'openCardReports', 'pendingSpreads', 'pendingDeckSystems'],
                            properties: [
                                new OAS\Property(property: 'deckSystems', schema: new OAS\Schema(description: 'Approved and pending.', type: 'integer')),
                                new OAS\Property(property: 'decks', schema: new OAS\Schema(description: 'Approved and pending.', type: 'integer')),
                                new OAS\Property(property: 'specialCards', schema: new OAS\Schema(type: 'integer')),
                                new OAS\Property(property: 'spreads', schema: new OAS\Schema(description: 'Public spreads.', type: 'integer')),
                                new OAS\Property(property: 'readings', schema: new OAS\Schema(type: 'integer')),
                                new OAS\Property(property: 'users', schema: new OAS\Schema(type: 'integer')),
                                new OAS\Property(property: 'unreadContacts', schema: new OAS\Schema(description: 'Contact messages not yet marked read.', type: 'integer')),
                                new OAS\Property(property: 'openCardReports', schema: new OAS\Schema(description: 'Reported cards not yet resolved (one per card).', type: 'integer')),
                                new OAS\Property(property: 'pendingSpreads', schema: new OAS\Schema(description: 'Spread submissions waiting for review.', type: 'integer')),
                                new OAS\Property(property: 'pendingDeckSystems', schema: new OAS\Schema(description: 'Deck system submissions waiting for review (also in deckSystems).', type: 'integer')),
                            ]
                        )),
                        new OAS\Property(property: 'stats', schema: new OAS\Schema(ref: '#/components/schemas/UsageStats')),
                    ]
                )
            ),
        ]
    )]
    public function getSummary(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson([
            'counts' => $this->stats->counts(),
            'stats'  => $this->stats->overview(),
        ]);
    }

    // ── Authentication ──────────────────────────────────────────
    // Admin access is gated by AdminAuth (an active is_admin user account);
    // logging in/out is handled by the regular auth endpoints (/api/auth/*).

    #[OAS\Operation\Get(
        path: '/admin/auth-check',
        summary: 'Verify the admin session',
        tags: ['Admin · Dashboard'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Admin session is valid',
                content: new OAS\MediaType\Json(required: ['authenticated'], properties: [new OAS\Property(property: 'authenticated', schema: new OAS\Schema(type: 'boolean'))])
            ),
        ]
    )]
    public function checkAuth(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson(['authenticated' => true]);
    }
}
