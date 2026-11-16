<?php

namespace Tarot\Service;

use Tarot\Exception\ApiException;
use Tarot\Utility\Input;

/**
 * Card positions as a client sends them (a spread's layout, a placed reading, a
 * custom reading's cards), and a spread's name, checked against the shape they
 * are stored in: x and y are percentages of the canvas (0–100), a title is at
 * most TITLE_MAX characters, a description at most DESCRIPTION_MAX and a name
 * at most NAME_MAX. A value past a limit is refused, never cut short or moved
 * onto the canvas.
 */
final class SpreadInput
{
    public const int NAME_MAX = 200;
    public const int TITLE_MAX = 100;
    public const int DESCRIPTION_MAX = 280;

    /**
     * Every position of a layout, each an object within the limits.
     *
     * @param array<mixed> $positions
     * @param string $location Where the list was sent, for the refusal.
     * @throws ApiException 422 at the position, or the field of it, at fault.
     */
    public static function positions(array $positions, string $location = 'body.positions'): void
    {
        foreach (array_values($positions) as $i => $position) {
            $at = "{$location}[{$i}]";
            if (!is_array($position)) {
                throw new ApiException('Invalid position data.', 422, location: $at);
            }

            self::title($position['title'] ?? null, "{$at}.title");
            Input::text($position['description'] ?? null, self::DESCRIPTION_MAX, "{$at}.description");
            self::coordinate($position['x'] ?? null, "{$at}.x");
            self::coordinate($position['y'] ?? null, "{$at}.y");
        }
    }

    /**
     * A spread's name, trimmed: '' when there is none.
     *
     * @throws ApiException 422 over NAME_MAX characters.
     */
    public static function name(mixed $value, string $location = 'body.name'): string
    {
        return Input::text($value, self::NAME_MAX, $location);
    }

    /**
     * A position's title, trimmed.
     *
     * @throws ApiException 422 over TITLE_MAX characters.
     */
    public static function title(mixed $value, string $location): string
    {
        return Input::text($value, self::TITLE_MAX, $location);
    }

    /**
     * A card's centre on one axis, a percentage of the canvas rounded to two
     * places; 50, the middle, when it is absent.
     *
     * @throws ApiException 422 for anything but a number from 0 to 100.
     */
    public static function coordinate(mixed $value, string $location): float
    {
        if ($value === null || $value === '') {
            return 50.0;
        }

        $rounded = is_numeric($value) ? round((float) $value, 2) : null;
        if ($rounded === null || $rounded < 0.0 || $rounded > 100.0) {
            $field = substr($location, (int) strrpos($location, '.') + 1);
            throw new ApiException("{$field} must be a number from 0 to 100.", 422, location: $location);
        }

        return $rounded;
    }
}
