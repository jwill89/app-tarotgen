<?php

/**
 * Builds what the Playwright suite (playwright.config.ts) serves, before its
 * server starts:
 *
 * - a throwaway data directory (TAROT_DATA_DIR): a .env and a database the
 *   backend's migrations build (Tarot\Database\Schema::migrate() on an empty
 *   file, as scripts/dump_schema.php builds db/schema.sql), seeded with one deck
 *   system, one usable deck, one spread, a guest's reading drawn from them and
 *   an admin account; e2e.json beside it holds the admin's random password and
 *   the reading's id for the specs to read;
 * - backend/dist: a copy of the built SPA, where og.php looks for the app shell
 *   (in the deployed web root, dist/ sits beside og.php).
 *
 * The data directory is deleted and made again, so a path inside the checkout's
 * backend/db/ (where the local database lives) or one holding it is refused, and
 * nothing here opens a database through Paths or TAROT_DATA_DIR: every class is
 * handed the new database's own connection.
 *
 * Usage: php prepare.php <data-dir> <base-url>
 */

declare(strict_types=1);

use Tarot\Data\DeckData;
use Tarot\Data\DeckSystemData;
use Tarot\Data\ReadingData;
use Tarot\Data\SpecialCardData;
use Tarot\Data\SpreadData;
use Tarot\Data\UserData;
use Tarot\Data\UserSpreadData;
use Tarot\Database\Schema;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\DeckSystemRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\SpecialCardRepository;
use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Service\CardNameResolver;
use Tarot\Service\ReadingService;

$frontend = dirname(__DIR__, 2);
$backend = dirname($frontend) . '/backend';
require $backend . '/vendor/autoload.php';

[, $dataDir, $baseUrl] = $argv + [null, '', ''];
if ($dataDir === '' || $baseUrl === '') {
    fwrite(STDERR, "Usage: php prepare.php <data-dir> <base-url>\n");
    exit(1);
}
if (!is_file($frontend . '/dist/index.html')) {
    fwrite(STDERR, "frontend/dist is missing: run `npm run build` first.\n");
    exit(1);
}
$dataDir = absolutePath($dataDir);
$localDb = absolutePath($backend . '/db');
if (contains($dataDir, $localDb) || contains($localDb, $dataDir)) {
    fwrite(STDERR, "Refusing {$dataDir}: the run deletes its data directory, and {$localDb} holds the local database. Use a folder outside it, as e2e/env.ts does.\n");
    exit(1);
}

removeTree($dataDir);
mkdir($dataDir . '/db', 0o777, true);
// The server's sys_temp_dir (playwright.config.ts): fresh rate-limit counts per run.
mkdir($dataDir . '/tmp');
// Development, so the session cookie isn't Secure-only over plain http.
file_put_contents($dataDir . '/.env', "APP_ENV=development\nAPP_URL={$baseUrl}\n");

// Phinx never creates a database: it migrates the empty file made here.
$path = $dataDir . '/db/tarotdb.db';
touch($path);
Schema::migrate($path);

$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

$system = new DeckSystemData($db)->store(['name' => 'Rider-Waite-Smith', 'short_name' => 'RWS', 'total_cards' => 78, 'approved' => true]);
if ($system === null) {
    throw new RuntimeException('Could not seed the deck system.');
}
new DeckSystemData($db)->storeCards($system->deck_system_id, cardNames());
$deck = new DeckData($db)->store(['name' => 'Smoke Test Deck', 'artist' => 'E2E', 'deck_system_id' => $system->deck_system_id, 'approved' => true, 'usable' => true]);
$spread = new SpreadData($db)->store([
    'name' => 'Past, Present, Future',
    'description' => 'Where a question has come from, where it stands and where it is heading.',
    'positions' => [
        ['order' => 1, 'title' => 'Past', 'x' => 25, 'y' => 50, 'rotation' => 0],
        ['order' => 2, 'title' => 'Present', 'x' => 50, 'y' => 50, 'rotation' => 0],
        ['order' => 3, 'title' => 'Future', 'x' => 75, 'y' => 50, 'rotation' => 0],
    ],
]);
if ($deck === null || $spread === null) {
    throw new RuntimeException('Could not seed the deck and spread.');
}

