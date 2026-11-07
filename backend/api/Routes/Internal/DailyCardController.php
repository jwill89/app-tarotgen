<?php

namespace Routes\Internal;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Random\RandomException;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Exception\ApiException;
use Tarot\Exception\DailyCardTakenException;
use Tarot\Service\DailyCardService;
use OpenApi\Spec as OAS;

/**
 * The signed-in user's daily card (behind UserAuth): the month calendar of past
 * draws, and drawing the card for their day.
 */
class DailyCardController extends AbstractController
{
    public function __construct(private readonly DailyCardService $dailyCards) {}

    #[OAS\Operation\Get(
        path: '/account/daily-cards',
        summary: "The user's daily cards in one month, for the calendar",
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'month', in: 'query', required: true, description: 'YYYY-MM', schema: new OAS\Schema(type: 'string')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'One per day drawn, oldest first; a day without a draw is left out; a day whose reading was deleted is still listed, its reading and card null',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/DailyCard'))
            ),
            new OAS\Response(response: 400, description: 'month is missing or not YYYY-MM'),
            new OAS\Response(response: 401, description: 'Not signed in'),
        ]
    )]
    public function list(Request $request, Response $response): Response|ResponseInterface
    {
        try {
            $cards = $this->dailyCards->month($this->authUserId($request), $request->getQueryParams()['month'] ?? null);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $response->withJson($cards)->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @throws RandomException
     * @throws JsonException
     */
    #[OAS\Operation\Post(
        path: '/account/daily-cards',
        summary: "Draw the user's daily card for their day",
        description: 'A one-card reading from the chosen deck, owned by the user and named "Daily card — 30 October 2026" '
            . 'after the day sent, which is the browser\'s local date: accepted only within a day of the server\'s UTC date. '
            . 'Deleting that reading doesn\'t free the day: it stays drawn, with no reading.',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['day', 'deck_id'],
                properties: [
                    new OAS\Property(property: 'day', schema: new OAS\Schema(description: 'The local date, YYYY-MM-DD.', type: 'string')),
                    new OAS\Property(property: 'deck_id', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'use_reversals', schema: new OAS\Schema(description: 'Off when omitted.', type: 'boolean')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'The day\'s card',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/DailyCard')
            ),
            new OAS\Response(response: 400, description: 'day is not a date within a day of today (UTC), or the deck is missing, unknown or awaiting approval'),
            new OAS\Response(response: 401, description: 'Not signed in'),
            new OAS\Response(
                response: 409,
                description: 'The day already has its card, or had one whose reading was deleted',
                content: new OAS\MediaType\Json(required: ['error', 'reading_id'], properties: [
                    new OAS\Property(property: 'error', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'reading_id', schema: new OAS\Schema(description: 'The reading already drawn for the day; null when it was deleted.', type: 'string', nullable: true)),
                ])
            ),
        ]
    )]
    public function draw(Request $request, Response $response): Response|ResponseInterface
    {
        try {
            $card = $this->dailyCards->draw($this->authUserId($request), $this->parsedBody($request));
        } catch (DailyCardTakenException $e) {
            return $response->withJson(['error' => $e->getMessage(), 'reading_id' => $e->readingId], 409);
        } catch (ApiException $e) {
            return $response->withJson(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $response->withJson($card, 201);
    }
}
