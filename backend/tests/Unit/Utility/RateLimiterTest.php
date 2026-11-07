<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Utility\RateLimiter;

/**
 * Exercises the file-backed, IP-keyed rate limiter that guards register / login
 * / forgot-password. Uses a unique namespace per test run and removes the
 * backing temp file afterwards so runs stay independent.
 */
#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    private string $namespace;

    protected function setUp(): void
    {
        // Unique per test so a leftover file can never bleed across tests.
        $this->namespace = 'test_' . bin2hex(random_bytes(6));
        $this->removeBackingFile();
    }

    protected function tearDown(): void
    {
        $this->removeBackingFile();
    }

    private function removeBackingFile(): void
    {
        $file = $this->backingFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Mirrors RateLimiter::file(): the namespace is sanitised to [A-Za-z0-9_]. */
    private function backingFile(): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $this->namespace);
        $safe = $safe === null || $safe === '' ? 'default' : $safe;
        return sys_get_temp_dir() . '/tarot_rl_' . $safe . '.json';
    }

    public function testMalformedEntriesInTheFileAreIgnored(): void
    {
        // The file sits in a shared temp dir: a stray or half-written entry must
        // neither throw nor count — not even a numeric string a cast would accept.
        file_put_contents($this->backingFile(), json_encode([
            'good'    => ['count' => 5, 'first' => time()],
            'stringy' => ['count' => '5', 'first' => time()],
            'partial' => ['count' => 5],
            'scalar'  => 7,
        ]));
        $limiter = new RateLimiter($this->namespace, 3, 3600);

        $this->assertTrue($limiter->isLimited('good'));
        $this->assertFalse($limiter->isLimited('stringy'));
        $this->assertFalse($limiter->isLimited('partial'));
        $this->assertFalse($limiter->isLimited('scalar'));

        // The next write keeps only well-formed entries.
        $limiter->hit('stringy');
        $stored = json_decode((string) file_get_contents($this->backingFile()), true);
        $this->assertIsArray($stored);
        $this->assertSame(['good', 'stringy'], array_keys($stored));
        $this->assertSame(1, $stored['stringy']['count']);
    }

    public function testUnknownKeyIsNotLimited(): void
    {
        $limiter = new RateLimiter($this->namespace, 3, 3600);
        $this->assertFalse($limiter->isLimited('1.2.3.4'));
    }

    public function testBecomesLimitedOnlyAtTheThreshold(): void
    {
        $limiter = new RateLimiter($this->namespace, 3, 3600);
        $ip = '1.2.3.4';

        $limiter->hit($ip); // 1
        $this->assertFalse($limiter->isLimited($ip));
        $limiter->hit($ip); // 2
        $this->assertFalse($limiter->isLimited($ip));
        $limiter->hit($ip); // 3 → reaches max
        $this->assertTrue($limiter->isLimited($ip));
    }

    public function testKeysAreTrackedIndependently(): void
    {
        $limiter = new RateLimiter($this->namespace, 2, 3600);
        $limiter->hit('10.0.0.1');
        $limiter->hit('10.0.0.1'); // 10.0.0.1 now limited

        $this->assertTrue($limiter->isLimited('10.0.0.1'));
        $this->assertFalse($limiter->isLimited('10.0.0.2'));
    }

    public function testClearResetsAKey(): void
    {
        $limiter = new RateLimiter($this->namespace, 2, 3600);
        $ip = '1.2.3.4';
        $limiter->hit($ip);
        $limiter->hit($ip);
        $this->assertTrue($limiter->isLimited($ip));

        $limiter->clear($ip);
        $this->assertFalse($limiter->isLimited($ip));
    }

    public function testWindowExpiryResetsTheCount(): void
    {
        // A 1-second window: after it elapses, the prior hits no longer count.
        $limiter = new RateLimiter($this->namespace, 1, 1);
        $ip = '1.2.3.4';

        $limiter->hit($ip);
        $this->assertTrue($limiter->isLimited($ip), 'limited within the window');

        sleep(2); // let the window lapse
        $this->assertFalse($limiter->isLimited($ip), 'window expired → no longer limited');

        // A fresh hit starts a brand-new window/count rather than incrementing.
        $limiter->hit($ip);
        $this->assertTrue($limiter->isLimited($ip));
    }

    public function testMaxHitsAndWindowAreFlooredToOne(): void
    {
        // Constructor floors maxHits/window to >= 1; a "0 max" limiter still
        // trips after a single hit rather than being permanently open.
        $limiter = new RateLimiter($this->namespace, 0, 0);
        $this->assertFalse($limiter->isLimited('1.2.3.4'));
        $limiter->hit('1.2.3.4');
        $this->assertTrue($limiter->isLimited('1.2.3.4'));
    }
}
