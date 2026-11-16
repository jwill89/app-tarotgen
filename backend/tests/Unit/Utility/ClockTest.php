<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Data\UserData;
use Tarot\Utility\Clock;

/**
 * Timestamps PHP writes must match SQLite's UTC clock whatever the host's zone.
 * Each test runs PHP in UTC+14 so a zone-dependent write would be off by
 * fourteen hours, not a few seconds.
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

    /** The form a DATETIME column's CHECK holds $timestamp to, which must be $timestamp itself. */
    private function checkedForm(string $timestamp): mixed
    {
        $stmt = $this->db->prepare("SELECT strftime('%Y-%m-%dT%H:%M:%SZ', :t)");
        $stmt->execute([':t' => $timestamp]);

        return $stmt->fetchColumn();
    }

    public function testNowIsRfc3339InUtcAndMatchesSqlitesClock(): void
    {
        $now = Clock::now();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $now);
        $this->assertSame($now, $this->checkedForm($now));
        $this->assertLessThanOrEqual(2, $this->secondsFromSqliteNow($now));
    }

    public function testInIsOffsetFromNowInTheSameZone(): void
    {
        $in = Clock::in(3600);
        $later = strtotime($in);
        $now   = strtotime(Clock::now());
        $this->assertIsInt($later);
        $this->assertIsInt($now);

        $this->assertSame($in, $this->checkedForm($in));
        $this->assertEqualsWithDelta(3600, $later - $now, 1);
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
        $this->assertSame($user->registered_at, $this->checkedForm($user->registered_at));
        $this->assertLessThanOrEqual(2, $this->secondsFromSqliteNow($user->registered_at));
    }
}
