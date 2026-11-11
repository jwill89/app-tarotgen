<?php

namespace Tarot\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Tarot\Data\ReadingFilter;
use Tarot\Exception\ApiException;
use Tarot\Utility\Clock;
use Tarot\Utility\Input;

/**
 * What a reader may send about a reading, checked and normalised in one place:
 * the journal fields (question, querent, tags), who can open it, a guest's
 * expiry, and My Readings' filters. Drawing a reading (ReadingService) and
 * editing one (PATCH /account/readings/{id}) take the same rules from here.
 *
 * Stateless; a refusal is an ApiException with a message the reader can act on.
 */
final class ReadingInput
{
    public const int QUESTION_MAX = 500;
    public const int QUERENT_MAX = 100;
    public const int TAG_MAX = 30;
    public const int TAGS_MAX = 10;
    public const int PASSWORD_MIN = 4;

    /** The days a guest may keep a reading for, instead of the usual year. */
    public const array EXPIRY_DAYS = [1, 7, 30];

    public const array VISIBILITIES = ['link', 'password', 'private'];

    private const int SEARCH_MAX = 100;

    /**
     * The longest spread name the filter keeps: a personal spread's name may be
     * 200 characters (UserSpreadData), and a reading records it whole, so a
     * shorter cap would cut the name GET /account/reading-spreads offers and
     * find nothing.
     */
    private const int SPREAD_NAME_MAX = 200;

    public static function question(mixed $value): ?string
    {
        return Input::nullableString(is_scalar($value) ? $value : null, self::QUESTION_MAX);
    }

    public static function querent(mixed $value): ?string
    {
        return Input::nullableString(is_scalar($value) ? $value : null, self::QUERENT_MAX);
    }

    /**
     * A reading's tags from a list of strings or one comma-separated string
     * (what a form post sends). Commas separate tags inside a string too; each
     * is normalised (tag()), and blanks and repeats are dropped.
     *
     * @return list<string>
     * @throws ApiException Over TAGS_MAX tags, a tag over TAG_MAX characters, or a tag that isn't text.
     */
    public static function tags(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $parts = is_array($value) ? $value : [$value];
        $tags = [];
        foreach ($parts as $part) {
            if (!is_string($part)) {
                throw new ApiException('Tags must be text.', 400);
            }
            foreach (explode(',', $part) as $raw) {
                $tag = self::tag($raw);
                if ($tag === '') {
                    continue;
                }
                if (mb_strlen($tag) > self::TAG_MAX) {
                    throw new ApiException('A tag can be at most ' . self::TAG_MAX . " characters: \"{$tag}\".", 400);
                }
                $tags[$tag] = true;
            }
        }

        if (count($tags) > self::TAGS_MAX) {
            throw new ApiException('A reading can have at most ' . self::TAGS_MAX . ' tags.', 400);
        }

        return array_map(strval(...), array_keys($tags));
    }

    /**
     * One tag as it's stored and matched: lower-case (so "Career" and "career"
     * are one tag), without a leading #, runs of whitespace or control
     * characters as one space, trimmed. Lower-cased rather than case-folded, so
     * a tag still reads as typed ("straße" stays "straße").
     */
    public static function tag(string $raw): string
    {
        // Control characters only (\p{Cc}): a zero-width joiner holds an emoji together.
        $tag = preg_replace('/[\p{Cc}\s]+/u', ' ', $raw) ?? '';
        $tag = trim(ltrim(trim($tag), '#'));

        return mb_strtolower($tag, 'UTF-8');
    }

    /**
     * When a guest's reading is to be deleted: $days from now, if a guest chose
     * one of EXPIRY_DAYS; null (the usual retention) when they chose nothing.
     *
     * @throws ApiException For any other value.
     */
    public static function expiresAt(mixed $days): ?string
    {
        if ($days === null || $days === '') {
            return null;
        }
        if (is_string($days) && ctype_digit($days)) {
            $days = (int) $days;
        }
        if (!in_array($days, self::EXPIRY_DAYS, true)) {
            throw new ApiException('A reading made without an account can be kept for 1, 7 or 30 days, or the usual year.', 400);
        }

        return Clock::in($days * 86400);
    }

    /**
     * The visibility asked for, or null when none was.
     *
     * @throws ApiException For a value that isn't one.
     */
    public static function visibility(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!in_array($value, self::VISIBILITIES, true)) {
            throw new ApiException('Choose who can open the reading: link, password or private.', 400);
        }

