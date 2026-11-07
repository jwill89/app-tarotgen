<?php

namespace Tarot\Utility;

/**
 * Small, stateless helpers for coercing and bounding untrusted request input.
 *
 * Centralises the two idioms that recur across services and controllers — a
 * boolean from a possibly-stringy flag, and a trimmed, length-capped string —
 * so they behave identically everywhere and the intent reads at a glance.
 */
final class Input
{
    /**
     * Interpret a request value as a boolean. Unlike a plain `(bool)` cast, the
     * strings "false"/"0"/"off"/"" are read as false (via FILTER_VALIDATE_BOOLEAN),
     * which matches how flags arrive from JSON/urlencoded bodies. A missing
     * (null) value yields $default.
     */
    public static function bool(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Cast to string and cap to $maxLen characters (multibyte-safe). Leading and
     * trailing whitespace is trimmed unless $trim is false.
     */
    public static function string(mixed $value, int $maxLen, bool $trim = true): string
    {
        $string = (string) ($value ?? '');

        if ($trim) {
            $string = trim($string);
        }

        return mb_substr($string, 0, $maxLen);
    }

    /**
     * Like {@see string()}, but a value that is empty after trimming becomes
     * null — for optional columns that should store NULL rather than an empty
     * string. The stored value itself is only trimmed when $trim is true.
     */
    public static function nullableString(mixed $value, int $maxLen, bool $trim = true): ?string
    {
        $string = self::string($value, $maxLen, $trim);

        return trim($string) === '' ? null : $string;
    }
}
