<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Shared\DomainException;

/**
 * Central period rule for every posting path. Call it inside the posting transaction: the period row is read with
 * FOR SHARE, so a concurrent close (FOR UPDATE) waits for the posting to commit, and a posting that starts after the
 * close commits sees CLOSED. The posting_date alone resolves the period (never created_at).
 */
class PeriodGuard
{
    public function resolveForPosting(string $postingDate, bool $allowSoftClosed = false): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->whereDate('start_date', '<=', $postingDate)->whereDate('end_date', '>=', $postingDate)
            ->sharedLock()->first();

        if (! $period) {
            throw new DomainException("No accounting period covers {$postingDate}.", 'PERIOD_NOT_FOUND', 422, ['posting_date' => $postingDate]);
        }

        $year = FiscalYear::query()->find($period->fiscal_year_id);
        if (! $year || $year->status !== FiscalYear::OPEN) {
            throw new DomainException('The fiscal year of this posting date is not open.', 'FISCAL_YEAR_NOT_OPEN', 422, ['posting_date' => $postingDate]);
        }

        match ($period->status) {
            AccountingPeriod::OPEN => null,
            AccountingPeriod::SOFT_CLOSED => $allowSoftClosed ? null : throw new DomainException(
                "Period {$period->code} is soft-closed; only a user allowed to post into soft-closed periods can post here.", 'PERIOD_SOFT_CLOSED', 422, ['period' => $period->code]),
            AccountingPeriod::CLOSED => throw new DomainException("Period {$period->code} is closed.", 'PERIOD_CLOSED', 422, ['period' => $period->code]),
            default => throw new DomainException("Period {$period->code} is not open yet.", 'PERIOD_NOT_OPEN', 422, ['period' => $period->code]),
        };

        return $period;
    }
}
