<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A user-submitted spread awaiting moderation.')]
class PendingSpread extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $pending_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $description = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $card_count = 1;

    /**
     * Array of position objects, each shaped:
     * ['order' => int, 'title' => string, 'x' => float, 'y' => float, 'rotation' => int,
     *  'description' => string (only when it has one)]
     *
     * @var list<array<string,mixed>>
     */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition')))]
    public private(set) array $positions = [] {
        set(array|string $value) => self::jsonList($value);
    }

    /** Resolved submitter label: the linked account's display name, or "Guest". */
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $submitter = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer', nullable: true))]
    public private(set) ?int $user_id = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $submitted_at = '';
}
