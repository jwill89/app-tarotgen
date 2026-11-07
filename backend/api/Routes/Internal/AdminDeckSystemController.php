<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Exception\NameTakenException;
use Tarot\Repository\DeckSystemRepository;
use Tarot\Structure\DeckSystem;
use OpenApi\Spec as OAS;

/**
 * Deck systems (the card lists decks follow): approved and pending, edit,
 * approve, delete.
 */
class AdminDeckSystemController extends AbstractController
{
    private const string NAME_TAKEN = 'A deck system with that name already exists';

    public function __construct(
        private readonly DeckSystemRepository $deckSystems,
    ) {}

    #[OAS\Operation\Get(
        path: '/admin/deck-systems',
        summary: 'List all approved deck systems',
        tags: ['Admin · Deck Systems'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of deck systems',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/DeckSystem'))
            ),
        ]
    )]
    public function getDeckSystems(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->deckSystems->getApproved());
    }

    #[OAS\Operation\Get(
        path: '/admin/deck-systems/pending',
        summary: 'List deck systems awaiting approval',
        tags: ['Admin · Deck Systems'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of pending deck systems',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/DeckSystem'))
            ),
        ]
    )]
    public function getPendingDeckSystems(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson($this->deckSystems->getPending());
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Get(
        path: '/admin/deck-systems/{id}',
        summary: 'Get a single deck system with its cards',
        tags: ['Admin · Deck Systems'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The deck system plus its cards array',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/DeckSystemWithCards')
            ),
            new OAS\Response(response: 404, description: 'Deck system not found'),
        ]
    )]
    public function getDeckSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $system = $this->deckSystems->get($id);

        if (!($system instanceof DeckSystem)) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        $cards = $this->deckSystems->getCards($id);

        $data = $system->jsonSerialize();
        $data['cards'] = array_map(fn($c) => $c->jsonSerialize(), $cards);

        return $response->withJson($data);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/deck-systems/{id}/approve',
        summary: 'Approve a pending deck system',
        tags: ['Admin · Deck Systems'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Approved deck system',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/DeckSystem')
            ),
            new OAS\Response(response: 404, description: 'Deck system not found'),
        ]
    )]
    public function approveDeckSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);

        $system = $this->deckSystems->update($id, ['approved' => true]);

        if ($system === null) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        return $response->withJson($system);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Put(
        path: '/admin/deck-systems/{id}',
        summary: 'Update a deck system and its cards',
        tags: ['Admin · Deck Systems'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'short_name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'total_cards', schema: new OAS\Schema(type: 'integer')),
                    new OAS\Property(property: 'cards', schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(type: 'object'))),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated deck system',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/DeckSystem')
            ),
            new OAS\Response(response: 400, description: 'Name or short name is required (one sent blank)'),
            new OAS\Response(response: 404, description: 'Deck system not found'),
            new OAS\Response(response: 409, description: 'Another deck system already has that name'),
        ]
    )]
    public function updateDeckSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id     = (int) ($args['id'] ?? 0);
        $params = $this->parsedBody($request);

        // The system must exist; but an edit that only changes card definitions
        // (no name/short_name/total_cards/approved change) is still valid, so a
        // null from update() is not on its own a 404.
        $existing = $this->deckSystems->get($id);
        if (!($existing instanceof DeckSystem)) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        // A rename may not blank either name, any more than a submission may leave it out.
        foreach (['name' => 'Name', 'short_name' => 'Short name'] as $field => $label) {
            if (array_key_exists($field, $params) && trim((string) $params[$field]) === '') {
                return $response->withJson(['error' => "{$label} is required"], 400);
            }
        }

        // A taken name is refused before the cards are touched, so nothing changes.
        try {
            $system = $this->deckSystems->update($id, $params) ?? $existing;
        } catch (NameTakenException) {
            return $response->withJson(['error' => self::NAME_TAKEN], 409);
        }

        // If cards were supplied, update them
        $cards = $params['cards'] ?? null;
        if (is_array($cards)) {
            $cardData = [];
            foreach ($cards as $i => $card) {
                if (!is_array($card)) {
                    continue;
                }
                $cardData[] = [
                    'card_id'           => (int) ($card['card_id'] ?? ($i + 1)),
                    'name'              => trim((string) ($card['name'] ?? '')),
                    'keywords'          => $card['keywords'] ?? null,
                    'meaning'           => $card['meaning'] ?? null,
                    'advice'            => $card['advice'] ?? null,
                    'reversed_keywords' => $card['reversed_keywords'] ?? null,
                    'reversed_meaning'  => $card['reversed_meaning'] ?? null,
                    'reversed_advice'   => $card['reversed_advice'] ?? null,
                ];
            }
            if ($cardData !== []) {
                $this->deckSystems->deleteCards($id);
                $this->deckSystems->saveCards($id, $cardData);
            }
        }

        return $response->withJson($system);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/deck-systems/{id}',
        summary: 'Delete a deck system',
        tags: ['Admin · Deck Systems'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'Deck system not found'),
        ]
    )]
    public function deleteDeckSystem(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);

        $result = $this->deckSystems->delete($id);

        if (!$result) {
            return $response->withJson(['error' => 'Deck system not found'], 404);
        }

        return $response->withStatus(204);
    }
}
