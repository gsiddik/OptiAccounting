<?php

namespace App\Domain\Closing\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\PostingRule;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\PostingEngine;
use App\Domain\Accounting\Services\PostingRuleService;
use App\Domain\Accounting\Services\ReversalService;
use App\Domain\Accounting\Support\Money;
use App\Domain\Accounting\Support\SourceTypes;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Closing\Models\ClosingEvent;
use App\Domain\Closing\Models\YearEndClosing;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The year-end closing (OA5 design doc 6): the cumulative balance of every REVENUE and EXPENSE account up to the last day of the fiscal year is
 * closed into the account the tenant mapped to RETAINED_EARNINGS, per branch / business unit group so dimension reports stay intact.
 *
 * Nothing here touches a balance. The run builds the business fact (the amount to close per account and dimension, the result per group) and hands
 * it to the Posting Engine as the event YEAR_END_CLOSING; the posting rule decides sides and roles, the engine writes one balanced SYSTEM journal
 * marked with the source type YEAR_END_CLOSING. A temporary account with a credit balance (revenue) is debited by `debit_close`, one with a debit
 * balance (expense) is credited by `credit_close`; each group's result goes to retained earnings as `profit` (credit) or `loss` (debit).
 *
 * run and reverse take the locks in the order the Posting Engine uses (tenant ledger gate exclusive, last period row, fiscal year row), so a
 * posting either lands before the run reads the balances or waits until it ends, and two simultaneous runs serialize: the second finds the first
 * POSTED. The database backs this with a partial unique index (one POSTED run per fiscal year). A correction is `reverse`: the closing journal is
 * reversed through the ReversalService, the run is marked REVERSED, and a new run (run_no + 1) is allowed; nothing is deleted.
 */
class YearEndClosingService
{
    private const SAMPLE = 20;

