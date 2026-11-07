<?php

declare(strict_types=1);

namespace Tarot\Tests\Unit\Utility;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tarot\Utility\DbSessionHandler;

/**
 * Exercises the database-backed session store against an in-memory SQLite
 * database: round-trip, hashed-id-at-rest, sliding expiry, hard expiry,
 * garbage collection, the strict-mode validateId() contract, and keeping track
 * of whose session each row is so a user's sessions can be ended.
 */
#[CoversClass(DbSessionHandler::class)]
final class DbSessionHandlerTest extends TestCase
{
    private PDO $pdo;
    private DbSessionHandler $handler;

    /** A representative raw session id and its at-rest storage key. */
    private const string SID = 'abc123sessionid';

    protected function setUp(): void
    {
        // Mirror db/migrate_sessions.php + migrate_sessions_user_id.php.
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE sessions (
                id      TEXT    PRIMARY KEY,
                data    TEXT    NOT NULL DEFAULT '',
                expires INTEGER NOT NULL,
                user_id INTEGER DEFAULT NULL
            )"
        );

        // Default to a non-"remember me" session for most tests.
        $_SESSION = [];
        $this->handler = new DbSessionHandler($this->pdo);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function storedKey(): string
    {
        return hash('sha256', self::SID);
    }

    private function expiresOf(string $rawId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT expires FROM sessions WHERE id = :id');
        $stmt->execute([':id' => hash('sha256', $rawId)]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (int) $val;
    }

    /** The user_id stored for $rawId's row (null: none, or no row). */
    private function ownerOf(string $rawId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM sessions WHERE id = :id');
        $stmt->execute([':id' => hash('sha256', $rawId)]);
        $val = $stmt->fetchColumn();
        return $val === false || $val === null ? null : (int) $val;
    }

    private function rowCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sessions');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function testUnknownSessionReadsEmptyAndIsInvalid(): void
    {
        $this->assertSame('', $this->handler->read(self::SID));
        $this->assertFalse($this->handler->validateId(self::SID));
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $this->assertTrue($this->handler->write(self::SID, 'user_id|i:7;'));
        $this->assertSame('user_id|i:7;', $this->handler->read(self::SID));
        $this->assertTrue($this->handler->validateId(self::SID));
    }

    public function testSessionIdIsStoredHashedNotRaw(): void
    {
        $this->handler->write(self::SID, 'x|i:1;');

        $stmt = $this->pdo->prepare('SELECT id FROM sessions');
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains($this->storedKey(), $ids, 'id should be stored as its sha256 hash');
        $this->assertNotContains(self::SID, $ids, 'the raw session id must never be persisted');
    }

    public function testDefaultSessionGetsTheShortIdleWindow(): void
    {
        $this->handler->write(self::SID, 'x|i:1;');
        // ~24h (DEFAULT_LIFETIME), with a couple seconds of execution slack.
        $this->assertEqualsWithDelta(time() + 60 * 60 * 24, $this->expiresOf(self::SID), 5);
    }

    public function testRememberMeSessionGetsTheThirtyDayWindow(): void
    {
        $_SESSION = ['remember_me' => true];
        $this->handler->write(self::SID, 'user_id|i:7;remember_me|b:1;');
        $this->assertEqualsWithDelta(time() + 60 * 60 * 24 * 30, $this->expiresOf(self::SID), 5);
    }

    public function testUpdateTimestampSlidesExpiryForward(): void
    {
        $this->handler->write(self::SID, 'x|i:1;');
        // Backdate the row to near-expiry, then touch it.
        $this->pdo->exec('UPDATE sessions SET expires = ' . (time() + 5));

        $this->assertTrue($this->handler->updateTimestamp(self::SID, 'x|i:1;'));
        $this->assertGreaterThan(time() + 60, $this->expiresOf(self::SID));
    }

    public function testUpdateTimestampSkipsTheWriteWhileTheExpiryIsFresh(): void
    {
        $this->handler->write(self::SID, 'x|i:1;');
        $fresh = time() + 60 * 60 * 24 - 10;
        $this->pdo->exec("UPDATE sessions SET expires = {$fresh}");

        $this->assertTrue($this->handler->updateTimestamp(self::SID, 'x|i:1;'));
        $this->assertSame($fresh, $this->expiresOf(self::SID));
    }

    public function testEachWriteRecordsWhoseSessionItIs(): void
    {
        $_SESSION = ['user_id' => 7];
        $this->handler->write(self::SID, 'user_id|i:7;');
        $this->assertSame(7, $this->ownerOf(self::SID));

        // Signed out (Session::clearUser), the same session belongs to nobody.
        $_SESSION = [];
        $this->handler->write(self::SID, '');
        $this->assertNull($this->ownerOf(self::SID));
    }

    public function testATimestampOnlyRefreshStillCorrectsTheOwner(): void
    {
        // A row from before the column existed: signed in, but owned by nobody.
        $_SESSION = ['user_id' => 7];
        $this->handler->write(self::SID, 'user_id|i:7;');
        $this->pdo->exec('UPDATE sessions SET user_id = NULL');
        $fresh = (int) $this->expiresOf(self::SID);

        // Well inside the throttle window, where only the expiry would be skipped.
        $this->assertTrue($this->handler->updateTimestamp(self::SID, 'user_id|i:7;'));
        $this->assertSame(7, $this->ownerOf(self::SID));
        $this->assertGreaterThanOrEqual($fresh, $this->expiresOf(self::SID));

        $_SESSION = [];
        $this->assertTrue($this->handler->updateTimestamp(self::SID, ''));
        $this->assertNull($this->ownerOf(self::SID));
    }

    public function testDestroyForUserEndsOnlyThatUsersSessions(): void
    {
        $_SESSION = ['user_id' => 7];
        $this->handler->write('laptop', 'user_id|i:7;');
        $this->handler->write('phone', 'user_id|i:7;');
        $this->handler->write('tablet', 'user_id|i:7;');
        $_SESSION = ['user_id' => 8];
        $this->handler->write('someone-else', 'user_id|i:8;');
        $_SESSION = [];
        $this->handler->write('signed-out', '');

        // Every one of user 7's but the one kept.
        $this->assertSame(2, $this->handler->destroyForUser(7, 'laptop'));
        $this->assertSame(7, $this->ownerOf('laptop'));
        $this->assertSame('', $this->handler->read('phone'));
        $this->assertSame(3, $this->rowCount());

        $this->assertSame(1, $this->handler->destroyForUser(7));
        $this->assertSame(0, $this->handler->destroyForUser(7));
        $this->assertSame([8, null], array_map($this->ownerOf(...), ['someone-else', 'signed-out']));
    }

    public function testDestroyForUserCountsOnlyTheSessionsThatWereStillLive(): void
    {
        $_SESSION = ['user_id' => 7];
        $this->handler->write('laptop', 'user_id|i:7;');
        $this->handler->write('phone', 'user_id|i:7;');
        $this->handler->write('old-tablet', 'user_id|i:7;');
        $this->pdo->prepare('UPDATE sessions SET expires = :e WHERE id = :id')
            ->execute([':e' => time() - 1, ':id' => hash('sha256', 'old-tablet')]);

        // The phone was signed in; the tablet's session had already run out.
        $this->assertSame(1, $this->handler->destroyForUser(7, 'laptop'));
        // Both rows go all the same: an expired one is only waiting for gc().
        $this->assertSame(1, $this->rowCount());
        $this->assertSame(7, $this->ownerOf('laptop'));
    }

    public function testExpiredSessionReadsEmptyAndIsInvalid(): void
    {
        $this->handler->write(self::SID, 'x|i:1;');
        $this->pdo->exec('UPDATE sessions SET expires = ' . (time() - 1));

        $this->assertSame('', $this->handler->read(self::SID), 'expired session must not surface data');
        $this->assertFalse($this->handler->validateId(self::SID));
        // Read/validate are non-destructive — the row is left for gc() to reap.
        $this->assertSame(1, $this->rowCount());
    }

    public function testGcRemovesOnlyExpiredRows(): void
    {
        // One live row...
        $this->handler->write('live-session', 'a|i:1;');
        // ...and one expired row.
        $this->handler->write(self::SID, 'b|i:2;');
        $this->pdo->prepare('UPDATE sessions SET expires = :e WHERE id = :id')
            ->execute([':e' => time() - 1, ':id' => $this->storedKey()]);

        $removed = $this->handler->gc(1440);

        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->rowCount());
        $this->assertSame('a|i:1;', $this->handler->read('live-session'), 'live session survives gc');
    }

    public function testDestroyRemovesTheRow(): void
    {
        $this->handler->write(self::SID, 'x|i:1;');
        $this->assertSame(1, $this->rowCount());

        $this->assertTrue($this->handler->destroy(self::SID));
        $this->assertSame(0, $this->rowCount());
    }

    public function testHandlerDegradesGracefullyWhenTableMissing(): void
    {
        // A storage failure (e.g. migration not yet run) must not fatal the
        // request — read degrades to "no session", validateId to false. The
        // handler error_log()s the failure; redirect that to a temp file so the
        // expected diagnostics don't clutter the test output.
        $this->pdo->exec('DROP TABLE sessions');

        $logFile = tempnam(sys_get_temp_dir(), 'sesslog');
        $prevLog = ini_set('error_log', $logFile);
        try {
            $this->assertSame('', $this->handler->read(self::SID));
            $this->assertFalse($this->handler->validateId(self::SID));
            $this->assertFalse($this->handler->write(self::SID, 'x|i:1;'));
            $this->assertFalse($this->handler->gc(1440));
        } finally {
            ini_set('error_log', $prevLog === false ? '' : $prevLog);
            @unlink($logFile);
        }
    }
}
