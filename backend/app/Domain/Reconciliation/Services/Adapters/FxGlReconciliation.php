<?php

namespace App\Domain\Reconciliation\Services\Adapters;

use App\Domain\Payables\Services\ApSubledgerService;
use App\Domain\Receivables\Services\ArSubledgerService;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Multi-currency against the general ledger (OA4 realised FX, module ACCOUNTING_MULTI_CURRENCY), as of the date. FX REVALUATION IS NOT
 * IMPLEMENTED (owner decision 2026-10-11): foreign open balances are carried at the rate they were recognised with, nothing is revalued,
 * and no revaluation posting or component exists here or is invented.
 *
 *   REALISED_FX_GAIN / REALISED_FX_LOSS   compared. The realised difference the settlement documents recorded (`fx_difference` of the posted vendor
 *                                         payments and customer receipts that settled foreign invoices; AP positive = loss, AR positive = gain)
 *                                         against the FX difference lines of their own journals (and reversals) on the FX_GAIN / FX_LOSS accounts.
 *   FOREIGN_OPEN_AP:<CCY> / FOREIGN_OPEN_AR:<CCY>   compared, one per foreign currency. The functional carrying amount of the open foreign
 *                                         invoices (the subledger's `outstanding_functional`) against the control account balance that the journals
 *                                         of the foreign invoices and settlements of that currency left there. They agree unless a foreign
 *                                         document's journal and its carrying value diverged; the foreign amount outstanding and the number of
 *                                         documents are reported inside the component.
 *   FX_ACCOUNT                            informational, one per FX gain / loss account: its whole ledger balance (manual postings and year-end
 *                                         closing also move it and are outside the comparison).
 *
 * Headline: the net realised FX gain, documents against ledger. NOT_APPLICABLE until a foreign-currency document has been posted by the date.
 */
class FxGlReconciliation extends ReconciliationAdapter
{
    public function __construct(private readonly ApSubledgerService $ap, private readonly ArSubledgerService $ar, private readonly TenantContext $context) {}

    public function type(): string
    {
        return ReconciliationResult::FX_GL;
    }

    public function module(): string
    {
        return 'ACCOUNTING_MULTI_CURRENCY';
    }

    public function reconcile(string $asOf, BigDecimal $tolerance): ReconciliationResult
    {
        $tenant = (string) $this->context->tenantId();
        if (! $this->hasForeignDocuments($tenant, $asOf)) {
            return $this->notApplicable($asOf, $tolerance, ['No foreign-currency document has been posted by this date, so there is no foreign exchange to reconcile.']);
        }

        $gainAccounts = $this->roleAccounts($tenant, 'FX_GAIN');
        $lossAccounts = $this->roleAccounts($tenant, 'FX_LOSS');
        $recorded = $this->recordedDifference($tenant, $asOf);
        $gainGl = $this->ledgerDifference($tenant, $asOf, $gainAccounts, 'l.credit - l.debit');
        $lossGl = $this->ledgerDifference($tenant, $asOf, $lossAccounts, 'l.debit - l.credit');

        $components = [
            $this->component('REALISED_FX_GAIN', 'Realised exchange gain of the settlements against the FX gain accounts', $recorded['gain'], $gainGl, $tolerance),
            $this->component('REALISED_FX_LOSS', 'Realised exchange loss of the settlements against the FX loss accounts', $recorded['loss'], $lossGl, $tolerance),
        ];
        $functional = (string) DB::table('accounting_profiles')->where('tenant_id', $tenant)->value('functional_currency');
        foreach (['AP' => $this->openForeign($this->ap->rowsAsOf($asOf), $functional), 'AR' => $this->openForeign($this->ar->rowsAsOf($asOf), $functional)] as $kind => $open) {
            $share = $this->controlShare($tenant, $asOf, strtolower($kind));
            foreach (array_unique([...array_keys($open), ...array_keys($share)]) as $currency) {
                $row = $open[$currency] ?? ['carrying' => BigDecimal::zero(), 'foreign' => BigDecimal::zero(), 'documents' => 0];
                $components[] = $this->component("FOREIGN_OPEN_{$kind}:{$currency}", "Open foreign {$kind} documents in {$currency}: functional carrying amount against the control account", $row['carrying'], $share[$currency] ?? BigDecimal::zero(), $tolerance, false, [
                    'currency' => $currency, 'foreign_outstanding' => $row['foreign']->toScale(4)->__toString(), 'documents' => $row['documents'],
                ]);
            }
        }
        foreach ([['FX_GAIN', $gainAccounts, 'l.credit - l.debit'], ['FX_LOSS', $lossAccounts, 'l.debit - l.credit']] as [$role, $accounts, $net]) {
            foreach ($this->accountBalances($tenant, $asOf, $accounts, $net) as $account) {
                $components[] = $this->component('FX_ACCOUNT', "{$role} account {$account['code']} {$account['name']}: whole ledger balance (not compared)", null, $account['balance'], $tolerance, true, [
                    'reference' => ['account_id' => $account['id'], 'code' => $account['code'], 'name' => $account['name'], 'role' => $role],
                ]);
            }
        }

        return $this->outcome($asOf, $tolerance, $recorded['gain']->minus($recorded['loss']), $gainGl->minus($lossGl), $components, [
            'Realised exchange differences of settlements and the carrying value of open foreign documents, documents against the ledger.',
            'FX revaluation is NOT IMPLEMENTED (owner decision 2026-10-11): open foreign balances stay at their historical rate and are not revalued; there is no unrealised gain or loss to reconcile.',
        ]);
    }

    private function hasForeignDocuments(string $tenant, string $asOf): bool
    {
        foreach (['ap_invoices' => 'functional_total_amount', 'ar_invoices' => 'functional_total_amount', 'vendor_payments' => 'functional_amount', 'customer_receipts' => 'functional_amount'] as $table => $column) {
            if (DB::table($table)->where('tenant_id', $tenant)->whereNotNull($column)->whereIn('status', ['POSTED', 'REVERSED'])->whereDate('posting_date', '<=', $asOf)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every account ever mapped to the role, whatever the mapping's status today (history stays reconcilable) */
    private function roleAccounts(string $tenant, string $role): array
    {
        return DB::table('account_mappings')->where('tenant_id', $tenant)->where('account_role', $role)->distinct()->pluck('account_id')->all();
    }

    /**
     * What the settlement documents in force on $asOf recorded as realised difference: a document counts from its posting date until its
     * reversal is posted. @return array{gain:BigDecimal,loss:BigDecimal}
     */
    private function recordedDifference(string $tenant, string $asOf): array
    {
        $sum = fn (string $table, string $expression) => BigDecimal::of((string) DB::table($table)->where('tenant_id', $tenant)->whereNotNull('fx_difference')->whereDate('posting_date', '<=', $asOf)
            ->where(fn ($q) => $q->where('status', 'POSTED')->orWhere(fn ($r) => $r->where('status', 'REVERSED')->whereDate('reversal_posting_date', '>', $asOf)))
            ->selectRaw("coalesce(sum({$expression}), 0) as total")->value('total'));
        $positive = 'case when fx_difference > 0 then fx_difference else 0 end';
        $negative = 'case when fx_difference < 0 then -fx_difference else 0 end';

        // AP: payment above carrying value = loss. AR: receipt above carrying value = gain.
        return [
            'gain' => $sum('customer_receipts', $positive)->plus($sum('vendor_payments', $negative)),
            'loss' => $sum('vendor_payments', $positive)->plus($sum('customer_receipts', $negative)),
        ];
    }

    /**
     * The FX difference lines of the settlement documents' journals (and reversals) on the given accounts, as of $asOf.
     *
     * @param  list<string>  $accounts
     */
    private function ledgerDifference(string $tenant, string $asOf, array $accounts, string $net): BigDecimal
    {
        if ($accounts === []) {
            return BigDecimal::zero();
        }
        $journals = DB::query()->fromSub($this->settlementJournals($tenant), 'x')->select('jid');

        return BigDecimal::of((string) DB::table('journal_lines as l')
            ->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->where('l.tenant_id', $tenant)->where('j.status', 'POSTED')->whereDate('j.posting_date', '<=', $asOf)->where('l.is_fx_difference', true)
            ->whereIn('l.account_id', $accounts)->whereIn('l.journal_entry_id', $journals)
            ->selectRaw("coalesce(sum({$net}), 0) as total")->value('total'));
    }

    /** The journals (posting and reversal) of the foreign settlement documents. */
    private function settlementJournals(string $tenant): Builder
    {
        $query = null;
        foreach (['vendor_payments', 'customer_receipts'] as $table) {
            foreach (['journal_entry_id', 'reversal_journal_id'] as $column) {
                $part = DB::table($table)->where('tenant_id', $tenant)->whereNotNull('fx_difference')->whereNotNull($column)->select("{$column} as jid");
                $query = $query === null ? $part : $query->unionAll($part);
            }
        }

        return $query;
    }

    /**
     * The open foreign invoices of a subledger by currency (functional carrying amount, foreign amount, number of invoices).
     *
     * @param  iterable<object>  $rows
     * @return array<string,array{carrying:BigDecimal,foreign:BigDecimal,documents:int}>
     */
    private function openForeign(iterable $rows, string $functional): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row->currency === $functional) {
                continue;
            }
            $out[$row->currency] ??= ['carrying' => BigDecimal::zero(), 'foreign' => BigDecimal::zero(), 'documents' => 0];
            $out[$row->currency]['carrying'] = $out[$row->currency]['carrying']->plus((string) $row->outstanding_functional_asof);
            $out[$row->currency]['foreign'] = $out[$row->currency]['foreign']->plus((string) $row->outstanding_asof);
            $out[$row->currency]['documents']++;
        }

        return $out;
    }

    /**
     * The balance the journals of the foreign invoices and foreign settlements left on the control accounts the foreign invoices were booked to,
     * by currency, as of $asOf (AP: credit minus debit, AR: debit minus credit).
     *
     * @return array<string,BigDecimal>
     */
    private function controlShare(string $tenant, string $asOf, string $kind): array
    {
        [$invoices, $settlements, $control, $net] = $kind === 'ap'
            ? ['ap_invoices', 'vendor_payments', 'payable_account_id', 'l.credit - l.debit']
            : ['ar_invoices', 'customer_receipts', 'receivable_account_id', 'l.debit - l.credit'];
        $accounts = DB::table($invoices)->where('tenant_id', $tenant)->whereNotNull('functional_total_amount')->whereNotNull($control)->distinct()->pluck($control)->all();
        if ($accounts === []) {
            return [];
        }
        $docs = null;
        foreach ([[$invoices, 'functional_total_amount'], [$settlements, 'functional_amount']] as [$table, $foreign]) {
            foreach (['journal_entry_id', 'reversal_journal_id'] as $column) {
                $part = DB::table($table)->where('tenant_id', $tenant)->whereNotNull($foreign)->whereNotNull($column)->select("{$column} as jid", 'currency');
                $docs = $docs === null ? $part : $docs->unionAll($part);
            }
        }

        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->joinSub($docs, 'd', 'd.jid', '=', 'j.id')
            ->where('l.tenant_id', $tenant)->where('j.status', 'POSTED')->whereDate('j.posting_date', '<=', $asOf)->whereIn('l.account_id', $accounts)
            ->groupBy('d.currency')->selectRaw("d.currency, sum({$net}) as balance")->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->currency] = BigDecimal::of((string) $row->balance);
        }

        return $out;
    }

    /**
     * @param  list<string>  $accounts
     * @return list<array{id:string,code:string,name:string,balance:BigDecimal}>
     */
    private function accountBalances(string $tenant, string $asOf, array $accounts, string $net): array
    {
        if ($accounts === []) {
            return [];
        }
        $balances = DB::table('journal_lines as l')
            ->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->where('l.tenant_id', $tenant)->where('j.status', 'POSTED')->whereDate('j.posting_date', '<=', $asOf)->whereIn('l.account_id', $accounts)
            ->groupBy('l.account_id')->selectRaw("l.account_id, sum({$net}) as balance")->pluck('balance', 'account_id');
        $out = [];
        foreach (DB::table('accounts')->where('tenant_id', $tenant)->whereIn('id', $accounts)->orderBy('code')->get(['id', 'code', 'name']) as $account) {
            $out[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'balance' => BigDecimal::of((string) ($balances[$account->id] ?? 0))];
        }

        return $out;
    }
}
