<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * A card users have reported as having a problem with its image. One row per
 * (deck, card); {@see $report_count} tracks how many times it has been reported,
 * and {@see $submissions} holds the reason each reporter gave. {@see $deck_name}
 * is resolved from the decks table for the admin listing and is not a stored
 * column.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: 'A card users have reported as having a problem with its image.')]
class CardReport extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $report_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $deck_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Resolved deck name (from the decks table).'))]
    public private(set) string $deck_name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $card_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $card_name = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'How many times this card has been reported.'))]
    public private(set) int $report_count = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', nullable: true, description: 'When an admin marked it handled; null = open.', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) ?string $resolved_at = null;

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $first_reported_at = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $last_reported_at = '';

    /**
     * Starts at 1 and goes up each time the card is reported again after an admin
     * resolved it, so the reasons for the current problem can be told apart from
     * those that were already dealt with.
     */
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'Goes up each time the card is reported again after being resolved.'))]
    public private(set) int $round = 1;

    /**
     * Every reason given for this card, newest first. Reports made before reasons
     * were collected (or by an older plugin build) have no entry, so this can hold
     * fewer items than {@see $report_count}.
     *
     * @var list<CardReportSubmission>
     */
    #[OAS\Property(schema: new OAS\Schema(type: 'array', items: new OAS\Schema\Items(ref: CardReportSubmission::class), description: 'Each reason given, newest first. Older reports may have none.'))]
    public private(set) array $submissions = [];
}
