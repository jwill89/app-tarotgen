<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Exception\NameTakenException;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\SpecialCardRepository;
use Tarot\Service\CardImageService;
use Tarot\Structure\Deck;
use OpenApi\Spec as OAS;

/**
 * Decks: the approved list and pending submissions, create/approve/edit/delete,
 * the web images derived from each deck's scans, and the special (extra) cards
 * that belong to a deck.
 */
class AdminDeckController extends AbstractController
{
    private const string NAME_TAKEN = 'A deck with that name already exists';

    public function __construct(
        private readonly DeckRepository $decks,
        private readonly SpecialCardRepository $specialCards,
        private readonly CardImageService $cardImages,
    ) {}

    #[OAS\Operation\Get(
        path: '/admin/decks',
        summary: 'List all approved decks',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of decks',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Deck'))
            ),
        ]
    )]
    public function getDecks(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->decks->getApproved());
    }

    #[OAS\Operation\Get(
        path: '/admin/decks/pending',
        summary: 'List decks awaiting approval',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of pending decks',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Deck'))
            ),
        ]
    )]
    public function getPendingDecks(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->decks->getPending());
    }

    #[OAS\Operation\Post(
        path: '/admin/decks',
        summary: 'Create a deck',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name'],
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'artist', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'purchase_url', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'deck_system_id', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'additional_cards', schema: new OAS\Schema(type: 'integer')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Created deck',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Deck')
            ),
            new OAS\Response(response: 400, description: 'Name is required'),
            new OAS\Response(response: 409, description: 'A deck with that name already exists'),
        ]
    )]
    public function createDeck(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);

        if (trim((string) ($params['name'] ?? '')) === '') {
            return $response->withJson(['error' => 'Name is required'], 400);
        }

        // Admin-created decks are auto-approved but NOT auto-usable.
        $params['approved'] = true;
        $params['usable']   = false;
        $deck = $this->decks->create($params);

        // Null only when the name is taken (decks.name is UNIQUE).
        if ($deck === null) {
            return $response->withJson(['error' => self::NAME_TAKEN], 409);
        }

        return $response->withJson($deck, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/decks/{deck_id}/approve',
        summary: 'Approve a pending deck',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Approved deck',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Deck')
            ),
            new OAS\Response(response: 404, description: 'Deck not found'),
        ]
    )]
    public function approveDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int) ($args['deck_id'] ?? 0);

        $deck = $this->decks->update($deck_id, ['approved' => true]);

        if ($deck === null) {
            return $response->withJson(['error' => 'Deck not found'], 404);
        }

        return $response->withJson($deck);
    }

    // ── Card web images (display WebP + thumbnail, derived from the PNG scans) ──

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/decks/{deck_id}/images',
        summary: 'Generate missing or stale web images (display WebP + thumbnail) for a deck',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Per-card counts: generated, skipped (already current), failed',
                content: new OAS\MediaType\Json(required: ['deck_id', 'generated', 'skipped', 'failed'], properties: [
                    new OAS\Property(property: 'deck_id', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'generated', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'skipped', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'failed', schema: new OAS\Schema(type: 'integer')),
                ])
            ),
            new OAS\Response(response: 400, description: 'Invalid deck ID'),
        ]
    )]
    public function generateDeckImages(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        // Full-size WebP encoding is ~0.3 s per card; a whole deck outlives the
        // default 30 s limit, and a closed tab mustn't abort it half-way.
        @set_time_limit(0);
        ignore_user_abort(true);

        $deck_id = (int) ($args['deck_id'] ?? 0);
        if ($deck_id < 1) {
            return $response->withJson(['error' => 'Invalid deck ID'], 400);
        }

        return $response->withJson($this->cardImages->generateForDeck($deck_id));
    }

    #[OAS\Operation\Post(
        path: '/admin/decks/images',
        summary: 'Generate missing or stale web images for all decks',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Per-card totals across decks: generated, skipped (already current), failed',
                content: new OAS\MediaType\Json(required: ['decks', 'generated', 'skipped', 'failed'], properties: [
                    new OAS\Property(property: 'decks', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'generated', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'skipped', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'failed', schema: new OAS\Schema(type: 'integer')),
                ])
            ),
        ]
    )]
    public function generateAllImages(Request $request, Response $response): Response|ResponseInterface
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        $ids = [];
        foreach ($this->decks->getAll() as $deck) {
            $ids[] = $deck->deck_id;
        }

        return $response->withJson($this->cardImages->generateAll($ids));
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Patch(
        path: '/admin/decks/{deck_id}',
        summary: 'Partially update a deck',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            description: 'Partial set of deck fields to update',
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'artist', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'usable', schema: new OAS\Schema(type: 'boolean')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated deck',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Deck')
            ),
            new OAS\Response(response: 400, description: 'Name is required (a name sent blank)'),
            new OAS\Response(response: 404, description: 'Deck not found or no valid fields'),
            new OAS\Response(response: 409, description: 'Another deck already has that name'),
        ]
    )]
    public function updateDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int) ($args['deck_id'] ?? 0);
        $params = $this->parsedBody($request);

        // A rename may not blank the name, any more than a create may leave it out.
        if (array_key_exists('name', $params) && trim((string) $params['name']) === '') {
            return $response->withJson(['error' => 'Name is required'], 400);
        }

        try {
            $deck = $this->decks->update($deck_id, $params);
        } catch (NameTakenException) {
            return $response->withJson(['error' => self::NAME_TAKEN], 409);
        }

        if ($deck === null) {
            return $response->withJson(['error' => 'Deck not found or no valid fields'], 404);
        }

        // Enabling a deck creates its on-disk image folders. This was previously
        // the dedicated POST …/usable action; it is now a PATCH field carrying the
        // same side effect.
        if (array_key_exists('usable', $params) && (bool) $params['usable']) {
            $this->cardImages->ensureDeckFolders($deck->deck_id);
        }

        return $response->withJson($deck);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/decks/{deck_id}',
        summary: 'Delete a deck',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 500, description: 'Failed to delete deck'),
        ]
    )]
    public function deleteDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int) ($args['deck_id'] ?? 0);

        $result = $this->decks->delete($deck_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete deck'], 500);
        }

        return $response->withStatus(204);
    }

    // ── Special Cards (nested under their deck) ─────────────────

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Get(
        path: '/admin/decks/{deck_id}/special-cards',
        summary: 'List a deck\'s special cards',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of special cards',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpecialCard'))
            ),
        ]
    )]
    public function getSpecialCards(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int) ($args['deck_id'] ?? 0);

        return $response->withJson($this->specialCards->getAll($deck_id));
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/decks/{deck_id}/special-cards',
        summary: 'Add a special card to a deck',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['card_id'],
                properties: [
                    new OAS\Property(property: 'card_id', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 201,
                description: 'Created special card',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/SpecialCard')
            ),
            new OAS\Response(response: 404, description: 'Deck not found'),
            new OAS\Response(response: 409, description: 'The deck already has a special card with that card_id'),
        ]
    )]
    public function createSpecialCard(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int) ($args['deck_id'] ?? 0);
        if (!($this->decks->get($deck_id) instanceof Deck)) {
            return $response->withJson(['error' => 'Deck not found'], 404);
        }

        $params = $this->parsedBody($request);
        // The deck is identified by the path, not the body.
        $params['deck_id'] = $deck_id;

        // Null only when the card_id is taken in this deck (its primary key).
        $card = $this->specialCards->create($params);
        if ($card === null) {
            return $response->withJson(['error' => 'This deck already has a special card with that card ID'], 409);
        }

        return $response->withJson($card, 201);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Put(
        path: '/admin/decks/{deck_id}/special-cards/{card_id}',
        summary: 'Update a special card',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'card_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated special card',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/SpecialCard')
            ),
            new OAS\Response(response: 404, description: 'Special card not found'),
        ]
    )]
    public function updateSpecialCard(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int) ($args['deck_id'] ?? 0);
        $card_id = (int) ($args['card_id'] ?? 0);
        $params = $this->parsedBody($request);

        $card = $this->specialCards->update($deck_id, $card_id, $params);

        if ($card === null) {
            return $response->withJson(['error' => 'Special card not found'], 404);
        }

        return $response->withJson($card);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/decks/{deck_id}/special-cards/{card_id}',
        summary: 'Delete a special card',
        tags: ['Admin · Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'card_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 500, description: 'Failed to delete special card'),
        ]
    )]
    public function deleteSpecialCard(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = (int) ($args['deck_id'] ?? 0);
        $card_id = (int) ($args['card_id'] ?? 0);

        $result = $this->specialCards->delete($deck_id, $card_id);

        if (!$result) {
            return $response->withJson(['error' => 'Failed to delete special card'], 500);
        }

        return $response->withStatus(204);
    }
}
