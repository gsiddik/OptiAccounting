<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place that turns journal lines into balances. Everything comes from POSTED journal lines (drafts, submitted
 * and approved journals never count; there is no stored balance). Semantics shared by the general ledger and the trial
 * balance, for a range [from, to]:
 *
 *   opening  = lines posted before `from`, plus every OPENING journal dated up to `to`
 *   movement = lines posted in [from, to], except OPENING journals (debit and credit kept apart)
 *   ending   = opening + movement
 *
 * An account's balance is expressed in its normal-balance direction (a debit-normal account is debit minus credit).
 * Data scope narrows the lines, so a scoped user sees a scoped ledger; `complete()` says whether the figures cover the
 * whole ledger (then total debits equal total credits by construction).
 */
class BalanceCalculator
{
    public function __construct(private readonly TenantContext $context, private readonly AccountingScope $scope) {}

    /** @return array{from:string,to:string} */
    public function range(array $filters): array
    {
        if (! empty($filters['period_id'])) {
            $period = AccountingPeriod::query()->find($filters['period_id'])
                ?? throw new DomainException('The period does not exist.', 'PERIOD_NOT_FOUND', 404);

            return ['from' => $period->start_date->toDateString(), 'to' => $period->end_date->toDateString()];
        }

        $to = $filters['to'] ?? Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
        $from = $filters['from']
            ?? FiscalYear::query()->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $to)->value('start_date')?->toDateString()
            ?? substr($to, 0, 8).'01';
        if ($from > $to) {
            throw new DomainException('The start date is after the end date.', 'RANGE_INVALID', 422);
        }

        return ['from' => $from, 'to' => $to];
    }

    /** POSTED lines of this tenant with their journal and account, narrowed by data scope and dimension filters (no date filter). */
    public function base(array $filters): Builder
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', fn ($join) => $join->on('je.id', '=', 'jl.journal_entry_id')->on('je.tenant_id', '=', 'jl.tenant_id'))
            ->where('jl.tenant_id', $this->context->tenantId())
            ->where('je.status', 'POSTED');

        foreach (['account_id', 'branch_id', 'business_unit_id', 'cost_center_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where("jl.{$column}", $filters[$column]);
            }
        }
        $this->scope->restrictLines($query);

        return $query;
    }

    /**
     * Opening net (debit - credit), period debit and period credit per account.
     *
     * @return Collection<string,object{account_id:string,opening_net:string,debit:string,credit:string}>
     */
    public function totals(string $from, string $to, array $filters): Collection
    {
        return $this->base($filters)->where('je.posting_date', '<=', $to)
            ->select('jl.account_id')
            ->selectRaw("coalesce(sum(case when je.posting_date < ? or je.journal_type = 'OPENING' then jl.debit - jl.credit else 0 end), 0) as opening_net", [$from])
            ->selectRaw("coalesce(sum(case when je.posting_date >= ? and je.journal_type <> 'OPENING' then jl.debit else 0 end), 0) as debit", [$from])
            ->selectRaw("coalesce(sum(case when je.posting_date >= ? and je.journal_type <> 'OPENING' then jl.credit else 0 end), 0) as credit", [$from])
            ->groupBy('jl.account_id')->get()->keyBy('account_id');
    }

    /** Do the figures cover the whole ledger (tenant-wide data scope, no dimension filter)? */
    public function complete(array $filters): bool
    {
        return $this->scope->isTenantWide() && empty($filters['branch_id']) && empty($filters['business_unit_id']) && empty($filters['cost_center_id']);
    }

    /** Net (debit - credit) in the account's normal-balance direction. */
    public static function signed(BigDecimal $net, string $normalBalance): BigDecimal
    {
        return $normalBalance === 'CREDIT' ? $net->negated() : $net;
    }

    /** @return array{0:BigDecimal,1:BigDecimal} net debit-minus-credit as (debit side, credit side) */
    public static function split(BigDecimal $net): array
    {
        return $net->isNegative() ? [BigDecimal::zero(), $net->negated()] : [$net, BigDecimal::zero()];
    }
}
