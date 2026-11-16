<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * One day's daily card as the calendar shows it: the day, the reading it opens,
 * and its one card, worked out in SQL from the reading (DailyCardData). Extra
 * cards the owner drew into the reading later don't change it: it is the first.
 *
 * A day whose reading was deleted is still drawn (no second card that day), but
 * with the reading gone it has no reading or card: those properties are null.
 * Either all of them are set or none is.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: "A day's daily card: the reading drawn for that day and the card it holds. A day whose reading was deleted stays drawn, with reading_id and every card field null.")]
class DailyCard extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(description: 'The day it was drawn for: the owner\'s local date, "Y-m-d".', type: 'string', format: 'date', examples: ['2026-11-07']))]
    public private(set) string $day = '';

    #[OAS\Property(schema: new OAS\Schema(description: 'The reading drawn for the day; null once that reading was deleted.', type: 'string', nullable: true))]
    public private(set) ?string $reading_id = null;

    #[OAS\Property(schema: new OAS\Schema(description: 'Null once the reading was deleted.', type: 'integer', nullable: true))]
    public private(set) ?int $deck_id = null;

    #[OAS\Property(schema: new OAS\Schema(description: 'The deck\'s name, or `Deck #<id>` once the deck is gone; null once the reading was deleted.', type: 'string', nullable: true))]
    public private(set) ?string $deck_name = null;

    #[OAS\Property(schema: new OAS\Schema(description: "The card's number in its deck; null once the reading was deleted.", type: 'integer', nullable: true))]
    public private(set) ?int $card_id = null;

    #[OAS\Property(schema: new OAS\Schema(description: 'The card\'s name as the reading recorded it, or `Card #<id>` when the deck had none; null once the reading was deleted.', type: 'string', nullable: true))]
    public private(set) ?string $card_name = null;

    #[OAS\Property(schema: new OAS\Schema(description: 'Drawn upside down; null once the reading was deleted.', type: 'boolean', nullable: true))]
    public private(set) ?bool $reversed = null;
}
