<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tarot\Config\Env;

/**
 * How settings are read: the .env file's format, and get()'s rules for unset and
 * empty values. load() runs once per process by design, so each test of it gets
 * a process of its own.
 */
#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    private const array KEYS = ['APP_ENV', 'TAROT_ENV_TEST'];

    /** @var array<string,mixed> */
    private array $saved = [];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->saved[$key] = [$_ENV[$key] ?? null, getenv($key)];
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$env, $process]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            putenv(is_string($process) ? "{$key}={$process}" : $key);
        }

        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testAnUnsetOrEmptyValueFallsBackToTheDefault(): void
    {
        $this->assertNull(Env::get('TAROT_ENV_TEST'));
        $this->assertSame('fallback', Env::get('TAROT_ENV_TEST', 'fallback'));

        // An empty line in .env (KEY=) means "not configured", not "configured as ''".
        $_ENV['TAROT_ENV_TEST'] = '';
        $this->assertNull(Env::get('TAROT_ENV_TEST'));
        $this->assertSame('fallback', Env::get('TAROT_ENV_TEST', 'fallback'));
    }

    public function testTheProcessEnvironmentIsReadWhenDotEnvDoesNotSetAValue(): void
    {
        putenv('TAROT_ENV_TEST=from-the-server');
        $this->assertSame('from-the-server', Env::get('TAROT_ENV_TEST'));

        $_ENV['TAROT_ENV_TEST'] = 'from-dotenv';
        $this->assertSame('from-dotenv', Env::get('TAROT_ENV_TEST'));
    }

    public function testOnlyAppEnvProductionIsProduction(): void
    {
        $this->assertFalse(Env::isProduction());

        $_ENV['APP_ENV'] = 'development';
        $this->assertFalse(Env::isProduction());

        $_ENV['APP_ENV'] = 'production';
        $this->assertTrue(Env::isProduction());

        $_ENV['APP_ENV'] = 'productions';
        $this->assertFalse(Env::isProduction());
    }

    public function testProductionIsRecognisedWhateverItsCaseAndSurroundingSpace(): void
    {
        $_ENV['APP_ENV'] = 'Production';
        $this->assertTrue(Env::isProduction());

        $_ENV['APP_ENV'] = 'PRODUCTION';
        $this->assertTrue(Env::isProduction());

        // load() trims a .env line, but the server's own environment isn't trimmed.
        unset($_ENV['APP_ENV']);
        putenv("APP_ENV= production\t");
        $this->assertTrue(Env::isProduction());
    }

    public function testACommaSeparatedSettingIsAListOfTrimmedNonEmptyEntries(): void
    {
        $this->assertSame([], Env::commaSeparated('TAROT_ENV_TEST'));

        $_ENV['TAROT_ENV_TEST'] = ' a@example.test, ,B@Example.test ,,';
        $this->assertSame(['a@example.test', 'B@Example.test'], Env::commaSeparated('TAROT_ENV_TEST'));

        $_ENV['TAROT_ENV_TEST'] = ' , ';
        $this->assertSame([], Env::commaSeparated('TAROT_ENV_TEST'));
    }

    #[RunInSeparateProcess]
    public function testLoadReadsKeyValueLinesAndSkipsEverythingElse(): void
    {
        Env::load($this->envFile(<<<'DOTENV'
            # A comment, then a blank line

            TAROT_ENV_PLAIN=plain
              TAROT_ENV_SPACED =  spaced value
            TAROT_ENV_DOUBLE="double quoted # not a comment"
            TAROT_ENV_SINGLE='single quoted'
            TAROT_ENV_EQUALS=a=b=c
            TAROT_ENV_MISMATCHED="half quoted'
            TAROT_ENV_EMPTY=
            not a setting
            DOTENV));

        $this->assertSame('plain', Env::get('TAROT_ENV_PLAIN'));
        $this->assertSame('spaced value', Env::get('TAROT_ENV_SPACED'));
        $this->assertSame('double quoted # not a comment', Env::get('TAROT_ENV_DOUBLE'));
        $this->assertSame('single quoted', Env::get('TAROT_ENV_SINGLE'));
        $this->assertSame('a=b=c', Env::get('TAROT_ENV_EQUALS'));
        $this->assertSame('"half quoted\'', Env::get('TAROT_ENV_MISMATCHED'));
        $this->assertNull(Env::get('TAROT_ENV_EMPTY'));
        $this->assertArrayNotHasKey('not a setting', $_ENV);

        // Also put in the process environment, for code that reads getenv().
        $this->assertSame('plain', getenv('TAROT_ENV_PLAIN'));
    }

    #[RunInSeparateProcess]
    public function testOnlyTheFirstFileLoadedCounts(): void
    {
        Env::load($this->envFile('TAROT_ENV_TEST=first'));
        Env::load($this->envFile('TAROT_ENV_TEST=second'));

        $this->assertSame('first', Env::get('TAROT_ENV_TEST'));
    }

    #[RunInSeparateProcess]
    public function testAMissingFileIsNotAnErrorAndDoesNotCountAsLoaded(): void
    {
        Env::load(sys_get_temp_dir() . '/tarot-env-missing-' . bin2hex(random_bytes(4)));
        $this->assertNull(Env::get('TAROT_ENV_TEST'));

        Env::load($this->envFile('TAROT_ENV_TEST=loaded'));
        $this->assertSame('loaded', Env::get('TAROT_ENV_TEST'));
    }

    private function envFile(string $contents): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'tarot-env');
        file_put_contents($file, $contents);
        $this->files[] = $file;
        return $file;
    }
}
