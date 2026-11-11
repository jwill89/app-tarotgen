<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Config\Env;
use Tarot\Repository\UserRepository;
use Tarot\Service\AccountProtection;
use Tarot\Service\AuthService;
use Tarot\Structure\User;
use Tarot\Utility\Session;
use OpenApi\Spec as OAS;

/**
 * User accounts: activation, the admin flag, resending activation, deletion.
 *
 * Demoting and deleting go through AccountProtection: never your own account,
 * never a protected one (403).
 */
#[OAS\Schema(
    component: 'AdminUser',
    description: 'A user account as the admin user list shows it.',
    allOf: [
        new OAS\Schema(ref: '#/components/schemas/User'),
        new OAS\Schema(
            type: 'object',
            required: ['is_protected'],
            properties: [
                new OAS\Property(property: 'is_protected', schema: new OAS\Schema(
                    description: "Listed in PROTECTED_ADMIN_EMAILS: its admin access can't be removed and it can't be deleted.",
                    type: 'boolean'
                )),
            ]
        ),
    ]
)]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly AccountProtection $protection,
    ) {}

    #[OAS\Operation\Get(
        path: '/admin/users',
        summary: 'List all user accounts',
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Array of users',
                content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/AdminUser'))
            ),
        ]
    )]
    public function getUsers(Request $request, Response $response): Response|ResponseInterface
    {
        return $response->withJson(array_map($this->adminView(...), $this->users->getAll()));
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/users/{user_id}/activate',
        summary: 'Activate a user account',
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Activated',
                content: new OAS\MediaType\Json(
                    required: ['success'],
                    properties: [new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean'))]
                )
            ),
            new OAS\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function activateUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int) ($args['user_id'] ?? 0);

        if ($user_id < 1 || $this->users->findById($user_id) === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $this->users->activate($user_id);

        return $response->withJson(['success' => true]);
    }

    /**
     * Partial update of an account. The `is_admin` flag is the only editable
     * field.
     *
     * @param array<string,string> $args
     */
    #[OAS\Operation\Patch(
        path: '/admin/users/{user_id}',
        summary: 'Partially update a user account',
        description: "Removing the admin flag is refused (403) on your own account and on a protected one.",
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                properties: [
                    new OAS\Property(property: 'is_admin', schema: new OAS\Schema(type: 'boolean')),
                ]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Updated user',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/AdminUser')
            ),
            new OAS\Response(response: 403, description: 'Removing your own admin flag, or a protected account\'s'),
            new OAS\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function updateUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int) ($args['user_id'] ?? 0);
        $target  = $user_id < 1 ? null : $this->users->findById($user_id);

        if ($target === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $params = $this->parsedBody($request);

        if (array_key_exists('is_admin', $params)) {
            $isAdmin = (bool) $params['is_admin'];

            $refusal = $target->is_admin && !$isAdmin ? $this->protection->refuseDemotion($this->adminId(), $target) : null;
            if ($refusal !== null) {
                return $response->withJson(['error' => $refusal], 403);
            }

            $this->users->setAdmin($user_id, $isAdmin);
        }

        // Return the refreshed user so the client can update the row in place.
        return $this->freshUserResponse($response, $user_id);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Post(
        path: '/admin/users/{user_id}/resend-activation',
        summary: 'Resend a user activation email',
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(
                response: 200,
                description: 'A new activation link was issued (replacing any earlier one)',
                content: new OAS\MediaType\Json(
                    required: ['success', 'emailed'],
                    properties: [
                        new OAS\Property(property: 'success', schema: new OAS\Schema(type: 'boolean')),
                        new OAS\Property(property: 'emailed', schema: new OAS\Schema(description: 'Whether the activation email was sent.', type: 'boolean')),
                        new OAS\Property(property: 'activation_link', schema: new OAS\Schema(description: 'The new activation link. Sent only outside production, and only when the email was not sent.', type: 'string')),
                    ]
                )
            ),
            new OAS\Response(response: 400, description: 'Could not resend activation'),
        ]
    )]
    public function resendActivation(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int) ($args['user_id'] ?? 0);

        $result = $this->auth->resendActivation($user_id);

        if (!$result['ok']) {
            return $response->withJson(['error' => $result['error'] ?? 'Could not resend activation.'], 400);
        }

        $payload = ['success' => true, 'emailed' => $result['emailed'] ?? false];

        // Outside production, surface the link when email isn't configured.
        if (!Env::isProduction() && !($result['emailed'] ?? false)) {
            $payload['activation_link'] = $result['activation_link'] ?? null;
        }

        return $response->withJson($payload);
    }

    /**
     * @param array<string,string> $args
     */
    #[OAS\Operation\Delete(
        path: '/admin/users/{user_id}',
        summary: 'Delete a user account',
        description: 'Ends every session of the account too. Refused (403) for your own account (Account Settings deletes that) and for a protected one.',
        tags: ['Admin · Users'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [
            new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 403, description: 'Your own account, or a protected one'),
            new OAS\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function deleteUser(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $user_id = (int) ($args['user_id'] ?? 0);
        $target  = $user_id < 1 ? null : $this->users->findById($user_id);

        if ($target === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        $refusal = $this->protection->refuseAdminDeletion($this->adminId(), $target);
        if ($refusal !== null) {
            return $response->withJson(['error' => $refusal], 403);
        }

        $this->auth->removeAccount($user_id);

        return $response->withStatus(204);
    }

    /** The account as it now stands, read again after a change. */
    private function freshUserResponse(Response $response, int $userId): Response|ResponseInterface
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withJson(['error' => 'User not found'], 404);
        }

        return $response->withJson($this->adminView($user));
    }

    /**
     * A user as the admin list shows it (the AdminUser schema): with whether
     * it's protected, so the page doesn't offer what would be refused.
     *
     * @return array<string,mixed>
     */
    private function adminView(User $user): array
    {
        return [...$user->jsonSerialize(), 'is_protected' => $this->protection->isProtected($user)];
    }

    /** The signed-in admin making the request (AdminAuth has checked the session). */
    private function adminId(): int
    {
        return Session::userId() ?? 0;
    }
}
