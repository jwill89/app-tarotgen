<?php

/**
 * Local dev router for PHP's built-in server, mirroring the production
 * .htaccess rules just enough for the Vite dev proxy.
 *
 * Vite (npm run dev) serves the SPA and proxies `/api` and `/assets` to
 * http://localhost:80. In production Apache routes those (public/api/.htaccess
 * sends /api/* to public/api/index.php; static files under /assets are served
 * directly). The built-in server has no .htaccess, so this script reproduces
 * that routing.
 *
 * Run from backend/, with public/ as the document root:
 *     php -S localhost:80 -t public dev-router.php
 * or via `composer dev`.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Static assets (deck images, favicon, etc.) live under /assets in the document
// root. Returning false lets the built-in server serve the file from disk.
if (str_starts_with($uri, '/assets/')) {
    return is_file(__DIR__ . '/public' . $uri) ? false : http_not_found();
}

// Everything under /api is handled by the Slim app, run from its own folder as
// Apache runs it in production.
if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    chdir(__DIR__ . '/public/api');
    require __DIR__ . '/public/api/index.php';
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
