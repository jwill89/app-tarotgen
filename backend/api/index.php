<?php

// Required Autoloader
require_once('../vendor/autoload.php');

// Timestamps are UTC everywhere (Tarot\Utility\Clock, SQLite's CURRENT_TIMESTAMP);
// pin PHP's zone too, so any stray date() — ours or a library's — agrees (AUDIT §1.4).
date_default_timezone_set('UTC');

use DI\ContainerBuilder;
use Tarot\Config\Env;
use Tarot\Config\Paths;
use Tarot\Utility\AfterResponse;

// Load environment variables (from TAROT_DATA_DIR in production — outside the
// web root — or the project root in dev; see Tarot\Config\Paths).
Env::load(Paths::envFile());

$is_production = Env::isProduction();

// Harden session cookies before any session is started.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $is_production,
]);

// Build the PHP-DI container with autowiring plus our one explicit definition
// (just PDO; everything else is autowired). In production, compile the container
// to disk so definition resolution isn't recomputed on every request.
$builder = new ContainerBuilder();
$builder->useAutowiring(true);
$builder->addDefinitions(__DIR__ . '/dependencies.php');

if ($is_production) {
    $builder->enableCompilation(sys_get_temp_dir() . '/tarot_di_cache');
}

// Routes, middleware and error handling live in app.php, which the HTTP tests
// build too.
$container = $builder->build();
$app = (require __DIR__ . '/app.php')($container, $is_production);

// Run the App
$app->run();

// Then what a request queued to do once its answer was sent (account emails).
$container->get(AfterResponse::class)->run();
