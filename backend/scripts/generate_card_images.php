<?php

/**
 * Generate missing or stale web images (full-size display WebP + 400 px
 * thumbnail) from the decks' master PNG scans. Same work as the admin
 * "Generate web images" action, but with no request time limit — use it for
 * a first backfill, or after uploading a deck's scans over SFTP.
 *
 * Run as the web-server user so the files it writes stay writable by the
 * admin action, and at low priority on a small droplet:
 *   runuser -u www-data -- nice -n 19 php scripts/generate_card_images.php          # every deck folder
 *   runuser -u www-data -- nice -n 19 php scripts/generate_card_images.php 3 12     # just decks 3 and 12
 *
 * Incremental: current images are skipped, so re-running is cheap and safe.
 * Exits 1 if any card failed (unreadable PNG, unwritable folder).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Tarot\Service\CardImageService;

$decksRoot = dirname(__DIR__) . '/assets/decks';

$deckIds = array_map(intval(...), array_slice($argv, 1));
if ($deckIds === []) {
    foreach (glob($decksRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (ctype_digit(basename($dir))) {
            $deckIds[] = (int) basename($dir);
        }
    }
    sort($deckIds);
}

$service = new CardImageService($decksRoot);
$totals = ['generated' => 0, 'skipped' => 0, 'failed' => 0];
$started = microtime(true);

foreach ($deckIds as $deckId) {
    $t = microtime(true);
    $r = $service->generateForDeck($deckId);
    printf(
        "deck %3d: %3d generated, %3d skipped, %d failed (%.0fs)\n",
        $deckId,
        $r['generated'],
        $r['skipped'],
        $r['failed'],
        microtime(true) - $t
    );
    $totals['generated'] += $r['generated'];
    $totals['skipped'] += $r['skipped'];
    $totals['failed'] += $r['failed'];
}

printf(
    "done: %d deck(s), %d generated, %d skipped, %d failed in %.0fs\n",
    count($deckIds),
    $totals['generated'],
    $totals['skipped'],
    $totals['failed'],
    microtime(true) - $started
);

exit($totals['failed'] > 0 ? 1 : 0);