// A guest's reading in that spread, drawn as the draw page draws one, for the reading page.
$readings = new ReadingService(
    new ReadingRepository(new ReadingData($db)),
    new DeckRepository(new DeckData($db)),
    new SpreadRepository(new SpreadData($db)),
    new UserSpreadRepository(new UserSpreadData($db)),
    new CardNameResolver(new DeckSystemRepository(new DeckSystemData($db)), new SpecialCardRepository(new SpecialCardData($db))),
);
$reading = $readings->generate(['deck_id' => $deck->deck_id, 'spread_id' => $spread->spread_id]);

$users = new UserRepository(new UserData($db));
$password = bin2hex(random_bytes(12));
$admin = $users->create('e2e-admin@tarotgen.test', 'E2E Admin', password_hash($password, PASSWORD_DEFAULT));
if ($admin === null) {
    throw new RuntimeException('Could not seed the admin account.');
}
$users->activate($admin->user_id);
$users->setAdmin($admin->user_id, true);
file_put_contents($dataDir . '/e2e.json', json_encode(['email' => $admin->email, 'password' => $password, 'reading' => $reading->reading_id], JSON_THROW_ON_ERROR));
unset($db);

removeTree($backend . '/dist');
copyTree($frontend . '/dist', $backend . '/dist');

echo "E2E data in {$dataDir}\n";

/**
 * $path made absolute, with `.` and `..` resolved and `/` separators. It need not
 * exist: its nearest existing parent goes through realpath(), so a link can't
 * lead out of a check made on the name.
 */
function absolutePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if (preg_match('#^(?:[A-Za-z]:)?/#', $path) !== 1) {
        $path = str_replace('\\', '/', (string)getcwd()) . '/' . $path;
    }
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '..') {
            array_pop($parts);
        } elseif ($part !== '.' && $part !== '') {
            $parts[] = $part;
        }
    }
    $missing = [];
    while ($parts !== []) {
        $real = realpath((preg_match('#^[A-Za-z]:$#', $parts[0]) === 1 ? '' : '/') . implode('/', $parts));
        if ($real !== false) {
            return rtrim(str_replace('\\', '/', $real), '/') . ($missing === [] ? '' : '/' . implode('/', array_reverse($missing)));
        }
        $missing[] = array_pop($parts);
    }

    return '/' . implode('/', array_reverse($missing));
}

/** Whether $path is $dir or inside it (ignoring case where the file system does). */
function contains(string $dir, string $path): bool
{
    if (PHP_OS_FAMILY === 'Windows' || PHP_OS_FAMILY === 'Darwin') {
        [$dir, $path] = [strtolower($dir), strtolower($path)];
    }

    return $path === $dir || str_starts_with($path, rtrim($dir, '/') . '/');
}

/** @return list<array{card_id: int, name: string}> */
function cardNames(): array
{
    $names = [
        'The Fool', 'The Magician', 'The High Priestess', 'The Empress', 'The Emperor', 'The Hierophant',
        'The Lovers', 'The Chariot', 'Strength', 'The Hermit', 'Wheel of Fortune', 'Justice', 'The Hanged Man',
        'Death', 'Temperance', 'The Devil', 'The Tower', 'The Star', 'The Moon', 'The Sun', 'Judgement', 'The World',
    ];
    $ranks = ['Ace', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Page', 'Knight', 'Queen', 'King'];
    foreach (['Wands', 'Cups', 'Swords', 'Pentacles'] as $suit) {
        foreach ($ranks as $rank) {
            $names[] = "{$rank} of {$suit}";
        }
    }

    return array_map(static fn(int $i): array => ['card_id' => $i + 1, 'name' => $names[$i]], array_keys($names));
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function copyTree(string $from, string $to): void
{
    mkdir($to, 0o777, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $target = $to . substr($entry->getPathname(), strlen($from));
        $entry->isDir() ? mkdir($target) : copy($entry->getPathname(), $target);
    }
}