    public function __construct(
        private readonly PostingEngine $engine,
        private readonly PostingRuleService $rules,
        private readonly JournalService $journals,
        private readonly ReversalService $reversals,
        private readonly PeriodClosingService $closing,
        private readonly YearEndSetupService $setup,
        private readonly CloseReadinessService $readiness,
        private readonly ClosingLocks $locks,
        private readonly ClosingHistory $history,
        private readonly ClosingAccess $access,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------ reads

    /** @return array{fiscal_years:list<array<string,mixed>>} */
    public function index(): array
    {
        $this->readiness->assertFullScope();
        $runs = YearEndClosing::query()->orderBy('run_no')->get()->groupBy('fiscal_year_id');

        return ['fiscal_years' => FiscalYear::query()->orderBy('start_date')->get()->map(fn (FiscalYear $year) => $this->summary($year, $runs->get($year->id, new Collection)))->all()];
    }

    /** @return array<string,mixed> the fiscal year with its readiness hints and every run, reversed ones included */
    public function show(FiscalYear $year): array
    {
        $this->readiness->assertFullScope();
        $runs = YearEndClosing::query()->where('fiscal_year_id', $year->id)->orderBy('run_no')->get();

        return $this->summary($year, $runs) + ['runs' => $runs->map(fn (YearEndClosing $run) => $this->runView($run))->all()];
    }

    /** @return array<string,mixed> what a run would close today: per account and dimension, per group result, retained earnings, journal totals, blockers. Nothing is written. */
    public function preview(FiscalYear $year): array
    {
        $this->readiness->assertFullScope();
        $plan = $this->analyze($year, $this->lastPeriod($year));

        return [
            'fiscal_year' => $year->only(['id', 'code', 'name', 'start_date', 'end_date', 'status']),
            'closing_date' => $plan['closing_date'],
            'last_period' => $plan['last'] === null ? null : $plan['last']->only(['id', 'code', 'status']),
            'posting_rule' => $plan['rule'] === null ? null : ['code' => $plan['rule']->code, 'version' => $plan['rule']->version, 'effective_from' => $plan['rule']->effective_from->toDateString()],
            'retained_earnings_account' => $plan['retained']['account'] === null ? null : $plan['retained']['account']->only(['id', 'code', 'name']),
            'can_run' => $plan['blockers'] === [],
            'blockers' => $plan['blockers'],
            'totals' => $plan['totals'],
            'journal' => $plan['journal'],
            'groups' => $plan['groups'],
            'accounts' => $plan['accounts'],
        ];
    }

    // ------------------------------------------------------------------------------------------ run

    /** @return array<string,mixed> the new run */
    public function run(FiscalYear $year, ?string $reason = null): array
    {
        $user = $this->access->authorize('accounting.year_end.run');
        $this->readiness->assertFullScope();
        $reason = $this->reason($reason, false);

        return DB::transaction(function () use ($year, $user, $reason) {
            $last = $this->lastPeriod($year) ?? throw new DomainException('The fiscal year has no periods.', 'YEAR_END_NO_PERIODS', 409);
            $last = $this->locks->ledgerAndPeriod($last->id);
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);

            $plan = $this->analyze($year, $last);
            if ($plan['blockers'] !== []) {
                $first = $plan['blockers'][0];
                throw new DomainException($first['message'], $first['code'], 409, ($first['details'] ?? []) + ['blockers' => $plan['blockers']]);
            }

            $runNo = (int) YearEndClosing::query()->where('fiscal_year_id', $year->id)->max('run_no') + 1;
            $retained = $plan['retained']['account'];
            $event = $this->engine->postEvent(
                YearEndSetupService::EVENT, SourceTypes::YEAR_END_CLOSING, "{$year->id}:{$runNo}", $plan['closing_date'], $plan['payload'], [], 'POST',
                "Penutupan akhir tahun {$year->code} (proses {$runNo})", "YE-{$year->code}-{$runNo}", $user, allowSoftClosed: $last->status === AccountingPeriod::SOFT_CLOSED,
            );

            $run = new YearEndClosing;
            $run->forceFill([
                'fiscal_year_id' => $year->id, 'run_no' => $runNo, 'status' => YearEndClosing::POSTED, 'closing_date' => $plan['closing_date'],
                'journal_entry_id' => $event->journal_entry_id, 'retained_earnings_account_id' => $retained->id, 'closed_account_count' => $plan['closed_accounts'],
                'run_by' => $user->id, 'run_at' => now(),
            ])->save();

            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
            $this->history->record(ClosingEvent::YEAR_END_RUN, [
                'fiscal_year_id' => $year->id, 'from_status' => 'NOT_RUN', 'to_status' => YearEndClosing::POSTED, 'reason' => $reason,
                'reference' => $journal->journal_number, 'journal_entry_id' => $journal->id,
            ]);
            $this->audit->record('accounting.year_end.run', 'fiscal_year', $year->id, null, [
                'code' => $year->code, 'run_no' => $runNo, 'closing_date' => $plan['closing_date'], 'journal_id' => $journal->id, 'journal_number' => $journal->journal_number,
                'retained_earnings_account' => $retained->code, 'closed_accounts' => $plan['closed_accounts'], 'net_result' => $plan['totals']['net_result'],
                'result' => $plan['totals']['result'], 'last_period_status' => $last->status, 'reason' => $reason,
            ]);

            return $this->runView($run) + ['totals' => $plan['totals']];
        });
    }

    // ------------------------------------------------------------------------------------------ reverse

