<?php

namespace Routes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use Tarot\Repository\UserRepository;
use Tarot\Service\PluginTokenService;
use Tarot\Utility\Session;

/**
 * Gate an account route on EITHER a browser session OR a linked-plugin Bearer
 * token, for the read endpoints a plugin is allowed to reach. The resolved user
 * id is stashed as the `auth_user_id` request attribute for the controller to
 * read (the same attribute {@see UserAuth} sets), so a handler never has to care
 * which credential authenticated it.
 *
 * Destructive / profile endpoints deliberately stay on {@see UserAuth}
 * (session-only) so a leaked plugin token can't change a password or delete an
 * account — a plugin token is least-privilege by construction.
 */
readonly class AccountAuth
{
    public function __construct(
        private UserRepository $users,
        private PluginTokenService $pluginTokens,
    ) {}

    public function __invoke(Request $request, Handler $handler): ResponseInterface
    {
        // A Bearer token wins when present; otherwise fall back to the session.
        $userId = $this->pluginTokens->resolveBearer($request->getHeaderLine('Authorization'));

        if ($userId === null) {
            Session::start();
            $userId = Session::userId();
        }

        if ($userId !== null) {
            $user = $this->users->findById($userId);
            if ($user !== null && $user->is_active) {
                return $handler->handle($request->withAttribute('auth_user_id', $userId));
            }
        }

        $response = new Response();
        $response->getBody()->write(json_encode(['error' => 'Not authenticated'], JSON_THROW_ON_ERROR));
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }
}
