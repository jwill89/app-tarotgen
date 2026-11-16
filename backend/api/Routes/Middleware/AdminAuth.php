<?php

namespace Routes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Routes\Problem;
use Slim\Psr7\Response;
use Tarot\Repository\UserRepository;
use Tarot\Structure\User;
use Tarot\Utility\Session;

/**
 * Gate admin routes on a logged-in user account that is active and flagged
 * `is_admin`: 401 without one, 403 for a signed-in account that isn't an admin.
 * The flag is re-checked from the database on every request, so revoking admin
 * (or deleting/deactivating the account) takes effect immediately rather than
 * lingering for the life of a session.
 */
readonly class AdminAuth
{
    public function __construct(private UserRepository $users) {}

    public function __invoke(Request $request, Handler $handler): ResponseInterface
    {
        $user = $this->sessionUser();

        if ($user === null) {
            return Problem::respond(new Response(), 401, 'Not authenticated');
        }

        if (!$user->is_admin) {
            return Problem::respond(new Response(), 403, 'Only an admin can do that.');
        }

        return $handler->handle($request);
    }

    /**
     * Whether this request's session belongs to an active admin account: the
     * check the admin routes are gated on, shared with the public routes that
     * show an admin more than a visitor (an unapproved submission). Session
     * only, as for the admin routes: a plugin token never carries admin rights.
     */
    public function sessionIsAdmin(): bool
    {
        return $this->sessionUser()?->is_admin === true;
    }

    /** The active account this request's session is signed in to, if any. */
    private function sessionUser(): ?User
    {
        Session::start();

        $userId = Session::userId();
        if ($userId === null) {
            return null;
        }

        $user = $this->users->findById($userId);
        return $user !== null && $user->is_active ? $user : null;
    }
}
