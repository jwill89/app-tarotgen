<?php

namespace Tarot\Utility;

/**
 * The one way PHP writes timestamps: UTC, in SQLite's own format.
 *
 * Every timestamp column must agree with SQLite's CURRENT_TIMESTAMP and
 * datetime('now') (always UTC), and the frontend parses them all as UTC
 * (frontend/src/utils/datetime.ts). PHP's date() uses the host's zone instead,
 * so it is right only on a host that happens to run UTC. Values compare as
 * strings in SQL, so expiries must use the same zone as now().
 */
final class Clock
{
    public const string FORMAT = 'Y-m-d H:i:s';

    /** The current UTC time, e.g. for created_at / last_used_at / :now. */
    public static function now(): string
    {
        return gmdate(self::FORMAT);
    }

    /** UTC time $seconds from now — token and code expiries. */
    public static function in(int $seconds): string
    {
        return gmdate(self::FORMAT, time() + $seconds);
    }

    /** Today's UTC date (Y-m-d). */
    public static function today(): string
    {
        return gmdate('Y-m-d');
    }
}
