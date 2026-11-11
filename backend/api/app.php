<?php

/**
 * Builds the Slim app — middleware, error handling and every route — without
 * running it. index.php serves it; the HTTP tests (tests/Http) dispatch PSR-7
 * requests through the very same stack against an in-memory database.
 */

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Handlers\ErrorHandler;
use Slim\Routing\RouteCollectorProxy;
use Routes\JsonErrorRenderer;
use Routes\Internal\{AccountController,
    AdminCardReportController,
    AdminContactController,
    AdminDashboardController,
    AdminDeckController,
    AdminDeckSystemController,
    AdminReadingController,
    AdminSpreadCategoryController,
    AdminSpreadController,
    AdminUserController,
    ApiDocsController,
    CardFrequencyController,
    CardReportController,
    ConfigController,
    ContactController,
    DailyCardController,
    DeckController,
    DeckSystemController,
    DrawKeyController,
    GoogleAuthController,
    PasskeyController,
    PluginAuthController,
    ReadingController,
    SpreadController,
    UserController};
use Routes\Middleware\AccountAuth;
use Routes\Middleware\AdminAuth;
use Routes\Middleware\OriginGuard;
use Routes\Middleware\UserAuth;
use Tarot\Config\Env;
use Tarot\Utility\Session;

/**
 * @return App<ContainerInterface|null>
 */
