<?php

namespace Tarot\Tests\Unit;

use OpenApi\Builder;
use OpenApi\Loggers\CollectingLogger;
use PHPUnit\Framework\TestCase;
use Routes\OpenApiSpec;

/**
 * Guards that the committed backend/openapi.json stays in sync with the
 * swagger-php attributes on the controllers and Structure classes.
 *
 * If this fails, someone changed a route/schema without regenerating the spec:
 * run `composer docs` and commit the updated openapi.json. This is the PHP
 * analogue of a "spec is current" CI check.
 */
final class OpenApiSpecTest extends TestCase
{
    public function testCommittedSpecMatchesTheAttributes(): void
    {
        if (!class_exists(Builder::class)) {
            self::markTestSkipped('zircote/swagger-php is not installed.');
        }

        $backend  = dirname(__DIR__, 2);
        $specPath = $backend . '/openapi.json';

        self::assertFileExists($specPath, 'openapi.json is missing — run `composer docs`.');

        // The same build `composer docs` runs (bin/openapi.php).
        $log = new CollectingLogger();
        $result = OpenApiSpec::builder($backend, $log)->build();
        self::assertTrue($result->isValid(), implode("\n", array_column($log->entries(), 'message')));

        $generated = json_decode($result->toJson(), true);
        $committed = json_decode((string) file_get_contents($specPath), true);

        self::assertSame(
            $committed,
            $generated,
            'backend/openapi.json is out of date. Run `composer docs` and commit the result.'
        );
    }
}
