<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Routes\Middleware\AdminAuth;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\UserRepository;
use Tarot\Service\CardNameResolver;
use Tarot\Structure\Deck;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

class DeckController extends AbstractController
{
    public function __construct(
        private readonly DeckRepository $decks,
        private readonly CardNameResolver $cardNames,
        private readonly UserRepository $users,
        private readonly AdminAuth $admin,
    ) {}

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Get(
        path: '/decks',
        summary: 'List usable decks',
        tags: ['Decks'],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of usable decks',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Deck'))
            ),
        ]
    )]
    #[OAS\Operation\Get(
        path: '/decks/{deck_id}',
        summary: 'A single deck (one still awaiting approval only to an admin)',
        tags: ['Decks'],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'The deck',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Deck')
            ),
            new OAS\Response(response: 404, description: 'InvalidDeckID: no such deck, or one awaiting approval and the viewer is not an admin'),
        ]
    )]
    public function getDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck_id = $args['deck_id'] ?? null;

        // Public responses omit submitted_by: it's a user id, needed only by the
        // admin deck list (AdminDeckController), not by anyone browsing decks.
        if ($deck_id === null) {
            // Public listing: only usable decks
            $data = array_map(
                static fn(Deck $d): array => $d->jsonSerializeWithout('submitted_by'),
                $this->decks->getUsable()
            );

            return $this->cacheable($response->withJson($data), true);
        }

        $deck = $this->visibleDeck((int) $deck_id);
        if ($deck === null) {
            return $response->withJson(['error' => 'InvalidDeckID'], 404);
        }

        return $this->cacheable($response->withJson($deck->jsonSerializeWithout('submitted_by')), $deck->approved);
    }

    /**
     * List every card available in a deck as {card_id, name}, so the custom
     * reading screen can offer a picker. Mirrors the reading engine's card
     * resolution.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Get(
        path: '/decks/{deck_id}/cards',
        summary: 'List every card available in a deck (for the custom-reading picker; a deck awaiting approval only to an admin)',
        tags: ['Decks'],
        parameters: [
            new OAS\Parameter(name: 'deck_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of {card_id, name}',
                content: new OAS\MediaType\Json(
                    type: 'array',
                    items: new OAS\Schema\Items(properties: [
                        new OAS\Property(property: 'card_id', schema: new OAS\Schema(type: 'integer')),
                        new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string')),
                    ])
                )
            ),
            new OAS\Response(response: 404, description: 'InvalidDeckID: no such deck, or one awaiting approval and the viewer is not an admin'),
        ]
    )]
    public function getDeckCards(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $deck = $this->visibleDeck((int) ($args['deck_id'] ?? 0));
        if ($deck === null) {
            return $response->withJson(['error' => 'InvalidDeckID'], 404);
        }

        // Include extras so the user can place anything physically in the deck.
        $systemTotal = $deck->getEffectiveTotalCards();
        $available = max(1, $systemTotal + $deck->additional_cards);

        $names = $this->cardNames->resolve($deck, range(1, $available)); // card_id => name
        ksort($names);

        $cards = [];
        foreach ($names as $card_id => $name) {
            $cards[] = ['card_id' => $card_id, 'name' => $name];
        }

        return $this->cacheable($response->withJson($cards), $deck->approved);
    }

    #[OAS\Operation\Post(
        path: '/decks',
        summary: 'Submit a new deck for review (requires a signed-in user)',
        tags: ['Decks'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['name', 'artist'],
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
                description: 'The created (unapproved) deck',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/Deck')
            ),
            new OAS\Response(response: 400, description: 'Name and artist are required'),
            new OAS\Response(response: 401, description: 'Not authenticated'),
            new OAS\Response(response: 409, description: 'A deck with that name already exists'),
        ]
    )]
    /**
     * Submit a new deck (requires a logged-in user). The deck is created
     * as unapproved (pending admin review).
     */
    public function submitDeck(Request $request, Response $response): Response|ResponseInterface
    {
        Session::start();

        // The account is re-checked, as UserAuth does: deleting an account leaves
        // its sessions behind, and they mustn't keep submitting.
        $userId = Session::userId();
        $user   = $userId === null ? null : $this->users->findById($userId);
        if ($user === null || !$user->is_active) {
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        $params = $this->parsedBody($request);

        $name   = trim((string) ($params['name'] ?? ''));
        $artist = trim((string) ($params['artist'] ?? ''));

        if ($name === '' || $artist === '') {
            return $response->withJson(['error' => 'Name and Artist are required.'], 400);
        }

        $deck = $this->decks->create([
            'name'             => $name,
            'artist'           => $artist,
            'purchase_url'     => trim((string) ($params['purchase_url'] ?? '')),
            'deck_system_id'   => (int) ($params['deck_system_id'] ?? 1),
            'additional_cards' => (int) ($params['additional_cards'] ?? 0),
            'approved'         => false,
            'usable'           => false,
            'submitted_by'     => $user->user_id,
        ]);

        // Null only when the name is taken (decks.name is UNIQUE).
        if ($deck === null) {
            return $response->withJson(['error' => 'A deck with that name already exists.'], 409);
        }

        return $response->withJson($deck, 201);
    }

    /**
     * The deck a visitor may see by id: an approved one to anyone, one still
     * awaiting approval only to an admin. Anyone else gets null, answered as an
     * unknown id, so pending submissions can't be found by probing ids. The
     * admin check runs for a missing deck too: it can start a session (and send
     * its cookie), and the two answers must not differ even in that.
     */
    private function visibleDeck(int $deckId): ?Deck
    {
        $deck = $this->decks->get($deckId);
        if ($deck instanceof Deck && $deck->approved) {
            return $deck;
        }

        $isAdmin = $this->admin->sessionIsAdmin();

        return $deck instanceof Deck && $isAdmin ? $deck : null;
    }

    /**
     * Deck metadata changes rarely, so what anyone may see gets a short shared
     * cache. What only an admin may see must never be kept by a shared cache.
     */
    private function cacheable(ResponseInterface $response, bool $public): ResponseInterface
    {
        return $response->withHeader('Cache-Control', $public ? 'public, max-age=300' : 'private, no-store');
    }
}
