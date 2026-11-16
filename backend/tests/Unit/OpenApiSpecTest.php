<?php

namespace Tarot\Tests\Unit;

use OpenApi\Builder;
use OpenApi\Loggers\CollectingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Routes\OpenApiSpec;
use Routes\ProblemResponses;

/**
 * Guards that the committed backend/openapi.json stays in sync with the
 * swagger-php attributes on the controllers and Structure classes.
 *
 * If this fails, someone changed a route/schema without regenerating the spec:
 * run `composer docs` and commit the updated openapi.json. This is the PHP
 * analogue of a "spec is current" CI check.
 */
#[CoversClass(ProblemResponses::class)]
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

    public function testEveryErrorResponseIsProblemDetailsAndEveryRateLimitSaysWhenToRetry(): void
    {
        $spec = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        $schemas = $spec['components']['schemas'];

        $errors = 0;
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                foreach ($operation['responses'] ?? [] as $status => $response) {
                    if ((int) $status < 400) {
                        continue;
                    }
                    $errors++;
                    $where = "{$method} {$path} {$status}";

                    self::assertSame(['application/problem+json'], array_keys($response['content'] ?? []), $where);
                    $ref = basename((string) ($response['content']['application/problem+json']['schema']['$ref'] ?? ''));
                    $schema = $schemas[$ref] ?? null;
                    self::assertIsArray($schema, "{$where}: no schema {$ref}");
                    // ProblemDetails, or a problem with members of its own.
                    $base = $ref === 'ProblemDetails' ? $ref : basename((string) ($schema['allOf'][0]['$ref'] ?? ''));
                    self::assertSame('ProblemDetails', $base, $where);

                    if ((int) $status === 429) {
                        self::assertArrayHasKey('Retry-After', $response['headers'] ?? [], $where);
                    }
                }
            }
        }
        self::assertGreaterThan(100, $errors);
        self::assertSame(['type', 'title', 'status', 'detail'], $schemas['ProblemDetails']['required']);
    }
}
