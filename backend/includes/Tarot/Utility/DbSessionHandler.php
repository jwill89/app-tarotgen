<?php

namespace Tarot\Utility;

use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Throwable;

/**
 * Database-backed PHP session storage.
 *
 * Replaces the host's filesystem session store so that an external GC cron or a
 * neighboring vhost (which read the global php.ini gc_maxlifetime, not our
 * per-request override) can no longer delete active sessions out from under us
 * after ~24 minutes. We own expiry and garbage collection here.
 *
 * Implements SessionUpdateTimestampHandlerInterface so that, with lazy_write
 * enabled, an unchanged session still slides its expiry (updateTimestamp) every
 * request instead of forcing a full rewrite — and validateId() lets PHP's
 * strict-mode reject unknown/expired ids (session-fixation defense).
 *
 * GC needs no cron: Session::start() enables PHP's probabilistic GC so gc()
 * runs on a small fraction of requests and sweeps expired rows. A scheduled
 * `DELETE FROM sessions WHERE expires < strftime('%s','now')` remains an
 * optional belt-and-suspenders, but is not required.
 *
 * Each row also records whose session it is (`user_id`, null when signed out),
 * so a password change or reset, or deleting the account, can end that user's
 * other sessions (destroyForUser). It's taken from $_SESSION when PHP saves the
 * session — the same array PHP encodes into $data at that moment, read the way
 * lifetime() reads remember_me — rather than by parsing $data, whose format
 * depends on session.serialize_handler. No foreign key: a request still in
 * flight when its account is deleted must be able to finish its session write.
 */
final readonly class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /**
     * Slide an idle session's stored expiry at most once every WRITE_THROTTLE
     * seconds instead of on every request. The sliding window (lifetime) is
     * hours-to-days, so letting the stored expiry drift by a few minutes never
     * risks a premature logout — but it lets updateTimestamp() skip the UPDATE on
     * the vast majority of requests (a read, not a write), so authenticated calls
     * no longer serialize on SQLite's single writer.
     */
    private const int WRITE_THROTTLE = 300;

    public function __construct(private PDO $db) {}

    #[\Override]
    public function open(string $path, string $name): bool
    {
        return true;
    }

    #[\Override]
    public function close(): bool
    {
        return true;
    }

    /** Return the stored payload for a live session, or '' (new/expired). */
    #[\Override]
    public function read(string $id): string|false
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT data FROM sessions WHERE id = :id AND expires > :now'
            );
            $stmt->execute([':id' => $this->key($id), ':now' => time()]);
            $data = $stmt->fetchColumn();

            return $data === false ? '' : (string) $data;
        } catch (Throwable $e) {
            // A storage hiccup should degrade to "not logged in", never 500 the
            // entire site on every request.
            error_log('DbSessionHandler::read failed: ' . $e->getMessage());
            return '';
        }
    }

    /** Insert or update the session payload with a fresh sliding expiry and its owner. */
    #[\Override]
    public function write(string $id, string $data): bool
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO sessions (id, data, expires, user_id) VALUES (:id, :data, :exp, :user)
                 ON CONFLICT(id) DO UPDATE SET data = excluded.data, expires = excluded.expires, user_id = excluded.user_id'
            );

            return $stmt->execute([
                ':id'   => $this->key($id),
                ':data' => $data,
                ':exp'  => time() + $this->lifetime(),
                ':user' => Session::userId(),
            ]);
        } catch (Throwable $e) {
            error_log('DbSessionHandler::write failed: ' . $e->getMessage());
            return false;
        }
    }

    #[\Override]
    public function destroy(string $id): bool
    {
        try {
            $this->db->prepare('DELETE FROM sessions WHERE id = :id')
                ->execute([':id' => $this->key($id)]);
            return true;
        } catch (Throwable $e) {
            error_log('DbSessionHandler::destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * End every session of $userId, except the one whose (raw) id is $exceptId —
     * the browser making the change, when it should stay signed in. Returns how
     * many were still live: an expired row gc() hasn't swept yet goes too, but it
     * signed nobody in, so "signed out of N other devices" doesn't count it.
     */
    public function destroyForUser(int $userId, ?string $exceptId = null): int
    {
        $params = [':user' => $userId, ':except' => $exceptId === null ? '' : $this->key($exceptId)];

        $live = $this->db->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = :user AND id <> :except AND expires > :now');
        $live->execute($params + [':now' => time()]);
        $ended = (int) $live->fetchColumn();

        $this->db->prepare('DELETE FROM sessions WHERE user_id = :user AND id <> :except')->execute($params);

        return $ended;
    }

    /** Sweep expired rows. Called by PHP's probabilistic GC (see Session::start). */
    #[\Override]
    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM sessions WHERE expires < :now');
            $stmt->execute([':now' => time()]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('DbSessionHandler::gc failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Strict-mode validation: only accept a session id the store already knows
     * and that hasn't expired. Unknown ids cause PHP to mint a fresh one,
     * blocking session-fixation.
     */
    #[\Override]
    public function validateId(string $id): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM sessions WHERE id = :id AND expires > :now'
            );
            $stmt->execute([':id' => $this->key($id), ':now' => time()]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('DbSessionHandler::validateId failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Slide an unchanged session's expiry forward (lazy_write path) so an
     * actively-browsing user is never logged out mid-session.
     */
    #[\Override]
    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            $key    = $this->key($id);
            $newExp = time() + $this->lifetime();
            $userId = Session::userId();

            // Only slide the expiry when it has drifted by more than WRITE_THROTTLE
            // seconds. Reading the current expiry is a WAL-concurrent read; on most
            // requests the session still has almost its full lifetime left, so we
            // return here and skip the write entirely — no writer-lock contention.
            // Unless the row names the wrong user (one written before the column
            // existed, say): destroyForUser() must find it, so that's fixed now.
            $sel = $this->db->prepare('SELECT expires, user_id FROM sessions WHERE id = :id');
            $sel->execute([':id' => $key]);
            $current = $sel->fetch(PDO::FETCH_NUM);
            if (
                is_array($current)
                && ($newExp - (int) $current[0]) < self::WRITE_THROTTLE
                && ($current[1] === null ? null : (int) $current[1]) === $userId
            ) {
                return true;
            }

            $this->db->prepare('UPDATE sessions SET expires = :exp, user_id = :user WHERE id = :id')
                ->execute([':exp' => $newExp, ':user' => $userId, ':id' => $key]);
            return true;
        } catch (Throwable $e) {
            error_log('DbSessionHandler::updateTimestamp failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sliding TTL for the row. "Remember me" sessions get the long 30-day
     * window; everything else gets a generous idle window (the cookie itself is
     * a browser-session cookie, so this only bounds how long an abandoned tab
     * can be resumed). Active use refreshes it on every request.
     */
    private function lifetime(): int
    {
        return ($_SESSION['remember_me'] ?? false) === true
            ? Session::REMEMBER_ME_LIFETIME
            : Session::DEFAULT_LIFETIME;
    }

    /**
     * Never store the raw session id. A hash means a leaked database can't be
     * replayed as a live session cookie (you'd need the pre-image).
     */
    private function key(string $id): string
    {
        return hash('sha256', $id);
    }
}
