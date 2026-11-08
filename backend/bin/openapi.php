<?php

// `composer docs`: writes openapi.json from the swagger-php attributes. A dev
// tool (swagger-php is a dev dependency); deploy.ps1 doesn't ship bin/.

require __DIR__ . '/../vendor/autoload.php';

use OpenApi\Loggers\CollectingLogger;
use Routes\OpenApiSpec;

$backend = dirname(__DIR__);
$log = new CollectingLogger();
$result = OpenApiSpec::builder($backend, $log)->build();

foreach ($log->entries() as ['level' => $level, 'message' => $message]) {
    if ($level !== 'debug' && $level !== 'info') {
        fwrite(STDERR, "{$level}: {$message}\n");
    }
}
if (!$result->isValid()) {
    exit(1);
}

$result->saveAs($backend . '/openapi.json', 'json');
echo "openapi.json written.\n";
