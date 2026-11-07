<?php

namespace Tarot\Repository;

use Tarot\Data\CardReportData;
use Tarot\Structure\CardReport;
use Tarot\Structure\CardReportReason;

readonly class CardReportRepository
{
    public function __construct(private CardReportData $data) {}

    /** A null $reason records a report from a client that predates reasons. */
    public function report(
        int $deckId,
        int $cardId,
        string $cardName,
        ?CardReportReason $reason,
        string $details,
    ): void {
        $this->data->report($deckId, $cardId, $cardName, $reason?->value, $details);
    }

    /** @return list<CardReport> */
    public function get(bool $includeResolved): array
    {
        return $this->data->retrieve($includeResolved);
    }

    public function find(int $reportId): ?CardReport
    {
        return $this->data->retrieveOne($reportId);
    }

    public function exists(int $reportId): bool
    {
        return $this->data->exists($reportId);
    }

    public function setResolved(int $reportId, bool $resolved): bool
    {
        return $this->data->setResolved($reportId, $resolved);
    }
}
