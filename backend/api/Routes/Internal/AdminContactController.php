<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\ContactRepository;
use OpenApi\Spec as OAS;

/**
 * Messages sent through the public contact form.
 */
class AdminContactController extends AbstractController
{
    public function __construct(
        private readonly ContactRepository $contacts,
    ) {}

    #[OAS\Operation\Get(
        path: '/admin/contacts',
        summary: 'List submitted contact messages',
        tags: ['Admin · Contacts'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(
                name: 'show_read',
                in: 'query',
                required: false,
                description: 'Set to "1" to include already-read messages',
                schema: new OAS\Schema(type: 'string', enum: ['0', '1'])
            ),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of contacts',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Contact'))
            ),
        ]
    )]
    public function getContacts(Request $request, Response $response): Response|ResponseInterface
    {
        $showRead = ($request->getQueryParams()['show_read'] ?? '0') === '1';
        $unreadOnly = $showRead ? null : true;

        return $response->withJson($this->contacts->get($unreadOnly));
    }

    /**
     * Partial update of a contact message. The `is_read` flag is the only
     * editable field.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Patch(
        path: '/admin/contacts/{contact_id}',
        summary: 'Partially update a contact message',
        tags: ['Admin · Contacts'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'contact_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'is_read', schema: new OAS\Schema(type: 'boolean')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated',
                content: new OAS\MediaType\Json(
                    required: ['success'],
                    properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))]
                )
            ),
            new OAS\Response(response: 400, description: 'Invalid contact ID'),
            new OAS\Response(response: 404, description: 'Contact not found'),
        ]
    )]
    public function updateContact(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $contact_id = (int) ($args['contact_id'] ?? 0);

        if ($contact_id < 1) {
            return $response->withJson(['error' => 'Invalid contact ID'], 400);
        }

        if (!$this->contacts->exists($contact_id)) {
            return $response->withJson(['error' => 'Contact not found'], 404);
        }

        $params = $this->parsedBody($request);

        if (array_key_exists('is_read', $params)) {
            $this->contacts->markRead($contact_id, (bool) $params['is_read']);
        }

        return $response->withJson(['success' => true]);
    }
}
