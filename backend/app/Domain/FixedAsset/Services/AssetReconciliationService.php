<?php

namespace App\Domain\FixedAsset\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\BalanceCalculator;
use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\Tenant;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fixed asset register to general ledger reconciliation (OA4 batch F). For every asset account and accumulated depreciation account the
 * register names (and every account the role mapping points at), it sets what the register says on a date against what the posted ledger
 * holds, side by side. Nothing is ever adjusted to force a match: a difference is reported as it is (an AP line posted to the asset account
 * without being registered, a manual journal, a reversed invoice) and the person decides.
 *
 * As of a date the register counts the assets capitalized by then and not yet disposed, and the depreciation of the runs posted by then
 * (a run or disposal reversed after the date still counts, exactly as the ledger shows it). Both sides respect the user's data scope.
 */
class AssetReconciliationService
{
    public function __construct(private readonly BalanceCalculator $balances, private readonly DocumentScope $scope, private readonly TenantContext $context) {}

    /** @return array<string,mixed> */
    public function report(?string $asOf = null): array
    {
        $asOf ??= Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
        $tenant = $this->context->tenantId();

        $cost = $this->register($this->costQuery($tenant, $asOf));
        $accumulated = $this->register($this->accumulatedQuery($tenant, $asOf));

        $mapped = DB::table('account_mappings')->where('tenant_id', $tenant)->where('status', 'ACTIVE')->whereIn('account_role', ['FIXED_ASSET', 'ACCUMULATED_DEPRECIATION'])->get(['account_role', 'account_id']);
        foreach ($mapped as $m) {
            if ($m->account_role === 'FIXED_ASSET') {
                $cost[$m->account_id] ??= ['register' => BigDecimal::zero(), 'assets' => 0];
            } else {
                $accumulated[$m->account_id] ??= ['register' => BigDecimal::zero(), 'assets' => 0];
            }
        }

        $accounts = Account::query()->whereIn('id', array_unique([...array_keys($cost), ...array_keys($accumulated)]))->get()->keyBy('id');
        $gl = $this->balances->base([])->where('je.posting_date', '<=', $asOf)->whereIn('jl.account_id', $accounts->keys())
            ->groupBy('jl.account_id')->select('jl.account_id')->selectRaw('coalesce(sum(jl.debit), 0) as debit, coalesce(sum(jl.credit), 0) as credit')->get()->keyBy('account_id');

        $rows = fn (array $side, bool $debitNormal) => collect($side)->map(function ($r, $accountId) use ($accounts, $gl, $debitNormal) {
            $figure = $gl[$accountId] ?? null;
            $net = BigDecimal::of((string) ($figure->debit ?? 0))->minus((string) ($figure->credit ?? 0));
            $glAmount = $debitNormal ? $net : $net->negated();
            $difference = $glAmount->minus($r['register']);
            $account = $accounts[$accountId] ?? null;

            return [
                'account' => ['id' => $accountId, 'code' => $account?->code, 'name' => $account?->name], 'asset_count' => $r['assets'],
                'register' => Money::str($r['register']), 'ledger' => Money::str($glAmount), 'difference' => Money::str($difference), 'matched' => $difference->isZero(),
            ];
        })->sortBy(fn ($r) => $r['account']['code'])->values()->all();

        $costRows = $rows($cost, true);
        $accumRows = $rows($accumulated, false);
        $sum = fn (array $rows, string $key) => Money::sum(array_column($rows, $key));
        $registerNbv = $sum($costRows, 'register')->minus($sum($accumRows, 'register'));
        $ledgerNbv = $sum($costRows, 'ledger')->minus($sum($accumRows, 'ledger'));

        return [
            'as_of' => $asOf, 'complete' => $this->scope->isTenantWide(),
            'cost' => $costRows, 'accumulated_depreciation' => $accumRows,
            'totals' => [
                'register_cost' => Money::str($sum($costRows, 'register')), 'ledger_cost' => Money::str($sum($costRows, 'ledger')),
                'register_accumulated' => Money::str($sum($accumRows, 'register')), 'ledger_accumulated' => Money::str($sum($accumRows, 'ledger')),
                'register_net_book_value' => Money::str($registerNbv), 'ledger_net_book_value' => Money::str($ledgerNbv), 'difference' => Money::str($ledgerNbv->minus($registerNbv)),
            ],
            'reconciled' => collect([...$costRows, ...$accumRows])->every(fn ($r) => $r['matched']),
        ];
    }

    /** @return array<string,array{register:BigDecimal,assets:int}> keyed by account id */
    private function register(Builder $query): array
    {
        $out = [];
        foreach ($query->get() as $row) {
            $out[$row->account_id] = ['register' => BigDecimal::of((string) $row->amount), 'assets' => (int) $row->assets];
        }

        return $out;
    }

    /** Assets on the books at $asOf: capitalized by then, capitalization not reversed by then, not disposed by then (a later reversal puts them back). */
    private function onBooks(Builder $query, string $tenant, string $asOf, string $table = 'fixed_assets'): Builder
    {
        $this->scope->restrict($query, $table);

        return $query->where("{$table}.tenant_id", $tenant)->whereNotNull("{$table}.asset_number")->whereDate("{$table}.capitalization_date", '<=', $asOf)
            ->whereRaw("not exists (select 1 from journal_entries r where r.tenant_id = {$table}.tenant_id and r.id = {$table}.capitalization_reversal_journal_id and r.posting_date <= ?)", [$asOf])
            ->whereRaw("not ({$table}.capitalization_mode = 'REGISTER_ONLY' and {$table}.capitalization_reversed_at is not null)")
            ->whereRaw("not exists (select 1 from asset_disposals d where d.tenant_id = {$table}.tenant_id and d.fixed_asset_id = {$table}.id and d.posting_date <= ?
                and (d.status = 'POSTED' or (d.status = 'REVERSED' and d.reversal_posting_date > ?)))", [$asOf, $asOf]);
    }

    private function costQuery(string $tenant, string $asOf): Builder
    {
        return $this->onBooks(DB::table('fixed_assets'), $tenant, $asOf)->groupBy('fixed_assets.asset_account_id')
            ->select('fixed_assets.asset_account_id as account_id')->selectRaw('sum(fixed_assets.acquisition_cost) as amount, count(*) as assets');
    }

    private function accumulatedQuery(string $tenant, string $asOf): Builder
    {
        $query = DB::table('asset_depreciation_run_lines as l')
            ->join('asset_depreciation_runs as r', fn ($j) => $j->on('r.id', '=', 'l.run_id')->on('r.tenant_id', '=', 'l.tenant_id'))
            ->join('fixed_assets', fn ($j) => $j->on('fixed_assets.id', '=', 'l.fixed_asset_id')->on('fixed_assets.tenant_id', '=', 'l.tenant_id'))
            ->whereDate('r.posting_date', '<=', $asOf)
            ->whereRaw("(r.status = 'POSTED' or (r.status = 'REVERSED' and r.reversal_posting_date > ?))", [$asOf]);

        return $this->onBooks($query, $tenant, $asOf)->groupBy('fixed_assets.accumulated_account_id')
            ->select('fixed_assets.accumulated_account_id as account_id')->selectRaw('sum(l.amount) as amount, count(distinct l.fixed_asset_id) as assets');
    }
}
