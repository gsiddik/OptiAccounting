<?php

namespace App\Domain\Closing\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Identity\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The closing dashboard: every period with its status, its last closing event and, for an OPEN or SOFT_CLOSED period that has ended, a quick
 * readiness flag (the same CloseReadinessService answer, so the flag can never disagree with the close), and the year-end state of every
 * fiscal year. A period that has not ended yet has no readiness (nothing to close yet); a closed one needs none.
 */
class ClosingOverviewService
{
    public function __construct(private readonly CloseReadinessService $readiness, private readonly TenantContext $context) {}

    /** @return array{business_date:string,periods:list<array<string,mixed>>,fiscal_years:list<array<string,mixed>>} */
    public function overview(): array
    {
        $this->readiness->assertFullScope();
        $tenant = $this->context->tenantId();
        $today = Tenant::query()->findOrFail($tenant)->businessDate();

        $last = DB::table('closing_events as e')->where('e.tenant_id', $tenant)->whereNotNull('e.accounting_period_id')->where('e.event_type', '<>', 'PRE_CLOSE')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
            ->orderByDesc('e.created_at')->orderByDesc('e.id')
            ->get(['e.accounting_period_id', 'e.event_type', 'e.from_status', 'e.to_status', 'e.reason', 'e.created_at', 'e.actor_id', 'u.name as actor_name'])
            ->unique('accounting_period_id')->keyBy('accounting_period_id');
        $lastPreClose = DB::table('closing_events')->where('tenant_id', $tenant)->where('event_type', 'PRE_CLOSE')->groupBy('accounting_period_id')
            ->selectRaw('accounting_period_id, max(created_at) as at')->pluck('at', 'accounting_period_id');

        $periods = [];
        foreach (AccountingPeriod::query()->with('fiscalYear:id,code')->orderBy('start_date')->get() as $period) {
            $candidate = in_array($period->status, [AccountingPeriod::OPEN, AccountingPeriod::SOFT_CLOSED], true) && $period->end_date->toDateString() <= $today;
            $result = $candidate ? $this->readiness->evaluate($period) : null;
            $event = $last->get($period->id);
            $periods[] = [
                'id' => $period->id, 'code' => $period->code, 'name' => $period->name, 'fiscal_year_id' => $period->fiscal_year_id, 'fiscal_year_code' => $period->fiscalYear?->code,
                'start_date' => $period->start_date->toDateString(), 'end_date' => $period->end_date->toDateString(), 'status' => $period->status,
                'closed_at' => $period->closed_at?->toIso8601String(), 'closed_by' => $period->closed_by,
                'last_event' => $event === null ? null : [
                    'event_type' => $event->event_type, 'from_status' => $event->from_status, 'to_status' => $event->to_status, 'reason' => $event->reason,
                    'actor_id' => $event->actor_id, 'actor_name' => $event->actor_name, 'at' => $event->created_at,
                ],
                'last_pre_close_at' => $lastPreClose[$period->id] ?? null,
                'readiness' => $result === null ? null : [
                    'result' => $result->result(), 'blocking' => array_column($result->blocking(), 'code'), 'warnings' => array_column($result->warnings(), 'code'),
                ],
            ];
        }

        $runs = DB::table('year_end_closings')->where('tenant_id', $tenant)->orderByDesc('run_no')->get(['fiscal_year_id', 'run_no', 'status', 'journal_entry_id', 'run_at'])->unique('fiscal_year_id')->keyBy('fiscal_year_id');
        $years = DB::table('fiscal_years')->where('tenant_id', $tenant)->orderBy('start_date')->get(['id', 'code', 'name', 'start_date', 'end_date', 'status'])->map(function ($year) use ($runs) {
            $run = $runs->get($year->id);

            return [
                'id' => $year->id, 'code' => $year->code, 'name' => $year->name, 'start_date' => $year->start_date, 'end_date' => $year->end_date, 'status' => $year->status,
                'year_end' => ['status' => $run?->status ?? 'NOT_RUN', 'run_no' => $run?->run_no, 'journal_entry_id' => $run?->journal_entry_id, 'run_at' => $run?->run_at],
            ];
        })->all();

        return ['business_date' => $today, 'periods' => $periods, 'fiscal_years' => $years];
    }
}
