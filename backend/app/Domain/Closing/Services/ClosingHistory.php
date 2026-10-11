<?php

namespace App\Domain\Closing\Services;

use App\Domain\Closing\Models\ClosingEvent;
use App\Support\Database\Micros;
use App\Support\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The append-only closing history: pre-close runs, soft close, hard close, reopen and the year-end run / reversal, each with actor, reason
 * and the readiness evidence the actor saw. Rows are inserted here and nowhere else; the database refuses any UPDATE or DELETE, and every
 * row records the transaction that wrote it (the controlled reopen trigger matches on it).
 */
class ClosingHistory
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{accounting_period_id?:?string,fiscal_year_id?:?string,from_status?:?string,to_status?:?string,readiness?:?ReadinessResult,reason?:?string,reference?:?string,journal_entry_id?:?string}  $attributes
     */
    public function record(string $eventType, array $attributes): string
    {
        $readiness = $attributes['readiness'] ?? null;
        $id = (string) Str::uuid7();
        DB::table('closing_events')->insert([
            'id' => $id, 'tenant_id' => $this->context->tenantId(), 'accounting_period_id' => $attributes['accounting_period_id'] ?? null,
            'fiscal_year_id' => $attributes['fiscal_year_id'] ?? null, 'event_type' => $eventType,
            'from_status' => $attributes['from_status'] ?? null, 'to_status' => $attributes['to_status'] ?? null,
            'readiness' => $readiness?->result(), 'readiness_checks' => $readiness === null ? null : json_encode($readiness->evidence(), JSON_UNESCAPED_UNICODE),
            'reason' => $attributes['reason'] ?? null, 'reference' => $attributes['reference'] ?? null, 'actor_id' => $this->context->user()?->id,
            'journal_entry_id' => $attributes['journal_entry_id'] ?? null, 'created_at' => Micros::now(),
        ]);

        return $id;
    }

    /**
     * @param  array{period_id?:?string,fiscal_year_id?:?string,event_type?:?string,per_page?:?int}  $filters
     * @return LengthAwarePaginator<int,object>
     */
    public function query(array $filters): LengthAwarePaginator
    {
        $query = DB::table('closing_events as e')
            ->leftJoin('accounting_periods as p', fn ($join) => $join->on('p.id', '=', 'e.accounting_period_id')->on('p.tenant_id', '=', 'e.tenant_id'))
            ->leftJoin('fiscal_years as y', fn ($join) => $join->on('y.id', '=', 'e.fiscal_year_id')->on('y.tenant_id', '=', 'e.tenant_id'))
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
            ->where('e.tenant_id', $this->context->tenantId())
            ->when($filters['period_id'] ?? null, fn ($q, $v) => $q->where('e.accounting_period_id', $v))
            ->when($filters['fiscal_year_id'] ?? null, fn ($q, $v) => $q->where('e.fiscal_year_id', $v))
            ->when($filters['event_type'] ?? null, fn ($q, $v) => $q->where('e.event_type', $v))
            ->orderByDesc('e.created_at')->orderByDesc('e.id')
            ->select('e.id', 'e.event_type', 'e.accounting_period_id', 'p.code as period_code', 'e.fiscal_year_id', 'y.code as fiscal_year_code', 'e.from_status', 'e.to_status',
                'e.readiness', 'e.readiness_checks', 'e.reason', 'e.reference', 'e.actor_id', 'u.name as actor_name', 'e.journal_entry_id', 'e.created_at');

        return $query->paginate($filters['per_page'] ?? 25)->through(function ($row) {
            $row->readiness_checks = $row->readiness_checks === null ? null : json_decode($row->readiness_checks, true);

            return $row;
        });
    }

    /** The latest event of a type for a period, or null. */
    public function latest(string $periodId, string $eventType): ?ClosingEvent
    {
        return ClosingEvent::query()->where('accounting_period_id', $periodId)->where('event_type', $eventType)->orderByDesc('created_at')->orderByDesc('id')->first();
    }
}
