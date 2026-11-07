<?php

namespace Tarot\Utility;

use Closure;
use PDO;
use Tarot\Config\Env;
use Tarot\Database\Connection;

/**
 * Centralised session management. Handles starting, persisting ("remember me"),
 * sliding cookie refresh, and cleanup — all in one place so controllers and
 * middleware don't duplicate session logic.
 *
 * Sessions are stored in the database (DbSessionHandler), not on the host's
 * filesystem, so an external GC cron / neighbouring vhost can't prune active
 * sessions after ~24 minutes. We control expiry and garbage collection here.
 */
final class Session
{
    /** 30-day lifetime for "remember me" sessions. */
    public const int REMEMBER_ME_LIFETIME = 60 * 60 * 24 * 30;

    /**
     * Sliding idle lifetime for ordinary (non-"remember me") sessions. The
     * browser cookie is a session cookie that dies on browser close; this only
     * bounds how long an abandoned-but-still-open tab can be resumed. Refreshed
     * on every request, so active users are never logged out mid-session.
     */
    public const int DEFAULT_LIFETIME = 60 * 60 * 24; // 24 hours

    /**
     * Where sessions are stored, resolved only when one starts. The app hands over
     * its container's PDO (api/app.php), so the HTTP tests, which build the app on
     * an in-memory database, keep their sessions there too.
     *
     * @var (Closure(): PDO)|null
     */
    private static ?Closure $database = null;

    /**
     * @param Closure(): PDO $database
     */
    public static function useDatabase(Closure $database): void
    {
        self::$database = $database;
    }

    /**
     * Start (or resume) the session, automatically honoring a previously-set
     * "remember me" flag with a sliding cookie refresh.
     */
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Re-enable PHP's probabilistic GC (disabled by default on Debian-based
        // hosts) so our DbSessionHandler::gc() sweeps expired rows on ~1% of
        // requests — no cron required. Strict mode makes PHP reject session ids
        // the store doesn't recognise (session-fixation defence).
        //
        // Note: session.gc_maxlifetime is intentionally NOT set. With a custom
        // save handler it is only passed as the (ignored) $max_lifetime arg to
        // gc(); the authoritative expiry lives in the sessions.expires column,
        // which read()/validateId()/gc() check directly.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.use_strict_mode', '1');

        // Persist sessions in the database rather than the host's shared
        // filesystem, which an external cron / other vhost can garbage-collect
        // out from under us. The handler owns expiry and GC.
        $db = self::$database !== null ? (self::$database)() : Connection::getInstance();
        session_set_save_handler(new DbSessionHandler($db), true);

        session_start();

        if (($_SESSION['remember_me'] ?? false) === true) {
            self::applySlidingExpiration();
        }
    }

    /**
     * Regenerate the session ID (prevents fixation attacks on privilege change)
     * while ensuring the cookie is sent with the correct persistent lifetime
     * when $persistent is true.
     *
     * session_regenerate_id() sends the new cookie with the params set at
     * bootstrap (api/index.php: a session cookie, HttpOnly, SameSite=Lax), and
     * replaces any session cookie already queued this request — e.g. start()'s
     * sliding refresh carrying the old ID. Those params can't be changed here:
     * session_set_cookie_params() is refused once the session is active.
     */
    public static function regenerate(bool $persistent = false): void
    {
        session_regenerate_id(true);

        // A remembered login needs the 30-day expiry, which setcookie() can
        // still add mid-session. Sent after PHP's header, so browsers keep it.
        if ($persistent) {
            self::sendCookie(self::REMEMBER_ME_LIFETIME);
        }
    }

    /**
     * Give the current session a new id without signing anyone out — after a
     * password change, so a copy of the old cookie stops working. A remembered
     * session stays remembered.
     */
    public static function rotate(): void
    {
        self::regenerate(persistent: ($_SESSION['remember_me'] ?? false) === true);
    }

    /**
     * Mark the current session for 30-day persistence ("remember me"). Call
     * this AFTER start() and regenerate().
     */
    public static function persist(): void
    {
        $_SESSION['remember_me'] = true;

        // The DB row's TTL is derived from $_SESSION['remember_me'] (see
        // DbSessionHandler::lifetime), so flagging it above is enough; here we
        // just extend the browser cookie to match the 30-day window.
        self::sendCookie(self::REMEMBER_ME_LIFETIME);
    }

    /** The current session's id, or null when no session has started. */
    public static function id(): ?string
    {
        $id = session_id();
        return $id === false || $id === '' ? null : $id;
    }

    /**
     * Get the currently authenticated user ID from the session, or null.
     */
    public static function userId(): ?int
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Destroy the user authentication state without ending the entire session
     * (preserves any other session data like admin flags if needed).
     */
    public static function clearUser(): void
    {
        unset($_SESSION['user_id'], $_SESSION['remember_me']);
    }

    /**
     * Apply sliding expiration: re-send the "remember me" cookie so the browser
     * keeps it while the user is actively using the site. (The DB row's expiry
     * slides independently on every request via the save handler.)
     */
    private static function applySlidingExpiration(): void
    {
        self::sendCookie(self::REMEMBER_ME_LIFETIME);
    }

    /**
     * (Re-)send the session cookie with the given lifetime.
     */
    private static function sendCookie(int $lifetime): void
    {
        setcookie(
            (string) session_name(),
            (string) session_id(),
            [
                'expires'  => time() + $lifetime,
                'path'     => '/',
                'httponly'  => true,
                'samesite' => 'Lax',
                'secure'   => Env::isProduction(),
            ]
        );
    }
}
