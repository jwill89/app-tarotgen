<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\CardFrequencyRepository;
use OpenApi\Spec as OAS;

/**
 * "Which cards keep turning up": card counts over the signed-in user's own
 * readings (behind UserAuth). A tally, never an interpretation.
 */
class CardFrequencyController extends AbstractController
{
    /** The longest window ?days takes: ten years, well past any account's age. */
    private const int MAX_DAYS = 3650;

    public function __construct(private readonly CardFrequencyRepository $frequency) {}

    #[OAS\Operation\Get(
        path: '/account/card-frequency',
        summary: "How often each card turned up in the user's readings (daily cards included)",
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(
                name: 'deck_id',
                in: 'query',
                required: false,
                description: 'Count one deck\'s readings only, card by card. Omit to count every deck, a card by its name.',
                schema: new OAS\Schema(type: 'integer', minimum: 1)
            ),
            new OAS\Parameter(
                name: 'days',
                in: 'query',
                required: false,
                description: 'Count readings from the last this many days (1–' . self::MAX_DAYS . '). Omit for all time.',
                schema: new OAS\Schema(type: 'integer', maximum: self::MAX_DAYS, minimum: 1)
            ),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The counts',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/CardFrequency')
            ),
            new OAS\Response(response: 400, description: 'deck_id or days is not a whole number in range'),
            new OAS\Response(response: 401, description: 'Not signed in'),
        ]
    )]
    public function get(Request $request, Response $response): Response|ResponseInterface
    {
        $query = $request->getQueryParams();

        $deckId = $this->wholeNumber($query['deck_id'] ?? null, PHP_INT_MAX);
        if ($deckId === false) {
            return $response->withJson(['error' => 'deck_id must be a deck id (a whole number from 1).'], 400);
        }

        $days = $this->wholeNumber($query['days'] ?? null, self::MAX_DAYS);
        if ($days === false) {
            return $response->withJson(['error' => 'days must be a whole number from 1 to ' . self::MAX_DAYS . '.'], 400);
        }

        return $response
            ->withJson($this->frequency->forUser($this->authUserId($request), $deckId, $days))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * An optional query value: null when absent or empty, the number when it is
     * a whole number from 1 to $max, false otherwise.
     */
    private function wholeNumber(mixed $value, int $max): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            return false;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);

        return is_int($number) ? $number : false;
    }
}