return function (ContainerInterface $container, bool $is_production): App {
    // Sessions live in the same database as everything else.
    Session::useDatabase(static fn(): PDO => $container->get(PDO::class));

    // Register Container
    AppFactory::setContainer($container);

    // Setup the app/log
    $app = AppFactory::create();

    // Set base path
    $app->setBasePath('/api');

    // Setup Middleware
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();

    // Setup Error Handling — never leak details/stack traces in production.
    $error_middleware = $app->addErrorMiddleware($is_production === false, true, true);

    // Every error answers in the API's `{ error }` JSON, whatever the request's
    // Accept header: this is a JSON API, and the client reads `error`.
    $default_error_handler = $error_middleware->getDefaultErrorHandler();
    if (!$default_error_handler instanceof ErrorHandler) {
        throw new LogicException('Expected Slim\'s own ErrorHandler as the default.');
    }
    $default_error_handler->registerErrorRenderer('application/json', JsonErrorRenderer::class);
    $default_error_handler->forceContentType('application/json');

    // 404/405s are mostly scanners probing for /api/.env and the like (~100 a day).
    // Answer them as the default handler does, but without writing a stack trace to
    // the shared Apache error log each time; real exceptions keep full logging.
    // Not a static closure: Slim's CallableResolver binds closures to the container.
    $error_middleware->setErrorHandler(
        [HttpNotFoundException::class, HttpMethodNotAllowedException::class],
        fn(ServerRequestInterface $request, Throwable $exception, bool $displayErrorDetails): ResponseInterface
            => $default_error_handler($request, $exception, $displayErrorDetails, false, false)
    );

    // Security headers + opt-in CORS. The SPA is same-origin, so cross-origin
    // access is only enabled when APP_ORIGIN is explicitly configured (never '*'
    // alongside credentialed session cookies).
    $allowed_origin = Env::get('APP_ORIGIN');
    $app->add(function ($request, $handler) use ($allowed_origin) {
        $response = $handler->handle($request);

        if (($allowed_origin ?? '') !== '') {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $allowed_origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
        }

        return $response
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    });

    // CSRF defense-in-depth: reject state-changing requests from a foreign origin.
    // Added last so it runs first (outermost), short-circuiting before routing.
    // Safe methods (incl. the GET OAuth callback) and same-origin requests pass.
    $app->add(OriginGuard::class);

    // ── API documentation (public, not part of the documented surface) ───────────
    $app->get('/openapi.json', ApiDocsController::class . ':spec');
    $app->get('/docs', ApiDocsController::class . ':reference');

    // ── Decks (public read + user submission) ────────────────────────────────────
    $app->group('/decks', function (RouteCollectorProxy $group) {
        $group->get('/{deck_id}/cards[/]', DeckController::class . ':getDeckCards');
        $group->get('[/{deck_id}[/]]', DeckController::class . ':getDeck');
        $group->post('[/]', DeckController::class . ':submitDeck');
    });

    // ── Deck systems (public read + user submission) ─────────────────────────────
    $app->group('/deck-systems', function (RouteCollectorProxy $group) {
        $group->get('/{id}[/]', DeckSystemController::class . ':getSystem');
        $group->get('[/]', DeckSystemController::class . ':getSystems');
        $group->post('[/]', DeckSystemController::class . ':submitSystem');
    });

    // ── Readings ─────────────────────────────────────────────────────────────────
    $app->group('/readings', function (RouteCollectorProxy $group) {
        $group->post('/generate[/]', ReadingController::class . ':newReading');
        $group->post('[/]', ReadingController::class . ':customReading');
        $group->put('/{reading_id}/placement[/]', ReadingController::class . ':updatePlacement');
        $group->post('/{reading_id}/draw[/]', ReadingController::class . ':drawCards');
        $group->post('/{reading_id}/finalize[/]', ReadingController::class . ':finalizeReading');
        $group->post('/{reading_id}/unlock[/]', ReadingController::class . ':unlockReading');
        $group->get('/{reading_id}[/]', ReadingController::class . ':getReading');
    });

    // ── Account reads a linked plugin may also reach (browser session OR a plugin
    // Bearer token). Least-privilege: only these read endpoints accept a token.
    $app->group('/account', function (RouteCollectorProxy $group) {
        $group->get('/readings[/]', AccountController::class . ':myReadings');
        $group->get('/favorites[/]', AccountController::class . ':myFavorites');
        $group->get('/favorite-decks[/]', AccountController::class . ':myFavoriteDecks');
    })->add(AccountAuth::class);

    // ── Account self-service (browser session only: mutations, settings, deletion,
    // and managing which plugins are linked). A plugin token is NOT accepted here.
    $app->group('/account', function (RouteCollectorProxy $group) {
        // The whole journal as a download: personal data in bulk, so session only.
        $group->get('/readings/export[/]', AccountController::class . ':exportReadings');
        $group->patch('/readings/{reading_id}[/]', AccountController::class . ':updateReadingMeta');
        $group->delete('/readings/{reading_id}[/]', AccountController::class . ':deleteReading');
        $group->get('/spreads[/]', AccountController::class . ':mySpreads');
        $group->post('/spreads[/]', AccountController::class . ':createSpread');
        $group->put('/spreads/{user_spread_id}[/]', AccountController::class . ':updateSpread');
        $group->delete('/spreads/{user_spread_id}[/]', AccountController::class . ':deleteSpread');
        $group->post('/spreads/{user_spread_id}/submit[/]', AccountController::class . ':submitSpreadAsPublic');
        $group->post('/favorites[/]', AccountController::class . ':addFavorite');
        $group->delete('/favorites/{spread_type}/{spread_id}[/]', AccountController::class . ':removeFavorite');
        $group->post('/favorite-decks[/]', AccountController::class . ':addFavoriteDeck');
        $group->delete('/favorite-decks/{deck_id}[/]', AccountController::class . ':removeFavoriteDeck');
        $group->post('/change-password[/]', AccountController::class . ':changePassword');
        $group->post('/sessions/end-others[/]', AccountController::class . ':endOtherSessions');
        $group->patch('[/]', AccountController::class . ':updateProfile');
        $group->delete('[/]', AccountController::class . ':deleteAccount');
        // Connected Apps: list / revoke linked plugin tokens.
        $group->get('/tokens[/]', PluginAuthController::class . ':listTokens');
        $group->delete('/tokens/{id}[/]', PluginAuthController::class . ':revokeToken');
    })->add(UserAuth::class);

    // ── Journal tools (browser session only: the plugin calls none of them, so a
    // token stays least-privilege): card frequency, the daily card, the reading
    // tags and spreads My Readings filters by.
    $app->group('/account', function (RouteCollectorProxy $group) {
        $group->get('/card-frequency[/]', CardFrequencyController::class . ':get');
        $group->get('/tags[/]', AccountController::class . ':myTags');
        $group->get('/reading-spreads[/]', AccountController::class . ':myReadingSpreads');
        $group->get('/daily-cards[/]', DailyCardController::class . ':list');
        $group->post('/daily-cards[/]', DailyCardController::class . ':draw');
    })->add(UserAuth::class);

    // ── Plugin account linking (OAuth-style, PKCE public client). `authorize` is the
    // browser consent step (session); `token` is the plugin's public code→token swap.
    $app->group('/plugin', function (RouteCollectorProxy $group) {
        $group->post('/token[/]', PluginAuthController::class . ':token');
        $group->post('/authorize[/]', PluginAuthController::class . ':authorize')->add(UserAuth::class);
        // Draw keys for an unlinked plugin, the same way round: the key page's
        // `authorize` needs a passed Turnstile challenge instead of a session.
        $group->post('/draw-keys/authorize[/]', DrawKeyController::class . ':authorize');
        $group->post('/draw-keys/token[/]', DrawKeyController::class . ':token');
    });

    // ── Spreads (public read + public submission; copying one is the signed-in
    // user's own, into My Spreads) ──────────────────────────────────────────────
    $app->group('/spreads', function (RouteCollectorProxy $group) {
        $group->get('[/{spread_id}[/]]', SpreadController::class . ':getSpread');
        $group->post('[/]', SpreadController::class . ':submitSpread');
        $group->post('/{spread_id}/copy[/]', SpreadController::class . ':copySpread')->add(UserAuth::class);
    });

    // ── Contact form (public submission) ─────────────────────────────────────────
    $app->group('/contacts', function (RouteCollectorProxy $group) {
        $group->post('[/]', ContactController::class . ':submit');
    });

    // ── Card reports: image problems + reason (public submission from the lightbox) ─
    $app->group('/card-reports', function (RouteCollectorProxy $group) {
        $group->post('[/]', CardReportController::class . ':report');
    });

    // Public runtime config (non-secret values the SPA needs, e.g. Turnstile site key)
    $app->get('/config[/]', ConfigController::class . ':get');

    // ── Authentication (credentials + session). Action-oriented by design. ───────
    $app->group('/auth', function (RouteCollectorProxy $group) {
        $group->post('/register[/]', UserController::class . ':register');
        $group->post('/activate[/]', UserController::class . ':activate');
        $group->post('/forgot-password[/]', UserController::class . ':forgotPassword');
        $group->post('/reset-password[/]', UserController::class . ':resetPassword');
        $group->post('/login[/]', UserController::class . ':login');
        $group->post('/logout[/]', UserController::class . ':logout');
        $group->get('/me[/]', UserController::class . ':me');
    });

    // Google OAuth (public initiate + callback, authenticated unlink)
    $app->group('/auth/google', function (RouteCollectorProxy $group) {
        $group->get('[/]', GoogleAuthController::class . ':redirect');
        $group->get('/callback[/]', GoogleAuthController::class . ':callback');
        $group->post('/unlink[/]', GoogleAuthController::class . ':unlink')->add(UserAuth::class);
    });

    // Passkeys / WebAuthn. Signing in with one is for signed-out visitors.
    $app->group('/auth/passkeys', function (RouteCollectorProxy $group) {
        $group->post('/login/options[/]', PasskeyController::class . ':loginOptions');
        $group->post('/login[/]', PasskeyController::class . ':login');
    });

    // Registering and managing them is the signed-in account's own business.
    $app->group('/auth/passkeys', function (RouteCollectorProxy $group) {
        $group->post('/register/options[/]', PasskeyController::class . ':registerOptions');
        $group->post('/register[/]', PasskeyController::class . ':register');
        $group->get('[/]', PasskeyController::class . ':list');
        $group->patch('/password-login[/]', PasskeyController::class . ':togglePasswordLogin');
        $group->patch('/{id}[/]', PasskeyController::class . ':rename');
        $group->delete('/{id}[/]', PasskeyController::class . ':delete');
    })->add(UserAuth::class);

    // ── Admin Routes (require a logged-in, active, is_admin user account) ─────────
    $app->group('/admin', function (RouteCollectorProxy $group) {
        $group->get('/auth-check[/]', AdminDashboardController::class . ':checkAuth');

        // Dashboard usage stats + one-shot summary (counts + stats)
        $group->get('/stats[/]', AdminDashboardController::class . ':getStats');
        $group->get('/summary[/]', AdminDashboardController::class . ':getSummary');

        // Decks. Static routes registered before the {deck_id} placeholder.
        $group->get('/decks[/]', AdminDeckController::class . ':getDecks');
        $group->get('/decks/pending[/]', AdminDeckController::class . ':getPendingDecks');
        $group->post('/decks[/]', AdminDeckController::class . ':createDeck');
        $group->post('/decks/images[/]', AdminDeckController::class . ':generateAllImages');
        // Special cards, nested under their deck.
        $group->get('/decks/{deck_id}/special-cards[/]', AdminDeckController::class . ':getSpecialCards');
        $group->post('/decks/{deck_id}/special-cards[/]', AdminDeckController::class . ':createSpecialCard');
        $group->put('/decks/{deck_id}/special-cards/{card_id}[/]', AdminDeckController::class . ':updateSpecialCard');
        $group->delete('/decks/{deck_id}/special-cards/{card_id}[/]', AdminDeckController::class . ':deleteSpecialCard');
        $group->post('/decks/{deck_id}/approve[/]', AdminDeckController::class . ':approveDeck');
        $group->post('/decks/{deck_id}/images[/]', AdminDeckController::class . ':generateDeckImages');
        $group->patch('/decks/{deck_id}[/]', AdminDeckController::class . ':updateDeck');
        $group->delete('/decks/{deck_id}[/]', AdminDeckController::class . ':deleteDeck');

        // Deck Systems
        $group->get('/deck-systems[/]', AdminDeckSystemController::class . ':getDeckSystems');
        $group->get('/deck-systems/pending[/]', AdminDeckSystemController::class . ':getPendingDeckSystems');
        $group->get('/deck-systems/{id}[/]', AdminDeckSystemController::class . ':getDeckSystem');
        $group->post('/deck-systems/{id}/approve[/]', AdminDeckSystemController::class . ':approveDeckSystem');
        $group->put('/deck-systems/{id}[/]', AdminDeckSystemController::class . ':updateDeckSystem');
        $group->delete('/deck-systems/{id}[/]', AdminDeckSystemController::class . ':deleteDeckSystem');

        // Spreads
        $group->get('/spreads[/]', AdminSpreadController::class . ':getSpreads');
        $group->post('/spreads[/]', AdminSpreadController::class . ':createSpread');
        $group->get('/spreads/{spread_id}[/]', AdminSpreadController::class . ':getSpread');
        $group->put('/spreads/{spread_id}[/]', AdminSpreadController::class . ':updateSpread');
        $group->delete('/spreads/{spread_id}[/]', AdminSpreadController::class . ':deleteSpread');

        // Spread library categories. The static /order route before {category_id}.
        $group->get('/spread-categories[/]', AdminSpreadCategoryController::class . ':list');
        $group->post('/spread-categories[/]', AdminSpreadCategoryController::class . ':create');
        $group->put('/spread-categories/order[/]', AdminSpreadCategoryController::class . ':reorder');
        $group->patch('/spread-categories/{category_id}[/]', AdminSpreadCategoryController::class . ':rename');
        $group->delete('/spread-categories/{category_id}[/]', AdminSpreadCategoryController::class . ':delete');

        // Pending Spreads (user submissions awaiting approval)
        $group->get('/pending-spreads[/]', AdminSpreadController::class . ':getPendingSpreads');
        $group->post('/pending-spreads/{pending_id}/approve[/]', AdminSpreadController::class . ':approvePendingSpread');
        $group->delete('/pending-spreads/{pending_id}[/]', AdminSpreadController::class . ':rejectPendingSpread');

        // Readings. Bulk delete ("/all") registered before the {reading_id} placeholder.
        $group->get('/readings[/]', AdminReadingController::class . ':getReadings');
        $group->delete('/readings/all[/]', AdminReadingController::class . ':cleanReadings');
        $group->delete('/readings/{reading_id}[/]', AdminReadingController::class . ':deleteReading');

        // Users (management)
        $group->get('/users[/]', AdminUserController::class . ':getUsers');
        $group->post('/users/{user_id}/activate[/]', AdminUserController::class . ':activateUser');
        $group->post('/users/{user_id}/resend-activation[/]', AdminUserController::class . ':resendActivation');
        $group->patch('/users/{user_id}[/]', AdminUserController::class . ':updateUser');
        $group->delete('/users/{user_id}[/]', AdminUserController::class . ':deleteUser');

        // Contacts (submitted via public contact form)
        $group->get('/contacts[/]', AdminContactController::class . ':getContacts');
        $group->patch('/contacts/{contact_id}[/]', AdminContactController::class . ':updateContact');

        // Card reports (image problems + reasons, submitted from the card lightbox)
        $group->get('/card-reports[/]', AdminCardReportController::class . ':getCardReports');
        $group->patch('/card-reports/{report_id}[/]', AdminCardReportController::class . ':updateCardReport');
        $group->post('/card-reports/{report_id}/regenerate[/]', AdminCardReportController::class . ':regenerateCard');
    })->add(AdminAuth::class);

    return $app;
};
