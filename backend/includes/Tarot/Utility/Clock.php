<?php

namespace Tarot\Utility;

/**
 * The one way PHP writes timestamps: RFC 3339 in UTC, to the second
 * (2026-11-07T09:18:09Z), the form every DATETIME column's CHECK accepts and
 * the API sends.
 *
 * Values compare as text in SQL, so a bound value and the column must share
 * this one form: SQLite's CURRENT_TIMESTAMP and datetime() write
 * '2026-11-07 09:18:09', and a space sorts before the T. In SQL the same form
 * is strftime('%Y-%m-%dT%H:%M:%SZ', 'now'). PHP's date() uses the host's zone
 * instead, so it is right only on a host that happens to run UTC.
 */
final class Clock
{
    public const string FORMAT = 'Y-m-d\TH:i:s\Z';

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

    /** Today's UTC date (Y-m-d), the form of a DATE column. */
    public static function today(): string
    {
        return gmdate('Y-m-d');
    }
}
