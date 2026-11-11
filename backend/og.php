<?php

/**
 * The HTML entry point for every page route (see .htaccess). Serves the built
 * app shell, dist/index.html, with the two things only a server can add:
 *
 * - A Content-Security-Policy carrying a fresh nonce per response — Cloudflare
 *   needs it to inject its scripts under a strict policy (see
 *   Tarot\Utility\ContentSecurityPolicy). Hence PHP for every page, not just
 *   shares; the shell is never cached anyway.
 * - For /reading/{id}, social meta: the SPA can't set Open Graph tags for
 *   crawlers, so the generic <title> and og:/twitter: tags are swapped for
 *   reading-specific ones (Tarot\Service\ReadingShareMeta, which says nothing
 *   about a private or expired reading). The SPA still hydrates as normal for
 *   visitors.
 *
 * Every other page gets the shell exactly as built. (The og.php name is from the
 * Open Graph tags, the part that is specific to a reading.)
 */

require_once __DIR__ . '/vendor/autoload.php';

use Tarot\Config\Env;
use Tarot\Config\Paths;
use Tarot\Data\DeckData;
use Tarot\Data\ReadingData;
use Tarot\Database\Connection;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Service\ReadingShareMeta;
use Tarot\Utility\ContentSecurityPolicy;

$template = @file_get_contents(__DIR__ . '/dist/index.html');
if ($template === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Application is not built.';
    exit;
}

Env::load(Paths::envFile());
$nonce = ContentSecurityPolicy::nonce();
header(ContentSecurityPolicy::headerName(Env::get('CSP_MODE')) . ': ' . ContentSecurityPolicy::forShell($nonce));

// Only a reading's own URL gets share meta (the rewrite leaves REQUEST_URI intact).
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (preg_match('#^/reading/([A-Za-z0-9]+)/?$#', $path, $m)) {
    try {
        $db = Connection::getInstance();
        $meta = new ReadingShareMeta(new ReadingRepository(new ReadingData($db)), new DeckRepository(new DeckData($db)), __DIR__)
            ->describe($m[1]);
    } catch (Throwable) {
        // Keep the generic meta on any failure.
        $meta = ReadingShareMeta::generic(__DIR__, $m[1]);
    }
    $template = ReadingShareMeta::inject($template, $meta);
}

header('Content-Type: text/html; charset=UTF-8');
echo $template;
