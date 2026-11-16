<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A user-created custom spread.')]
class UserSpread extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $user_spread_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $user_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $description = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $card_count = 1;

    /** @var list<array<string,mixed>> */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/SpreadPosition')))]
    public private(set) array $positions = [] {
        set(array|string $value) => self::jsonList($value);
    }

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $created_at = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $updated_at = '';
}
