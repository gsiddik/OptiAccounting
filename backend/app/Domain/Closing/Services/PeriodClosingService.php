<?php

namespace App\Domain\Closing\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Closing\Models\ClosingEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The period closing workflow of OA5, the only code that moves a period into or out of SOFT_CLOSED / CLOSED once ACCOUNTING_REPORTING is
 * entitled (the legacy period routes delegate here):
 *
 *   pre-close   evaluates the HARD readiness and keeps the evidence; locks nothing, changes nothing, repeatable
 *   soft-close  OPEN -> SOFT_CLOSED under the period row lock; no readiness gate, the evidence is recorded
 *   hard-close  OPEN | SOFT_CLOSED -> CLOSED: ledger gate, period lock, readiness evaluated INSIDE the locks, then the transition
 *   reopen      CLOSED -> SOFT_CLOSED | OPEN (or SOFT_CLOSED -> OPEN), with a reason, under the reopen policy
 *
 * Every action is authorized here as well as at the route (the permission through the central resolver, plus a writable
 * ACCOUNTING_REPORTING module), needs a tenant-wide data scope, appends to the closing history and is audited. Nothing is deleted or
 * rewritten: history rows are append-only, and a reopen writes its history row BEFORE the status change because the database lets a period
 * leave CLOSED only in a transaction that also wrote the matching REOPEN row.
 */
class PeriodClosingService
{
    public function __construct(
        private readonly CloseReadinessService $readiness,
        private readonly ClosingPolicyService $policy,
        private readonly ClosingHistory $history,
        private readonly ClosingLocks $locks,
        private readonly AuditService $audit,
        private readonly ClosingAccess $access,
    ) {}

    /** @return array{readiness:ReadinessResult,event_id:string} */
    public function preClose(AccountingPeriod $period): array
    {
        $this->access->authorize('accounting.period.preclose');
        $this->readiness->assertFullScope();
        $period = AccountingPeriod::query()->findOrFail($period->id);
        if (! in_array($period->status, [AccountingPeriod::OPEN, AccountingPeriod::SOFT_CLOSED], true)) {
            throw new DomainException("A {$period->status} period cannot be pre-closed.", 'PERIOD_NOT_CLOSABLE', 409, ['status' => $period->status]);
        }

        $result = $this->readiness->evaluate($period, ReadinessResult::HARD);

        return DB::transaction(function () use ($period, $result) {
            $eventId = $this->history->record(ClosingEvent::PRE_CLOSE, [
                'accounting_period_id' => $period->id, 'fiscal_year_id' => $period->fiscal_year_id, 'from_status' => $period->status, 'to_status' => $period->status, 'readiness' => $result,
            ]);
            $this->audit->record('accounting.period.pre_closed', 'accounting_period', $period->id, null, [
                'code' => $period->code, 'status' => $period->status, 'readiness' => $result->result(), 'blocking' => array_column($result->blocking(), 'code'), 'warnings' => array_column($result->warnings(), 'code'),
            ]);

            return ['readiness' => $result, 'event_id' => $eventId];
        });
    }

    public function softClose(AccountingPeriod $period, ?string $reason = null): AccountingPeriod
    {
        $this->access->authorize('accounting.period.soft_close');
        $this->readiness->assertFullScope();
        $reason = $this->reason($reason, false);

        return DB::transaction(function () use ($period, $reason) {
            // FOR UPDATE: waits for postings that hold the period FOR SHARE and blocks new ones until commit.
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $this->assertTransition($period, AccountingPeriod::SOFT_CLOSED, [AccountingPeriod::OPEN]);

            $result = $this->readiness->evaluate($period, ReadinessResult::SOFT);
            $from = $period->status;
            $period->status = AccountingPeriod::SOFT_CLOSED;
            $period->save();

            $this->history->record(ClosingEvent::SOFT_CLOSE, [
                'accounting_period_id' => $period->id, 'fiscal_year_id' => $period->fiscal_year_id, 'from_status' => $from, 'to_status' => $period->status, 'readiness' => $result, 'reason' => $reason,
            ]);
            $this->auditTransition('accounting.period.soft_closed', $period, $from, $reason, $result);

            return $period;
        });
    }

