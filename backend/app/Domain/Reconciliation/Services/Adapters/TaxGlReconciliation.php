<?php

namespace App\Domain\Reconciliation\Services\Adapters;

use App\Domain\Reconciliation\Services\ReconciliationResult;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * The tax transactions against the tax control accounts of the ledger (OA4 tax foundation, module ACCOUNTING_TAX), as of the date. THIS IS AN
 * ACCOUNTING RECONCILIATION, NOT A STATUTORY TAX-RETURN RECONCILIATION: it proves that the tax the documents recorded (the frozen
 * `tax_transactions`, at the functional amounts the report uses) is what their journals put on the tax accounts. It does not check rates,
 * filing periods or any tax-authority figure.
 *
 *   TAX_OUTPUT                  compared. Output tax recorded (always owed to the authority) against the credit balance the journals of those
 *                               documents (and their reversals) left on the tax control accounts.
 *   TAX_INPUT_RECOVERABLE       compared. Recoverable input tax recorded against the debit balance the journals of input documents left there.
 *   TAX_INPUT_NON_RECOVERABLE   informational. Input tax that is a cost of its line: recorded, deliberately not on a tax account.
 *   TAX_CONTROL_ACCOUNT         informational, one per tax control account: its whole ledger balance (settlements to the authority, the manual
 *                               tax of credit notes, opening balances and manual journals also move it and are outside the comparison).
 *
 * Tax control accounts are the accounts the tax codes and transactions name (explicit account or role) and every account mapped to those roles
 * (TAX_RECEIVABLE, TAX_PAYABLE by default), whatever the mapping's status today, so a re-mapped role cannot hide an earlier posting. Documents
 * are matched to the ledger through their own journals, like the cash/bank reconciliation, so a settlement or an unrelated journal on the same
 * account is never an error. Headline: the net payable (output minus recoverable input), records against ledger. NOT_APPLICABLE until a tax
 * transaction has been posted by the date.
 */
class TaxGlReconciliation extends ReconciliationAdapter
{
    public function __construct(private readonly TenantContext $context) {}

    public function type(): string
    {
        return ReconciliationResult::TAX_GL;
    }

    public function module(): string
    {
        return 'ACCOUNTING_TAX';
    }

    public function reconcile(string $asOf, BigDecimal $tolerance): ReconciliationResult
    {
        $tenant = (string) $this->context->tenantId();
        if (! DB::table('tax_transactions')->where('tenant_id', $tenant)->whereIn('status', ['POSTED', 'REVERSED'])->whereDate('posting_date', '<=', $asOf)->exists()) {
            return $this->notApplicable($asOf, $tolerance, ['No tax transaction has been posted by this date, so there is no tax to reconcile.']);
        }

        $outputSource = $this->recorded($tenant, $asOf, 'OUTPUT', true);
        $inputSource = $this->recorded($tenant, $asOf, 'INPUT', true);
        $costSource = $this->recorded($tenant, $asOf, 'INPUT', false);
        $accounts = $this->taxAccounts($tenant);
        $notes = ['Accounting reconciliation of recorded tax transactions with the tax control accounts; it is not a statutory tax-return reconciliation.'];

        if ($accounts === [] && ($outputSource['total']->isPositive() || $inputSource['total']->isPositive())) {
            return $this->incomplete($asOf, $tolerance, $outputSource['total']->minus($inputSource['total']), null, [
                ...$notes, 'Tax was recorded but no tax control account can be determined (no explicit tax account and no account mapped to a tax role).',
            ]);
        }

        $outputGl = $this->ledger($tenant, $asOf, 'OUTPUT', $accounts);
        $inputGl = $this->ledger($tenant, $asOf, 'INPUT', $accounts);
        $components = [
            $this->component('TAX_OUTPUT', 'Output tax recorded against the tax control accounts', $outputSource['total'], $outputGl, $tolerance, false, ['breakdown' => $outputSource['by_code']]),
            $this->component('TAX_INPUT_RECOVERABLE', 'Recoverable input tax recorded against the tax control accounts', $inputSource['total'], $inputGl, $tolerance, false, ['breakdown' => $inputSource['by_code']]),
        ];
        if ($costSource['total']->isPositive()) {
            $components[] = $this->component('TAX_INPUT_NON_RECOVERABLE', 'Input tax that is a cost of its line (recorded, not on a tax account; not compared)', $costSource['total'], null, $tolerance, true, ['breakdown' => $costSource['by_code']]);
        }
        foreach ($this->accountBalances($tenant, $asOf, $accounts) as $account) {
            $components[] = $this->component('TAX_CONTROL_ACCOUNT', "Tax control account {$account['code']} {$account['name']}: whole ledger balance (not compared)", null, $account['balance'], $tolerance, true, [
                'reference' => ['account_id' => $account['id'], 'code' => $account['code'], 'name' => $account['name']],
            ]);
        }

        return $this->outcome($asOf, $tolerance, $outputSource['total']->minus($inputSource['total']), $outputGl->minus($inputGl), $components, $notes);
    }

