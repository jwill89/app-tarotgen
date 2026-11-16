<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Config\Paths;

#[CoversClass(Paths::class)]
final class PathsTest extends TestCase
{
    private const string VARIABLE = 'TAROT_DATA_DIR';

    protected function setUp(): void
    {
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
    }

    public function testFallsBackToTheProjectRootWhenUnset(): void
    {
        $backend = dirname(__DIR__, 3);

        $this->assertSame($backend, Paths::dataDir());
        $this->assertSame($backend . '/.env', Paths::envFile());
        $this->assertSame($backend . '/db/tarotdb.db', Paths::database());
    }

    public function testUsesTheProcessEnvironmentAndTrimsTrailingSlashes(): void
    {
        putenv(self::VARIABLE . '=/srv/example-data/');

        $this->assertSame('/srv/example-data', Paths::dataDir());
        $this->assertSame('/srv/example-data/.env', Paths::envFile());
        $this->assertSame('/srv/example-data/db/tarotdb.db', Paths::database());
    }

    public function testApacheSetEnvInServerWinsOverTheProcessEnvironment(): void
    {
        putenv(self::VARIABLE . '=/from/getenv');
        $_SERVER[self::VARIABLE] = '/from/setenv';

        $this->assertSame('/from/setenv', Paths::dataDir());
    }

    public function testAnEmptyValueMeansUnset(): void
    {
        putenv(self::VARIABLE . '=');

        $this->assertSame(dirname(__DIR__, 3), Paths::dataDir());
    }

    private function clear(): void
    {
        putenv(self::VARIABLE);
        unset($_SERVER[self::VARIABLE]);
    }
}
