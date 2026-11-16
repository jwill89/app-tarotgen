<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * One row of the admin readings list: who drew it, when, and with what, worked
 * out in SQL (ReadingData::listAll) rather than by decoding every reading here.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A reading as the admin readings list shows it.')]
class ReadingSummary extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $reading_id = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $reading_time = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer', nullable: true, description: 'The owner, or null for a guest reading.'))]
    public private(set) ?int $user_id = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: "The owner's display name, or `Guest`."))]
    public private(set) string $display_name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $deck_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'The deck\'s name, or `Deck #<id>` once the deck is gone.'))]
    public private(set) string $deck_name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'The spread name, or empty for a free draw.'))]
    public private(set) string $spread_name = '';
}