    public function hardClose(AccountingPeriod $period, ?string $reason = null): AccountingPeriod
    {
        $user = $this->access->authorize('accounting.period.hard_close');
        $this->readiness->assertFullScope();
        $reason = $this->reason($reason, false);

        return DB::transaction(function () use ($period, $reason, $user) {
            // Ledger gate -> period row, the Posting Engine's order: a posting either committed before this point or finds CLOSED afterwards.
            $period = $this->locks->ledgerAndPeriod($period->id);
            $this->assertTransition($period, AccountingPeriod::CLOSED, [AccountingPeriod::OPEN, AccountingPeriod::SOFT_CLOSED]);
            if ($period->status === AccountingPeriod::OPEN && $this->policy->settings()->require_soft_close_first) {
                throw new DomainException('The closing policy requires the period to be soft-closed before it is closed.', 'SOFT_CLOSE_REQUIRED', 409, ['period' => $period->code]);
            }

            $result = $this->readiness->evaluate($period, ReadinessResult::HARD);
            if (! $result->ready()) {
                throw new DomainException("Period {$period->code} is not ready to close.", 'PERIOD_NOT_READY', 409, [
                    'period' => $period->code, 'blocking' => $result->blocking(), 'warning_count' => count($result->warnings()),
                ]);
            }

            $from = $period->status;
            $period->status = AccountingPeriod::CLOSED;
            $period->closed_at = now();
            $period->closed_by = $user->id;
            $period->save();

            $this->history->record(ClosingEvent::HARD_CLOSE, [
                'accounting_period_id' => $period->id, 'fiscal_year_id' => $period->fiscal_year_id, 'from_status' => $from, 'to_status' => $period->status, 'readiness' => $result, 'reason' => $reason,
            ]);
            $this->auditTransition('accounting.period.hard_closed', $period, $from, $reason, $result);

            return $period;
        });
    }

    /**
     * CLOSED -> SOFT_CLOSED | OPEN, or SOFT_CLOSED -> OPEN. The reason is mandatory (at least 10 characters) and recorded in the history and the audit.
     * Reopening a closed period is refused while its fiscal year has a POSTED year-end closing (reverse the year end first), and obeys the policy:
     * reopen_requires_later_open, reopen_max_periods_back, separate_closer_and_reopener. A closed fiscal year goes back to OPEN in the same transaction.
     */
    public function reopen(AccountingPeriod $period, string $target, string $reason): AccountingPeriod
    {
        $user = $this->access->authorize('accounting.period.reopen');
        $this->readiness->assertFullScope();
        $reason = $this->reason($reason, true);
        if (! in_array($target, [AccountingPeriod::SOFT_CLOSED, AccountingPeriod::OPEN], true)) {
            throw new DomainException('A period is reopened to SOFT_CLOSED or OPEN.', 'REOPEN_TARGET_INVALID', 422, ['target' => $target]);
        }

        return DB::transaction(fn () => $this->reopenLocked($period->id, $target, $reason, $user, yearEndReversal: false));
    }

    /**
     * The reopen the year-end reversal performs for the last period of the year (CLOSED -> $target, or SOFT_CLOSED -> OPEN): the same locks, policy,
     * history and audit as a reopen, but the POSTED year-end closing it is about to reverse is no obstacle. The caller checked the reopen permission.
     */
    public function reopenForYearEndReversal(AccountingPeriod $period, string $target, string $reason): AccountingPeriod
    {
        return $this->reopenLocked($period->id, $target, $this->reason($reason, true), $this->access->user(), yearEndReversal: true);
    }

    // ------------------------------------------------------------------------------------------ internals

    private function reopenLocked(string $periodId, string $target, string $reason, User $user, bool $yearEndReversal): AccountingPeriod
    {
        $period = $this->locks->ledgerAndPeriod($periodId);
        $from = $period->status;
        $this->assertTransition($period, $target, $from === AccountingPeriod::CLOSED ? [AccountingPeriod::CLOSED] : [AccountingPeriod::SOFT_CLOSED]);
        if ($from === AccountingPeriod::SOFT_CLOSED && $target !== AccountingPeriod::OPEN) {
            throw new DomainException("A SOFT_CLOSED period cannot become {$target}.", 'PERIOD_INVALID_TRANSITION', 409, ['from' => $from, 'to' => $target]);
        }

        $year = FiscalYear::query()->lockForUpdate()->findOrFail($period->fiscal_year_id);
        if (! $yearEndReversal && DB::table('year_end_closings')->where('tenant_id', $period->tenant_id)->where('fiscal_year_id', $year->id)->where('status', 'POSTED')->exists()) {
            throw new DomainException("Fiscal year {$year->code} has a posted year-end closing; reverse it before reopening a period of that year.", 'YEAR_END_POSTED', 409, ['fiscal_year' => $year->code]);
        }
        if ($from === AccountingPeriod::CLOSED) {
            $this->assertReopenPolicy($period, $user);
        }

        if ($year->status === FiscalYear::CLOSED) {
            $this->history->record(ClosingEvent::REOPEN, ['fiscal_year_id' => $year->id, 'from_status' => FiscalYear::CLOSED, 'to_status' => FiscalYear::OPEN, 'reason' => $reason]);
            $year->status = FiscalYear::OPEN;
            $year->save();
            $this->audit->record('accounting.fiscal_year.reopened', 'fiscal_year', $year->id, ['status' => FiscalYear::CLOSED], ['status' => FiscalYear::OPEN, 'reason' => $reason, 'period' => $period->code]);
        }

        // The history row first: the database accepts the status change below only because this row exists in the same transaction.
        $this->history->record(ClosingEvent::REOPEN, [
            'accounting_period_id' => $period->id, 'fiscal_year_id' => $period->fiscal_year_id, 'from_status' => $from, 'to_status' => $target, 'reason' => $reason,
        ]);
        $before = ['status' => $from, 'closed_at' => $period->closed_at?->toIso8601String(), 'closed_by' => $period->closed_by];
        $period->status = $target;
        $period->closed_at = null;
        $period->closed_by = null;
        $period->save();

        $this->audit->record('accounting.period.reopened', 'accounting_period', $period->id, $before, ['code' => $period->code, 'status' => $target, 'reason' => $reason]);
        $this->audit->record('accounting.period.status_changed', 'accounting_period', $period->id, ['status' => $from, 'code' => $period->code], ['status' => $target, 'code' => $period->code]);

        return $period;
    }