    /**
     * The tax recorded by transactions that are in force on $asOf (posted by then and not reversed by then), at the functional amounts.
     *
     * @return array{total:BigDecimal,by_code:list<array<string,mixed>>}
     */
    private function recorded(string $tenant, string $asOf, string $direction, bool $recoverable): array
    {
        $rows = DB::table('tax_transactions as t')
            ->leftJoin('journal_entries as rj', fn ($j) => $j->on('rj.id', '=', 't.reversal_journal_id')->on('rj.tenant_id', '=', 't.tenant_id'))
            ->where('t.tenant_id', $tenant)->where('t.direction', $direction)->where('t.is_recoverable', $recoverable)
            ->whereIn('t.status', ['POSTED', 'REVERSED'])->whereDate('t.posting_date', '<=', $asOf)
            ->whereRaw("(t.status = 'POSTED' or rj.posting_date > ?)", [$asOf])
            ->groupBy('t.tax_code', 't.tax_name')->orderBy('t.tax_code')
            ->selectRaw('t.tax_code, t.tax_name, sum(coalesce(t.functional_tax_amount, t.tax_amount)) as tax_amount, count(*) as transactions')->get();

        $total = BigDecimal::zero();
        $byCode = [];
        foreach ($rows as $row) {
            $total = $total->plus((string) $row->tax_amount);
            $byCode[] = ['tax_code' => $row->tax_code, 'tax_name' => $row->tax_name, 'tax_amount' => $this->money((string) $row->tax_amount), 'transactions' => (int) $row->transactions];
        }

        return ['total' => $total, 'by_code' => $byCode];
    }

    /**
     * The tax account balance the journals of the direction's tax transactions (and their reversals) left on the tax control accounts as of $asOf:
     * credit minus debit for output tax, debit minus credit for input tax.
     *
     * @param  list<string>  $accounts
     */
    private function ledger(string $tenant, string $asOf, string $direction, array $accounts): BigDecimal
    {
        if ($accounts === []) {
            return BigDecimal::zero();
        }
        $table = fn () => DB::table('tax_transactions')->where('tenant_id', $tenant)->where('direction', $direction)->whereIn('status', ['POSTED', 'REVERSED']);
        $journals = DB::query()->fromSub($table()->select('journal_entry_id as jid')->unionAll($table()->whereNotNull('reversal_journal_id')->select('reversal_journal_id as jid')), 'x')->select('jid');
        $net = $direction === 'OUTPUT' ? 'l.credit - l.debit' : 'l.debit - l.credit';

        return BigDecimal::of((string) DB::table('journal_lines as l')
            ->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->where('l.tenant_id', $tenant)->where('j.status', 'POSTED')->whereDate('j.posting_date', '<=', $asOf)
            ->whereIn('l.account_id', $accounts)->whereIn('l.journal_entry_id', $journals)
            ->selectRaw("coalesce(sum({$net}), 0) as total")->value('total'));
    }

    /** @return list<string> */
    private function taxAccounts(string $tenant): array
    {
        $roles = DB::table('tax_codes')->where('tenant_id', $tenant)->whereNotNull('account_role')->pluck('account_role')
            ->merge(DB::table('tax_transactions')->where('tenant_id', $tenant)->whereNotNull('account_role')->distinct()->pluck('account_role'))
            ->merge(['TAX_RECEIVABLE', 'TAX_PAYABLE'])->unique()->values()->all();

        return DB::table('account_mappings')->where('tenant_id', $tenant)->whereIn('account_role', $roles)->pluck('account_id')
            ->merge(DB::table('tax_codes')->where('tenant_id', $tenant)->whereNotNull('account_id')->pluck('account_id'))
            ->merge(DB::table('tax_transactions')->where('tenant_id', $tenant)->whereNotNull('account_id')->distinct()->pluck('account_id'))
            ->unique()->values()->all();
    }

    /**
     * The whole ledger balance of each tax control account as of $asOf, in the account's normal direction.
     *
     * @param  list<string>  $accounts
     * @return list<array{id:string,code:string,name:string,balance:BigDecimal}>
     */
    private function accountBalances(string $tenant, string $asOf, array $accounts): array
    {
        if ($accounts === []) {
            return [];
        }
        $balances = DB::table('journal_lines as l')
            ->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->where('l.tenant_id', $tenant)->where('j.status', 'POSTED')->whereDate('j.posting_date', '<=', $asOf)->whereIn('l.account_id', $accounts)
            ->groupBy('l.account_id')->selectRaw('l.account_id, sum(l.debit) as debit, sum(l.credit) as credit')->get()->keyBy('account_id');
        $out = [];
        foreach (DB::table('accounts')->where('tenant_id', $tenant)->whereIn('id', $accounts)->orderBy('code')->get(['id', 'code', 'name', 'normal_balance']) as $account) {
            $figure = $balances[$account->id] ?? null;
            $net = BigDecimal::of((string) ($figure->debit ?? 0))->minus((string) ($figure->credit ?? 0));
            $out[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'balance' => $account->normal_balance === 'DEBIT' ? $net : $net->negated()];
        }

        return $out;
    }

    private function money(string $value): string
    {
        return BigDecimal::of($value)->toScale(4)->__toString();
    }
}
