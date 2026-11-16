<?php

namespace Tarot\Structure;

use OpenApi\Spec as OAS;

/**
 * One person's report of a card: the reason they picked and anything they wrote
 * about it. Belongs to the card's {@see CardReport}.
 *
 * Properties use asymmetric visibility (PHP 8.4): public reads, private writes.
 */
#[OAS\Schema(description: "One user's report of a card, with their reason.")]
class CardReportSubmission extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $submission_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $report_id = 0;

    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: "The card's round when this was submitted (see CardReport.round)."))]
    public private(set) int $round = 1;

    // The raw CardReportReason value: PDO hydrates rows as strings, not enums.
    #[OAS\Property(schema: new OAS\Schema(ref: CardReportReason::class))]
    public private(set) string $reason = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'What the reporter wrote; may be empty.'))]
    public private(set) string $details = '';

    #[OAS\Property(schema: new OAS\Schema(type: 'string', format: 'date-time', examples: ['2026-11-07T09:18:09Z']))]
    public private(set) string $submitted_at = '';
}
