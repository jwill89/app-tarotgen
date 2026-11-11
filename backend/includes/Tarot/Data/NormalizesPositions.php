<?php

namespace Tarot\Data;

/**
 * Shared sanitizer for spread position payloads, used by SpreadData,
 * PendingSpreadData and UserSpreadData so the official, submitted and personal
 * tables enforce the same shape: clamped coordinates, normalized rotation,
 * length-capped titles and descriptions. (Reading it back is the Structures'
 * positions hook.)
 */
trait NormalizesPositions
{
    /** A title names the position ("Past"). */
    private const int TITLE_MAX_LENGTH = 100;

    /** A description says what the position asks about, in a sentence or two. */
    private const int DESCRIPTION_MAX_LENGTH = 280;

    /**
     * A position's description is optional and only stored when it has one, so
     * a position without one keeps the plain shape.
     *
     * @return list<array{order:int, title:string, x:float, y:float, rotation:int, description?:string}>
     */
    private function normalizePositions(mixed $positions): array
    {
        if (!is_array($positions)) {
            return [];
        }

        $out = [];
        foreach ($positions as $p) {
            if (!is_array($p)) {
                continue;
            }

            $rotation = (int) ($p['rotation'] ?? 0) % 360;
            if ($rotation < 0) {
                $rotation += 360;
            }

            $position = [
                'order'    => (int) ($p['order'] ?? 0),
                'title'    => mb_substr(trim((string) ($p['title'] ?? '')), 0, self::TITLE_MAX_LENGTH),
                'x'        => max(0.0, min(100.0, round((float) ($p['x'] ?? 50), 2))),
                'y'        => max(0.0, min(100.0, round((float) ($p['y'] ?? 50), 2))),
                'rotation' => $rotation,
            ];

            $description = $p['description'] ?? null;
            $description = is_scalar($description) ? mb_substr(trim((string) $description), 0, self::DESCRIPTION_MAX_LENGTH) : '';
            if ($description !== '') {
                $position['description'] = $description;
            }

            $out[] = $position;
        }

        return $out;
    }
}