    /**
     * Reverse the POSTED year-end closing of a fiscal year. The reason is mandatory. When the last period (or the fiscal year) is CLOSED the
     * reversal also needs `accounting.period.reopen`: the period is reopened under the controlled-reopen rules and policy, with history, to
     * SOFT_CLOSED when the actor may post into a soft-closed period and to OPEN when not. A soft-closed last period that the actor may not post into
     * is stepped back to OPEN the same way. Afterwards a new run is possible.
     *
     * @return array<string,mixed> the reversed run
     */
    public function reverse(FiscalYear $year, string $reason): array
    {
        $user = $this->access->authorize('accounting.year_end.run');
        $this->readiness->assertFullScope();
        $reason = $this->reason($reason, true);

        return DB::transaction(function () use ($year, $user, $reason) {
            $last = $this->lastPeriod($year) ?? throw new DomainException('The fiscal year has no periods.', 'YEAR_END_NO_PERIODS', 409);
            $last = $this->locks->ledgerAndPeriod($last->id);
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);
            $run = YearEndClosing::query()->where('fiscal_year_id', $year->id)->where('status', YearEndClosing::POSTED)->lockForUpdate()->first()
                ?? throw new DomainException("Fiscal year {$year->code} has no posted year-end closing to reverse.", 'YEAR_END_NOT_POSTED', 409, ['fiscal_year' => $year->code]);
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($run->journal_entry_id);
            if (($later = $this->laterYearPosted($year)) !== null) {
                throw new DomainException("Fiscal year {$later} already has a posted year-end closing that sweeps this year's balances; reverse that one first.", 'YEAR_END_LATER_YEAR_POSTED', 409, ['fiscal_year' => $later]);
            }

            if ($journal->reversed_by_journal_id !== null) {
                $reversal = JournalEntry::query()->findOrFail($journal->reversed_by_journal_id); // reversed through the journal route: the run only catches up
            } else {
                $this->makePostable($year, $last, $user, $reason);
                $reversal = $this->reversals->reverse($journal, $user, $reason);
            }

            $run->forceFill([
                'status' => YearEndClosing::REVERSED, 'reversal_journal_id' => $reversal->id, 'reversed_by' => $user->id, 'reversed_at' => now(), 'reverse_reason' => $reason,
            ])->save();
            $this->history->record(ClosingEvent::YEAR_END_REVERSE, [
                'fiscal_year_id' => $year->id, 'from_status' => YearEndClosing::POSTED, 'to_status' => YearEndClosing::REVERSED, 'reason' => $reason,
                'reference' => $reversal->journal_number, 'journal_entry_id' => $reversal->id,
            ]);
            $this->audit->record('accounting.year_end.reversed', 'fiscal_year', $year->id, ['run_no' => $run->run_no, 'journal_id' => $journal->id, 'journal_number' => $journal->journal_number], [
                'code' => $year->code, 'run_no' => $run->run_no, 'reversal_journal_id' => $reversal->id, 'reversal_journal_number' => $reversal->journal_number, 'reason' => $reason,
            ]);

            return $this->runView($run->refresh());
        });
    }

    // ------------------------------------------------------------------------------------------ internals

    /** Bring the last period (and with it the fiscal year) to a state the reversal journal may be posted into. */
    private function makePostable(FiscalYear $year, AccountingPeriod $last, User $user, string $reason): void
    {
        $canSoft = $this->access->canPostSoftClosed($user);
        $closed = $last->status === AccountingPeriod::CLOSED || $year->status === FiscalYear::CLOSED;
        $steppedBack = $last->status === AccountingPeriod::SOFT_CLOSED && ! $canSoft;
        if (! $closed && ! $steppedBack) {
            return;
        }

        $this->access->require($user, 'accounting.period.reopen');
        $this->closing->reopenForYearEndReversal($last, $closed && $canSoft ? AccountingPeriod::SOFT_CLOSED : AccountingPeriod::OPEN, $reason);
    }

    private function lastPeriod(FiscalYear $year): ?AccountingPeriod
    {
        return AccountingPeriod::query()->where('fiscal_year_id', $year->id)->orderByDesc('end_date')->first();
    }

    /**
     * Everything a run needs, computed from POSTED lines at this moment: balances, groups, payload, journal check and the blockers. Run calls it
     * inside the locks; preview and the listings call it without.
     *
     * @return array<string,mixed>
     */
    private function analyze(FiscalYear $year, ?AccountingPeriod $last): array
    {
        $closingDate = $year->end_date->toDateString();
        $retained = $this->setup->retainedEarnings();
        $rule = $this->rule($closingDate);
        $rows = $this->balances($closingDate);
        $plan = $this->plan($rows, $retained['account']);
        $journal = $rule === null ? null : $this->simulate($rule, $plan['payload']);

        return $plan + [
            'closing_date' => $closingDate, 'last' => $last, 'rule' => $rule, 'retained' => $retained, 'journal' => $journal,
            'blockers' => $this->blockers($year, $last, $rows, $retained, $rule, $journal),
        ];
    }

    /**
     * Closing amounts per account and dimension, the result per branch / business unit group and the posting payload.
     *
     * @return array{payload:array<string,mixed>,totals:array<string,string>,groups:list<array<string,mixed>>,accounts:list<array<string,mixed>>,closed_accounts:int}
     */
    private function plan(Collection $rows, ?Account $retained): array
    {
        $labels = [
            'branch' => DB::table('branches')->whereIn('id', $rows->pluck('branch_id')->filter()->unique())->pluck('code', 'id'),
            'unit' => DB::table('business_units')->whereIn('id', $rows->pluck('business_unit_id')->filter()->unique())->pluck('code', 'id'),
        ];
        $debitClose = [];
        $creditClose = [];
        $groups = [];
        $accounts = [];
        foreach ($rows as $row) {
            $net = BigDecimal::of($row->net);
            $amount = Money::str($net->abs());
            $part = ['amount' => $amount, 'account_id' => $row->account_id, 'branch_id' => $row->branch_id, 'business_unit_id' => $row->business_unit_id, 'cost_center_id' => $row->cost_center_id];
            if ($net->isPositive()) {
                $creditClose[] = $part; // a debit balance (expense) is closed by a credit
            } else {
                $debitClose[] = $part;
            }

            $key = ($row->branch_id ?? '').'|'.($row->business_unit_id ?? '');
            $groups[$key] ??= ['branch_id' => $row->branch_id, 'business_unit_id' => $row->business_unit_id, 'revenue' => BigDecimal::zero(), 'expense' => BigDecimal::zero()];
            $side = $row->account_type === 'REVENUE' ? 'revenue' : 'expense';
            $groups[$key][$side] = $groups[$key][$side]->plus($side === 'revenue' ? $net->negated() : $net);

            $accounts[] = [
                'account_id' => $row->account_id, 'code' => $row->code, 'name' => $row->name, 'account_type' => $row->account_type, 'status' => $row->status,
                'branch_id' => $row->branch_id, 'branch_code' => $labels['branch'][$row->branch_id] ?? null, 'business_unit_id' => $row->business_unit_id,
                'business_unit_code' => $labels['unit'][$row->business_unit_id] ?? null, 'cost_center_id' => $row->cost_center_id,
                'balance' => Money::str($net), 'closes_with' => $net->isPositive() ? 'CREDIT' : 'DEBIT', 'amount' => $amount,
            ];
        }

        $profit = [];
        $loss = [];
        $groupViews = [];
        foreach ($groups as $group) {
            $result = $group['revenue']->minus($group['expense']);
            $part = ['amount' => Money::str($result->abs()), 'branch_id' => $group['branch_id'], 'business_unit_id' => $group['business_unit_id']];
            if ($result->isPositive()) {
                $profit[] = $part;
            } elseif ($result->isNegative()) {
                $loss[] = $part;
            }
            $groupViews[] = [
                'branch_id' => $group['branch_id'], 'branch_code' => $labels['branch'][$group['branch_id']] ?? null, 'business_unit_id' => $group['business_unit_id'],
                'business_unit_code' => $labels['unit'][$group['business_unit_id']] ?? null, 'revenue' => Money::str($group['revenue']), 'expense' => Money::str($group['expense']),
                'result' => Money::str($result),
            ];
        }

        $sum = fn (array $parts) => Money::sum(array_column($parts, 'amount'));
        $totals = ['debit_close' => $sum($debitClose), 'credit_close' => $sum($creditClose), 'profit' => $sum($profit), 'loss' => $sum($loss)];
        $net = $totals['profit']->minus($totals['loss']);
        $payload = array_map(fn (BigDecimal $amount) => Money::str($amount), $totals) + [
            'distribution' => array_filter(['debit_close' => $debitClose, 'credit_close' => $creditClose, 'profit' => $profit, 'loss' => $loss]),
            'role_accounts' => $retained === null ? [] : [YearEndSetupService::ROLE => $retained->id],
        ];

        return [
            'payload' => $payload,
            'totals' => [
                'revenue' => Money::str(Money::sum(array_column($groupViews, 'revenue'))), 'expense' => Money::str(Money::sum(array_column($groupViews, 'expense'))),
                'profit' => Money::str($totals['profit']), 'loss' => Money::str($totals['loss']), 'net_result' => Money::str($net), 'result' => $net->isPositive() ? 'PROFIT' : ($net->isNegative() ? 'LOSS' : 'BREAK_EVEN'),
            ],
            'groups' => $groupViews,
            'accounts' => $accounts,
            'closed_accounts' => $rows->pluck('account_id')->unique()->count(),
        ];
    }

    /**
     * The cumulative net (debit - credit) of every REVENUE / EXPENSE account up to the closing date, per account and dimension, from POSTED lines
     * only. Earlier year-end closings and their reversals are lines like any other, so a year that was closed already contributes zero.
     *
     * @return Collection<int,object>
     */
    private function balances(string $closingDate): Collection
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', fn ($join) => $join->on('je.id', '=', 'jl.journal_entry_id')->on('je.tenant_id', '=', 'jl.tenant_id'))
            ->join('accounts as a', fn ($join) => $join->on('a.id', '=', 'jl.account_id')->on('a.tenant_id', '=', 'jl.tenant_id'))
            ->where('jl.tenant_id', $this->tenantId())->where('je.status', JournalEntry::POSTED)->where('je.posting_date', '<=', $closingDate)
            ->whereIn('a.account_type', ['REVENUE', 'EXPENSE'])
            ->groupBy('jl.account_id', 'jl.branch_id', 'jl.business_unit_id', 'jl.cost_center_id', 'a.code', 'a.name', 'a.account_type', 'a.status')
            ->havingRaw('sum(jl.debit - jl.credit) <> 0')
            ->orderBy('a.code')->orderBy('jl.branch_id')->orderBy('jl.business_unit_id')->orderBy('jl.cost_center_id')
            ->selectRaw('jl.account_id, jl.branch_id, jl.business_unit_id, jl.cost_center_id, a.code, a.name, a.account_type, a.status, sum(jl.debit - jl.credit) as net')
            ->get();
    }

    private function rule(string $closingDate): ?PostingRule
    {
        try {
            return $this->rules->resolve(YearEndSetupService::EVENT, $closingDate);
        } catch (DomainException) {
            return null;
        }
    }

    /**
     * The published rule applied to the payload without storing anything, so a rule edited into something that does not balance is caught before a run.
     *
     * @return array{balanced:bool,total_debit:?string,total_credit:?string,line_count:int,error:?string}
     */
    private function simulate(PostingRule $rule, array $payload): array
    {
        try {
            $result = $this->rules->simulate($rule, $payload, [], (int) $this->journals->profile()->currency_scale);

            return ['balanced' => $result['balanced'], 'total_debit' => $result['total_debit'], 'total_credit' => $result['total_credit'], 'line_count' => count($result['lines']), 'error' => null];
        } catch (DomainException $e) {
            return ['balanced' => false, 'total_debit' => null, 'total_credit' => null, 'line_count' => 0, 'error' => $e->errorCode];
        }
    }

    /**
     * Every reason a run would be refused now, in the order they are reported: a first POSTED run ends the list at once.
     *
     * @param  array{account:?Account,problem:?string}  $retained
     * @param  array<string,mixed>|null  $journal
     * @return list<array{code:string,message:string,details:array<string,mixed>}>
     */
    private function blockers(FiscalYear $year, ?AccountingPeriod $last, Collection $rows, array $retained, ?PostingRule $rule, ?array $journal): array
    {
        if (YearEndClosing::query()->where('fiscal_year_id', $year->id)->where('status', YearEndClosing::POSTED)->exists()) {
            return [$this->blocker('YEAR_END_ALREADY_POSTED', "Fiscal year {$year->code} already has a posted year-end closing; reverse it first.", ['fiscal_year' => $year->code])];
        }

        $blockers = [];
        $later = $this->laterYearPosted($year);
        if ($later !== null) {
            $blockers[] = $this->blocker('YEAR_END_LATER_YEAR_POSTED', "Fiscal year {$later} already has a posted year-end closing that sweeps this year's balances; reverse that one first.", ['fiscal_year' => $later]);
        }
        if ($year->status !== FiscalYear::OPEN) {
            $blockers[] = $this->blocker('FISCAL_YEAR_NOT_OPEN', "Fiscal year {$year->code} is {$year->status}; only an open fiscal year can be closed at year end.", ['status' => $year->status]);
        }
        $future = AccountingPeriod::query()->where('fiscal_year_id', $year->id)->where('status', AccountingPeriod::FUTURE)->orderBy('start_date')->pluck('code');
        if ($future->isNotEmpty()) {
            $blockers[] = $this->blocker('YEAR_END_FUTURE_PERIODS', 'Every period of the fiscal year must have been opened.', ['periods' => $future->take(self::SAMPLE)->all(), 'count' => $future->count()]);
        }
        if ($last !== null && ! in_array($last->status, [AccountingPeriod::OPEN, AccountingPeriod::SOFT_CLOSED], true)) {
            $blockers[] = $this->blocker('YEAR_END_LAST_PERIOD_CLOSED', "The last period {$last->code} is {$last->status}; the closing entries are posted into an open or soft-closed period.", ['period' => $last->code, 'status' => $last->status]);
        }
        $pending = DB::table('journal_entries')->where('tenant_id', $this->tenantId())->whereIn('status', [JournalEntry::SUBMITTED, JournalEntry::APPROVED])
            ->where('posting_date', '<=', $year->end_date->toDateString())->count();
        if ($pending > 0) {
            $blockers[] = $this->blocker('YEAR_END_PENDING_JOURNALS', 'Post or cancel the submitted and approved journals first.', ['pending' => $pending]);
        }
        if ($retained['account'] === null) {
            $blockers[] = $this->blocker('YEAR_END_MAPPING_MISSING', 'Map an account to the role RETAINED_EARNINGS first.', ['account_role' => YearEndSetupService::ROLE, 'reason' => $retained['problem']]);
        }
        $inactive = $rows->where('status', '<>', Account::ACTIVE)->pluck('code')->unique();
        if ($rule === null) {
            $blockers[] = $this->blocker('YEAR_END_RULE_MISSING', 'Publish the posting rule YEAR_END_CLOSING (year-end/rule-defaults) with an effective date on or before the closing date.', ['event_type' => YearEndSetupService::EVENT]);
        } elseif ($journal !== null && ! $journal['balanced'] && $rows->isNotEmpty() && $retained['account'] !== null && $inactive->isEmpty()) {
            $blockers[] = $this->blocker('YEAR_END_RULE_INVALID', 'The posting rule YEAR_END_CLOSING does not produce a balanced closing journal.', ['rule' => $rule->code, 'version' => $rule->version, 'error' => $journal['error']]);
        }
        if ($inactive->isNotEmpty()) {
            $blockers[] = $this->blocker('YEAR_END_ACCOUNT_INACTIVE', 'An inactive account still has a balance; activate it so it can be closed.', ['accounts' => $inactive->take(self::SAMPLE)->values()->all()]);
        }
        if ($rows->isEmpty()) {
            $blockers[] = $this->blocker('YEAR_END_NOTHING_TO_CLOSE', 'No revenue or expense account has a balance to close.', []);
        }

        return $blockers;
    }

    /** The code of a later fiscal year whose POSTED year-end closing already swept this year's balances (cumulative), or null. */
    private function laterYearPosted(FiscalYear $year): ?string
    {
        return DB::table('year_end_closings as c')->join('fiscal_years as y', fn ($join) => $join->on('y.id', '=', 'c.fiscal_year_id')->on('y.tenant_id', '=', 'c.tenant_id'))
            ->where('c.tenant_id', $this->tenantId())->where('c.status', YearEndClosing::POSTED)->where('y.start_date', '>', $year->end_date->toDateString())
            ->orderBy('y.start_date')->value('y.code');
    }

    /** @return array{code:string,message:string,details:array<string,mixed>} */
    private function blocker(string $code, string $message, array $details): array
    {
        return ['code' => $code, 'message' => $message, 'details' => $details];
    }

    /**
     * @param  Collection<int,YearEndClosing>  $runs
     * @return array<string,mixed>
     */
    private function summary(FiscalYear $year, Collection $runs): array
    {
        $last = $this->lastPeriod($year);
        $plan = $this->analyze($year, $last);
        $current = $runs->firstWhere('status', YearEndClosing::POSTED) ?? $runs->last();

        return $year->only(['id', 'code', 'name', 'start_date', 'end_date', 'status']) + [
            'closing_date' => $plan['closing_date'],
            'last_period' => $last?->only(['id', 'code', 'status']),
            'year_end' => [
                'status' => $current?->status ?? 'NOT_RUN', 'run_no' => $current?->run_no, 'journal_entry_id' => $current?->journal_entry_id,
                'journal_number' => $current === null ? null : JournalEntry::query()->whereKey($current->journal_entry_id)->value('journal_number'), 'run_at' => $current?->run_at?->toIso8601String(),
            ],
            'can_run' => $plan['blockers'] === [],
            'blockers' => array_column($plan['blockers'], 'code'),
        ];
    }

    /** @return array<string,mixed> */
    private function runView(YearEndClosing $run): array
    {
        $numbers = JournalEntry::query()->whereIn('id', array_filter([$run->journal_entry_id, $run->reversal_journal_id]))->pluck('journal_number', 'id');
        $account = Account::query()->find($run->retained_earnings_account_id);
        $net = DB::table('journal_lines')->where('tenant_id', $run->tenant_id)->where('journal_entry_id', $run->journal_entry_id)->where('account_id', $run->retained_earnings_account_id)
            ->selectRaw('coalesce(sum(credit - debit), 0) as net')->value('net');

        return [
            'id' => $run->id, 'fiscal_year_id' => $run->fiscal_year_id, 'run_no' => $run->run_no, 'status' => $run->status, 'closing_date' => $run->closing_date->toDateString(),
            'journal_entry_id' => $run->journal_entry_id, 'journal_number' => $numbers[$run->journal_entry_id] ?? null,
            'reversal_journal_id' => $run->reversal_journal_id, 'reversal_journal_number' => $numbers[$run->reversal_journal_id] ?? null,
            'retained_earnings_account' => $account?->only(['id', 'code', 'name']), 'closed_account_count' => $run->closed_account_count, 'net_result' => Money::str($net),
            'run_by' => $run->run_by, 'run_at' => $run->run_at?->toIso8601String(), 'reversed_by' => $run->reversed_by, 'reversed_at' => $run->reversed_at?->toIso8601String(), 'reverse_reason' => $run->reverse_reason,
        ];
    }

    private function reason(?string $reason, bool $required): ?string
    {
        $reason = trim((string) $reason);
        if ($required && mb_strlen($reason) < 10) {
            throw new DomainException('Reversing the year-end closing needs a reason of at least 10 characters.', 'YEAR_END_REASON_REQUIRED', 422, ['min_length' => 10]);
        }

        return $reason === '' ? null : mb_substr($reason, 0, 500);
    }

    private function tenantId(): string
    {
        return $this->context->tenantId() ?? throw new \LogicException('The year-end closing needs a tenant context.');
    }
}