        return $value;
    }

    /**
     * The access columns to write for a request: `is_private` and the password
     * hash. Private and Anyone-with-the-link both clear a password, so a reading
     * never holds one it doesn't use. Password keeps the reading's password when
     * no new one is sent ($hasPassword). Without `visibility`, a `password`
     * means Password and `remove_password` drops one.
     *
     * @param array<string,mixed> $params
     * @param int $status The status a password problem is refused with (the edit endpoint answers 422).
     * @return array{is_private?: bool, password_hash?: string|null}
     * @throws ApiException
     */
    public static function access(array $params, bool $hasPassword = false, int $status = 400): array
    {
        $visibility = self::visibility($params['visibility'] ?? null);
        $password = is_scalar($params['password'] ?? null) ? (string) $params['password'] : '';

        if ($visibility === 'private' || $visibility === 'link') {
            return ['is_private' => $visibility === 'private', 'password_hash' => null];
        }

        if ($visibility === null) {
            // No visibility sent, so the password fields decide: removing wins over a password sent alongside.
            if (Input::bool($params['remove_password'] ?? null)) {
                return ['password_hash' => null];
            }
            if ($password === '') {
                return [];
            }
        }

        // Password: chosen, or implied by a new password.
        if ($password === '') {
            if ($hasPassword) {
                return ['is_private' => false];
            }
            throw new ApiException('Choose a password of at least ' . self::PASSWORD_MIN . ' characters for this reading.', $status);
        }
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            throw new ApiException('Reading password must be at least ' . self::PASSWORD_MIN . ' characters.', $status);
        }

        return ['is_private' => false, 'password_hash' => password_hash($password, AuthService::passwordAlgo())];
    }

    /**
     * My Readings' filters from the query string: `q` (text), `deck_id`,
     * `spread`, `tag`, `from` and `to` (the reader's dates, both included, in
     * `tz`, an IANA time zone, UTC when absent) and `daily` (only / exclude). A
     * blank value is no filter.
     *
     * @param array<array-key,mixed> $query
     * @throws ApiException For a value that can't be a filter.
     */
    public static function filter(array $query): ReadingFilter
    {
        $text = self::queryString($query, 'q');
        $deckId = self::queryString($query, 'deck_id');
        if ($deckId !== '' && (!ctype_digit($deckId) || (int) $deckId < 1)) {
            throw new ApiException("deck_id must be a deck's id.", 400);
        }

        $daily = match (self::queryString($query, 'daily')) {
            ''        => null,
            'only'    => true,
            'exclude' => false,
            default   => throw new ApiException('daily must be only or exclude.', 400),
        };

        $tzName = self::queryString($query, 'tz');
        try {
            $zone = new DateTimeZone($tzName === '' ? 'UTC' : $tzName);
        } catch (Exception) {
            throw new ApiException('tz must be a time zone, such as Europe/London.', 400);
        }
        $from = self::localDay($query, 'from', $zone);
        $to = self::localDay($query, 'to', $zone);
        if ($from !== null && $to !== null && $from > $to) {
            throw new ApiException('from must be on or before to.', 400);
        }

        $utc = new DateTimeZone('UTC');
        $tag = self::tag(self::queryString($query, 'tag'));
        $spread = Input::string(self::queryString($query, 'spread'), self::SPREAD_NAME_MAX);

        return new ReadingFilter(
            text: $text === '' ? null : mb_substr($text, 0, self::SEARCH_MAX),
            deckId: $deckId === '' ? null : (int) $deckId,
            spread: $spread === '' ? null : $spread,
            tag: $tag === '' ? null : $tag,
            from: $from?->setTimezone($utc)->format(Clock::FORMAT),
            until: $to?->modify('+1 day')->setTimezone($utc)->format(Clock::FORMAT),
            daily: $daily,
        );
    }

    /**
     * A query parameter as trimmed text ('' when absent).
     *
     * @param array<array-key,mixed> $query
     * @throws ApiException When it was sent as a list.
     */
    private static function queryString(array $query, string $name): string
    {
        $value = $query[$name] ?? '';
        if (!is_string($value)) {
            throw new ApiException("{$name} must be sent once, as text.", 400);
        }

        return trim($value);
    }

    /**
     * The start of the day `$name` names ("Y-m-d") in $zone, or null when absent.
     *
     * @param array<array-key,mixed> $query
     * @throws ApiException When it isn't a date.
     */
    private static function localDay(array $query, string $name, DateTimeZone $zone): ?DateTimeImmutable
    {
        $day = self::queryString($query, $name);
        if ($day === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new ApiException("{$name} must be a date, as YYYY-MM-DD.", 400);
        }

        return $date;
    }
}