    private function assertReopenPolicy(AccountingPeriod $period, User $user): void
    {
        $settings = $this->policy->settings();
        $later = AccountingPeriod::query()->where('start_date', '>', $period->end_date->toDateString())->where('status', AccountingPeriod::CLOSED)->orderBy('start_date');

        if ($settings->reopen_requires_later_open && (clone $later)->exists()) {
            throw new DomainException('Later periods are closed; reopen from the latest closed period backwards.', 'REOPEN_LATER_PERIOD_CLOSED', 409, [
                'period' => $period->code, 'later_closed' => (clone $later)->limit(5)->pluck('code')->all(),
            ]);
        }

        if ($settings->reopen_max_periods_back !== null) {
            $latestClosed = AccountingPeriod::query()->where('status', AccountingPeriod::CLOSED)->max('end_date');
            $back = AccountingPeriod::query()->where('start_date', '>', $period->end_date->toDateString())->whereDate('end_date', '<=', $latestClosed)->count();
            if ($back > $settings->reopen_max_periods_back) {
                throw new DomainException("The policy allows reopening at most {$settings->reopen_max_periods_back} periods back from the latest closed period.", 'REOPEN_TOO_FAR_BACK', 409, [
                    'period' => $period->code, 'periods_back' => $back, 'max_periods_back' => $settings->reopen_max_periods_back,
                ]);
            }
        }

        if ($settings->separate_closer_and_reopener) {
            $closer = $this->history->latest($period->id, ClosingEvent::HARD_CLOSE)?->actor_id ?? $period->closed_by;
            if ($closer !== null && $closer === $user->id) {
                throw new DomainException('Segregation of duties: the person who closed a period cannot reopen it.', 'SOD_VIOLATION', 403, ['rule' => 'closer_not_reopener']);
            }
        }
    }

    /** @param list<string> $allowedFrom */
    private function assertTransition(AccountingPeriod $period, string $to, array $allowedFrom): void
    {
        if (! in_array($period->status, $allowedFrom, true)) {
            throw new DomainException("A {$period->status} period cannot become {$to}.", 'PERIOD_INVALID_TRANSITION', 409, ['from' => $period->status, 'to' => $to]);
        }
    }

    private function auditTransition(string $action, AccountingPeriod $period, string $from, ?string $reason, ReadinessResult $result): void
    {
        $this->audit->record($action, 'accounting_period', $period->id, ['status' => $from, 'code' => $period->code], [
            'status' => $period->status, 'code' => $period->code, 'reason' => $reason, 'readiness' => $result->result(), 'blocking' => array_column($result->blocking(), 'code'), 'warnings' => array_column($result->warnings(), 'code'),
        ]);
        // The OA1 audit action stays, so existing consumers of the period trail keep working.
        $this->audit->record('accounting.period.status_changed', 'accounting_period', $period->id, ['status' => $from, 'code' => $period->code], ['status' => $period->status, 'code' => $period->code]);
    }

    private function reason(?string $reason, bool $required): ?string
    {
        $reason = trim((string) $reason);
        if ($required && mb_strlen($reason) < 10) {
            throw new DomainException('A reopen needs a reason of at least 10 characters.', 'REOPEN_REASON_REQUIRED', 422, ['min_length' => 10]);
        }

        return $reason === '' ? null : mb_substr($reason, 0, 500);
    }
}
