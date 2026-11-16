<?php

namespace Tarot\Utility;

use Tarot\Exception\ApiException;

/**
 * Small, stateless helpers for coercing and bounding untrusted request input.
 *
 * Centralises the two idioms that recur across services and controllers — a
 * boolean from a possibly-stringy flag, and a trimmed text field with a length
 * limit — so they behave identically everywhere and the intent reads at a glance.
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
     * A text field as a string: trimmed unless $trim is false, and refused when
     * longer than $maxLen characters (multibyte-safe), never cut short. Anything
     * but a string or a number counts as empty.
     *
     * @param string $location Where the field was sent (`body.reading_name`), named in the refusal.
     * @throws ApiException 422, when it is too long.
     */
    public static function text(mixed $value, int $maxLen, string $location, bool $trim = true): string
    {
        $string = is_scalar($value) ? (string) $value : '';

        if ($trim) {
            $string = trim($string);
        }

        if (mb_strlen($string) > $maxLen) {
            $field = substr($location, (int) strrpos($location, '.') + 1);
            throw new ApiException("{$field} can be at most {$maxLen} characters.", 422, location: $location);
        }

        return $string;
    }

    /**
     * Like {@see text()}, but a value that is empty after trimming becomes
     * null — for optional columns that should store NULL rather than an empty
     * string. The stored value itself is only trimmed when $trim is true.
     *
     * @throws ApiException 422, when it is too long.
     */
    public static function nullableText(mixed $value, int $maxLen, string $location, bool $trim = true): ?string
    {
        $string = self::text($value, $maxLen, $location, $trim);

        return trim($string) === '' ? null : $string;
    }
}
