<?php

/**
 * Local dev router for PHP's built-in server, mirroring the production
 * .htaccess rules just enough for the Vite dev proxy.
 *
 * Vite (npm run dev) serves the SPA and proxies `/api` and `/assets` to
 * http://localhost:80. In production Apache routes those (api/.htaccess sends
 * /api/* to api/index.php; static files under /assets are served directly).
 * The built-in server has no .htaccess, so this script reproduces that routing.
 *
 * Run from backend/ (document root = backend/):
 *     php -S localhost:80 dev-router.php
 * or via `composer dev`.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Static assets (deck images, favicon, etc.) live under /assets at the document
// root. Returning false lets the built-in server serve the file from disk.
if (str_starts_with($uri, '/assets/')) {
    return is_file(__DIR__ . $uri) ? false : http_not_found();
}

// Everything under /api is handled by the Slim app. index.php loads the
// autoloader via a path relative to its own directory, so run it with the CWD
// set to api/ (matching how Apache executes it in production).
if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    chdir(__DIR__ . '/api');
    require __DIR__ . '/api/index.php';
    return true;
}

return http_not_found();

function http_not_found(): bool
{
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"Not found (dev-router): only /api and /assets are served here."}';
    return true;
}
