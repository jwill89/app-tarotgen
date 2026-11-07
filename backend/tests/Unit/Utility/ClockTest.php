<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\UserData;
use Tarot\Utility\Clock;

/**
 * Timestamps PHP writes must match SQLite's UTC clock whatever the host's zone
 * (AUDIT §1.4). Each test runs PHP in UTC+14 so a zone-dependent write would be
 * off by fourteen hours, not a few seconds.
 */
#[CoversClass(Clock::class)]
#[CoversClass(UserData::class)]
final class ClockTest extends TestCase
{
    private string $zone;
    private PDO $db;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    /** Seconds between a stored timestamp and SQLite's own UTC now. */
    private function secondsFromSqliteNow(string $timestamp): int
    {
        $stmt = $this->db->prepare("SELECT CAST(strftime('%s', 'now') - strftime('%s', :t) AS INTEGER)");
        $stmt->execute([':t' => $timestamp]);

        return abs((int) $stmt->fetchColumn());
    }

    public function testNowMatchesSqliteCurrentTimestamp(): void
    {
        $this->assertLessThanOrEqual(2, $this->secondsFromSqliteNow(Clock::now()));
    }

    public function testInIsOffsetFromNowInTheSameZone(): void
    {
        $later = strtotime(Clock::in(3600) . ' UTC');
        $now   = strtotime(Clock::now() . ' UTC');
        $this->assertIsInt($later);
        $this->assertIsInt($now);

        $this->assertSame(3600, $later - $now);
    }

    public function testTodayIsTheUtcDate(): void
    {
        $today = $this->db->prepare("SELECT date('now')");
        $today->execute();
        $this->assertSame((string) $today->fetchColumn(), Clock::today());
    }

    public function testDataLayerWritesUtcTimestamps(): void
    {
        // A real write path: registration stamps registered_at in PHP.
        $this->db->exec((string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.sql'));
        $user = new UserData($this->db)->create('clock@example.test', 'Clock', 'not-a-real-hash');

        $this->assertNotNull($user);
        $this->assertLessThanOrEqual(2, $this->secondsFromSqliteNow($user->registered_at));
    }
}
