<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Routes\Problem;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Utility\ContentSecurityPolicy;

/**
 * The raw OpenAPI 3.1 spec (generated from the swagger-php attributes via
 * `composer docs`) and the Swagger UI reference that renders it. Both are public,
 * safe GETs and are deliberately NOT part of the documented API surface.
 */
class ApiDocsController extends AbstractController
{
    /**
     * Where the SPA's build serves Swagger UI's files, copied from the swagger-ui-dist
     * package the frontend pins (frontend/config/swagger-ui-plugin.ts), beside the
     * script that starts it (frontend/public/swagger-ui/api-docs.js). The reference
     * runs on our origin, where a signed-in admin's cookies live, so it loads nothing
     * from another host.
     */
    public const string SWAGGER_UI = '/swagger-ui/';

    public function spec(Request $request, Response $response): Response|ResponseInterface
    {
        $path = dirname(__DIR__, 3) . '/openapi.json';
        $spec = is_file($path) ? file_get_contents($path) : false;

        if ($spec === false) {
            return Problem::respond($response, 500, 'API specification has not been generated. Run: composer docs');
        }

        $response->getBody()->write($spec);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function reference(Request $request, Response $response): Response|ResponseInterface
    {
        $files = self::SWAGGER_UI;
        $response->getBody()->write(<<<HTML
            <!doctype html>
            <html lang="en">
              <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>TarotGen.io — API Reference</title>
                <link rel="stylesheet" href="{$files}swagger-ui.css" />
              </head>
              <body>
                <main id="reference"></main>
                <script src="{$files}swagger-ui-bundle.js"></script>
                <script src="{$files}api-docs.js"></script>
              </body>
            </html>
            HTML);

        // Our own files only — plus a nonce, because Cloudflare injects its analytics
        // beacon and bot-detection script into this page too and stamps the header's
        // nonce on them (as for every other page; see ContentSecurityPolicy). Swagger UI
        // sets its styles through the CSSOM and its icons are data: URIs in its
        // stylesheet; it fetches the spec and sends "Try it out" calls to this origin.
        $nonce = ContentSecurityPolicy::nonce();
        $csp = "default-src 'none'; "
            . "script-src 'self' 'nonce-{$nonce}' https://static.cloudflareinsights.com; "
            . "style-src 'self'; img-src 'self' data:; connect-src 'self'; "
            . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', $csp);
    }
}
