<?php

namespace Routes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use Tarot\Repository\UserRepository;
use Tarot\Utility\Session;

/**
 * Gate admin routes on a logged-in user account that is active and flagged
 * `is_admin`. The flag is re-checked from the database on every request, so
 * revoking admin (or deleting/deactivating the account) takes effect
 * immediately rather than lingering for the life of a session.
 */
readonly class AdminAuth
{
    public function __construct(private UserRepository $users) {}

    public function __invoke(Request $request, Handler $handler): ResponseInterface
    {
        if ($this->sessionIsAdmin()) {
            return $handler->handle($request);
        }

        $response = new Response();
        $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Whether this request's session belongs to an active admin account: the
     * check the admin routes are gated on, shared with the public routes that
     * show an admin more than a visitor (an unapproved submission). Session
     * only, as for the admin routes: a plugin token never carries admin rights.
     */
    public function sessionIsAdmin(): bool
    {
        Session::start();

        $userId = Session::userId();
        if ($userId === null) {
            return false;
        }

        $user = $this->users->findById($userId);
        return $user !== null && $user->is_active && $user->is_admin;
    }
}
