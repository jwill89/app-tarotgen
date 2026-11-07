<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\CardReportRepository;
use Tarot\Repository\DeckRepository;
use Tarot\Service\CardImageService;
use Tarot\Structure\Deck;
use OpenApi\Spec as OAS;

/**
 * Card image problems reported from the lightbox, and their resolution.
 */
class AdminCardReportController extends AbstractController
{
    public function __construct(
        private readonly CardReportRepository $cardReports,
        private readonly DeckRepository $decks,
        private readonly CardImageService $cardImages,
    ) {}

    #[OAS\Operation\Get(
        path: '/admin/card-reports',
        summary: 'List reported cards with their reasons (open first; pass ?show_resolved=1 for all)',
        tags: ['Admin · Card Reports'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(
                name: 'show_resolved',
                in: 'query',
                required: false,
                schema: new OAS\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of card reports',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/CardReport'))
            ),
        ]
    )]
    public function getCardReports(Request $request, Response $response): Response|ResponseInterface
    {
        $includeResolved = ($request->getQueryParams()['show_resolved'] ?? '0') === '1';

        return $response->withJson($this->cardReports->get($includeResolved));
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Patch(
        path: '/admin/card-reports/{report_id}',
        summary: 'Mark a card report resolved / reopened',
        tags: ['Admin · Card Reports'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'report_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(properties: [new OAS\Property(property: 'resolved', schema: new OAS\Schema(type: 'boolean'))])
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated',
                content: new OAS\MediaType\Json(required: ['success'], properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))])
            ),
            new OAS\Response(response: 400, description: 'Invalid report ID'),
            new OAS\Response(response: 404, description: 'Report not found'),
        ]
    )]
    public function updateCardReport(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reportId = (int) ($args['report_id'] ?? 0);

        if ($reportId < 1) {
            return $response->withJson(['error' => 'Invalid report ID'], 400);
        }

        if (!$this->cardReports->exists($reportId)) {
            return $response->withJson(['error' => 'Report not found'], 404);
        }

        $params = $this->parsedBody($request);

        if (array_key_exists('resolved', $params)) {
            $this->cardReports->setResolved($reportId, (bool) $params['resolved']);
        }

        return $response->withJson(['success' => true]);
    }

    /**
     * The last step of fixing a reported card, once its new master scan is on
     * the server: rebuild that one card's web images and, only if that worked,
     * resolve the report. The images are rebuilt even when they look current,
     * since an upload may keep the old scan's timestamp.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/card-reports/{report_id}/regenerate',
        summary: "Rebuild a reported card's web images from its replaced master scan, then resolve the report",
        tags: ['Admin · Card Reports'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'report_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Images rebuilt and the report resolved',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/CardReport')
            ),
            new OAS\Response(response: 400, description: 'Invalid report ID'),
            new OAS\Response(response: 404, description: 'Report (or its deck) not found'),
            new OAS\Response(response: 409, description: 'Already resolved, or the card has no master scan on the server'),
            new OAS\Response(response: 500, description: 'The scan could not be turned into web images; the report stays open'),
        ]
    )]
    public function regenerateCard(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $reportId = (int) ($args['report_id'] ?? 0);

        if ($reportId < 1) {
            return $response->withJson(['error' => 'Invalid report ID'], 400);
        }

        $report = $this->cardReports->find($reportId);

        if ($report === null) {
            return $response->withJson(['error' => 'Report not found'], 404);
        }

        // Reports from before decks were foreign-keyed can outlive their deck.
        if (!$this->decks->get($report->deck_id) instanceof Deck) {
            return $response->withJson(['error' => 'Deck not found'], 404);
        }

        // Someone already closed it (another tab, a second click): resolving it
        // again would only move its date, so say so rather than claim a new fix.
        if ($report->resolved_at !== null) {
            return $response->withJson(['error' => 'This report is already resolved.'], 409);
        }

        $scan = CardImageService::scanFileName($report->card_id);

        if (!$this->cardImages->hasScan($report->deck_id, $report->card_id)) {
            return $response->withJson(
                ['error' => "There is no master scan for this card on the server. Upload {$scan} to deck {$report->deck_id}'s folder, then try again."],
                409
            );
        }

        if (!$this->cardImages->regenerateCard($report->deck_id, $report->card_id)) {
            return $response->withJson(
                ['error' => "Couldn't make web images from {$scan}; is it a valid PNG? The report is still open."],
                500
            );
        }

        $this->cardReports->setResolved($reportId, true);

        // Deleting its deck in the meantime would have taken the report with it.
        $resolved = $this->cardReports->find($reportId);

        if ($resolved === null) {
            return $response->withJson(['error' => 'Report not found'], 404);
        }

        return $response->withJson($resolved);
    }
}
