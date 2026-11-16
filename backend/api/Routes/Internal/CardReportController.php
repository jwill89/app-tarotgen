<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Routes\Problem;
use Tarot\Exception\ApiException;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\CardReportRepository;
use Tarot\Repository\DeckRepository;
use Tarot\Structure\CardReportReason;
use Tarot\Structure\Deck;
use Tarot\Utility\Input;
use Tarot\Utility\RateLimiter;
use OpenApi\Spec as OAS;

/**
 * Public endpoint for reporting a problem with a card's image, with the reason
 * (and optional details) the admins need to fix it. Reporting a card that
 * already has an open report bumps its counter. Two rate limits keep this from
 * being abused: an overall per-IP cap, and a per-IP-per-card cooldown so one
 * person can't inflate a card's count.
 */
class CardReportController extends AbstractController
{
    /** Overall abuse cap: reports per IP per window. */
    private const int REPORT_MAX_PER_WINDOW = 20;

    /** A given IP may report the same card at most once per window (no count inflation). */
    private const int PER_CARD_MAX_PER_WINDOW = 1;

    private const int WINDOW_SECONDS = 3600;

    /** Longest description a reporter can attach. */
    private const int DETAILS_MAX_LENGTH = 500;

    /** Longest card name a report keeps. */
    private const int CARD_NAME_MAX_LENGTH = 120;

    public function __construct(
        private readonly CardReportRepository $reports,
        private readonly DeckRepository $decks,
    ) {}

    #[OAS\Operation\Post(
        path: '/card-reports',
        summary: "Report a problem with a card's image (rate-limited)",
        tags: ['Cards'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['deck_id', 'card_id'],
                properties: [
                    new OAS\Property(property: 'deck_id', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'card_id', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'card_name', schema: new OAS\Schema(type: 'string', maxLength: self::CARD_NAME_MAX_LENGTH)),
                    new OAS\Property(
                        property: 'reason',
                        schema: new OAS\Schema(ref: CardReportReason::class, description: 'Sent by the site and current plugin builds. Older plugin builds omit it; '
                            . 'their reports only bump the counter.')
                    ),
                    new OAS\Property(
                        property: 'details',
                        schema: new OAS\Schema(type: 'string', maxLength: self::DETAILS_MAX_LENGTH, description: 'What is wrong, in the reporter\'s words. Required when reason is `other`.')
                    ),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Report recorded',
                content: new OAS\MediaType\Json(required: ['status'], properties: [new OAS\Property(property: 'status', schema: new OAS\Schema(type: 'string'))])
            ),
            new OAS\Response(
                response: 200,
                description: 'Already reported this card recently (counter not bumped)',
                content: new OAS\MediaType\Json(required: ['status'], properties: [new OAS\Property(property: 'status', schema: new OAS\Schema(type: 'string'))])
            ),
            new OAS\Response(
                response: 422,
                description: 'Missing/invalid deck or card id, unknown reason, `other` without details, or a card name or details over the limit'
            ),
            new OAS\Response(response: 429, description: 'Too many reports from this IP: 20/hour'),
        ]
    )]
    public function report(Request $request, Response $response): Response|ResponseInterface
    {
        $ip = $this->clientIp($request);

        $overall = new RateLimiter('card_report_ip', self::REPORT_MAX_PER_WINDOW, self::WINDOW_SECONDS);
        if ($overall->isLimited($ip)) {
            return Problem::tooManyRequests(
                $response,
                'You have reported too many cards recently. Please try again later.',
                $overall->retryAfter($ip)
            );
        }

        $body   = $this->parsedBody($request);
        $deckId = (int) ($body['deck_id'] ?? 0);
        $cardId = (int) ($body['card_id'] ?? -1);

        try {
            $cardName  = Input::text($body['card_name'] ?? null, self::CARD_NAME_MAX_LENGTH, 'body.card_name');
            $reasonKey = Input::text($body['reason'] ?? null, PHP_INT_MAX, 'body.reason');
            $details   = Input::text($body['details'] ?? null, self::DETAILS_MAX_LENGTH, 'body.details');
        } catch (ApiException $e) {
            return Problem::fromException($response, $e);
        }
        $reason = CardReportReason::tryFrom($reasonKey);

        // A deck deleted since its card was shown (a stale tab, an old plugin
        // build) would otherwise fail the foreign key as a 500.
        if ($deckId < 1 || !$this->decks->get($deckId) instanceof Deck) {
            return Problem::invalid($response, 'body.deck_id', 'A valid deck and card are required.');
        }
        if ($cardId < 0) {
            return Problem::invalid($response, 'body.card_id', 'A valid deck and card are required.');
        }

        // No reason at all is an older plugin build, accepted as a bare count bump;
        // a reason we don't recognise is a client bug worth surfacing.
        if ($reasonKey !== '' && $reason === null) {
            return Problem::invalid($response, 'body.reason', "Please choose what's wrong with this card.");
        }

        if ($reason === CardReportReason::Other && $details === '') {
            return Problem::invalid($response, 'body.details', "Please describe what's wrong with this card.");
        }

        // Per-card cooldown: if this IP already reported this exact card in the
        // window, treat it as a friendly no-op rather than inflating the counter.
        $perCard = new RateLimiter('card_report_card', self::PER_CARD_MAX_PER_WINDOW, self::WINDOW_SECONDS);
        $cardKey = "{$ip}:{$deckId}:{$cardId}";
        if ($perCard->isLimited($cardKey)) {
            return $response->withJson(['status' => 'already_reported']);
        }

        $this->reports->report($deckId, $cardId, $cardName, $reason, $details);

        $perCard->hit($cardKey);
        $overall->hit($ip);

        return $response->withJson(['status' => 'reported'], 201);
    }
}
