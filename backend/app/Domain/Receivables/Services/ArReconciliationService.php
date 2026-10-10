<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Accounting\Support\Money;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Proof that the AR subledger equals the AR control accounts of the general ledger on an as-of date. Both sides are read, never
 * adjusted: GL = debits minus credits of POSTED lines on the control accounts (those the AR role is mapped to, the customer overrides
 * and every account an invoice was booked to); subledger = what the documents say is outstanding that day. An opening balance
 * posted straight to a control account has no documents behind it, so it is shown as its own component and excluded from the
 * comparison. A difference is reported as MISMATCH with the figures; nothing is posted to hide it. The customer breakdown attributes each
 * GL line to a customer through the document that owns its journal (invoice, receipt or credit note, or its reversal).
 */
class ArReconciliationService
{
    public function __construct(private readonly ArSubledgerService $subledger, private readonly AccountingScope $scope, private readonly TenantContext $context) {}

    /** @return array<string,mixed> */
    public function report(string $asOf, ?string $customerId = null): array
    {
        $tenant = $this->context->tenantId();
        $accountIds = $this->controlAccountIds();
        $accounts = DB::table('accounts')->where('tenant_id', $tenant)->whereIn('id', $accountIds)->orderBy('code')->get(['id', 'code', 'name']);

        $attributed = $this->glByCustomer($asOf, $accountIds);
        $subledger = $this->subledger->rowsAsOf($asOf, $customerId ? ['customer_id' => $customerId] : []);
        $subByCustomer = [];
        foreach ($subledger as $row) {
            $subByCustomer[$row->customer_id] = ($subByCustomer[$row->customer_id] ?? BigDecimal::zero())->plus((string) $row->outstanding_functional_asof);
        }

        $customerIds = array_values(array_unique(array_merge(array_keys($subByCustomer), array_filter(array_keys($attributed['by_customer'])))));
        if ($customerId !== null) {
            $customerIds = [$customerId];
        }
        $names = DB::table('customers')->where('tenant_id', $tenant)->whereIn('id', $customerIds)->get(['id', 'code', 'name'])->keyBy('id');

        $customers = [];
        foreach ($customerIds as $id) {
            $gl = $attributed['by_customer'][$id] ?? BigDecimal::zero();
            $sub = $subByCustomer[$id] ?? BigDecimal::zero();
            if ($gl->isZero() && $sub->isZero()) {
                continue;
            }
            $customers[] = [
                'customer_id' => $id, 'customer_code' => $names[$id]->code ?? null, 'customer_name' => $names[$id]->name ?? null,
                'gl_balance' => Money::str($gl), 'subledger_balance' => Money::str($sub), 'difference' => Money::str($gl->minus($sub)),
                'status' => $gl->isEqualTo($sub) ? 'MATCHED' : 'MISMATCH',
            ];
        }
        usort($customers, fn ($a, $b) => strcmp((string) $a['customer_code'], (string) $b['customer_code']));

        $glTransactional = $customerId !== null ? ($attributed['by_customer'][$customerId] ?? BigDecimal::zero()) : $attributed['transactional'];
        $subTotal = Money::sum($subByCustomer);
        $difference = $glTransactional->minus($subTotal);

        return [
            'as_of' => $asOf,
            'customer_id' => $customerId,
            'control_accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'gl_balance' => Money::str($attributed['by_account'][$a->id] ?? '0')])->all(),
            'gl_balance' => Money::str($customerId !== null ? $glTransactional : $attributed['total']),
            'opening_balance_component' => Money::str($customerId !== null ? '0' : $attributed['opening']),
            'gl_transactional_balance' => Money::str($glTransactional),
            'subledger_balance' => Money::str($subTotal),
            'difference' => Money::str($difference),
            'status' => $difference->isZero() ? 'MATCHED' : 'MISMATCH',
            'customers' => $customers,
            'complete' => $this->scope->isTenantWide(),
        ];
    }

    /** @return list<string> */
    private function controlAccountIds(): array
    {
        $tenant = $this->context->tenantId();

        return array_values(array_unique(array_merge(
            DB::table('account_mappings')->where('tenant_id', $tenant)->where('account_role', 'ACCOUNTS_RECEIVABLE')->pluck('account_id')->all(),
            DB::table('customers')->where('tenant_id', $tenant)->whereNotNull('receivable_account_id')->pluck('receivable_account_id')->all(),
            DB::table('ar_invoices')->where('tenant_id', $tenant)->whereNotNull('receivable_account_id')->distinct()->pluck('receivable_account_id')->all(),
        )));
    }

    /**
     * GL receivable balance (debit minus credit) of the control accounts up to $asOf, split by account, by origin (opening / documents) and by customer.
     *
     * @param  list<string>  $accountIds
     * @return array{total:BigDecimal,opening:BigDecimal,transactional:BigDecimal,by_account:array<string,BigDecimal>,by_customer:array<string,BigDecimal>}
     */
    private function glByCustomer(string $asOf, array $accountIds): array
    {
        $empty = ['total' => BigDecimal::zero(), 'opening' => BigDecimal::zero(), 'transactional' => BigDecimal::zero(), 'by_account' => [], 'by_customer' => []];
        if ($accountIds === []) {
            return $empty;
        }

        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', fn ($j) => $j->on('je.id', '=', 'jl.journal_entry_id')->on('je.tenant_id', '=', 'jl.tenant_id'))
            ->leftJoin('journal_entries as orig', fn ($j) => $j->on('orig.id', '=', 'je.reverses_journal_id')->on('orig.tenant_id', '=', 'je.tenant_id'))
            ->leftJoin('ar_invoices as ai', fn ($j) => $j->on('ai.tenant_id', '=', 'je.tenant_id')->whereRaw('(ai.journal_entry_id = je.id or ai.reversal_journal_id = je.id)'))
            ->leftJoin('customer_receipts as cr', fn ($j) => $j->on('cr.tenant_id', '=', 'je.tenant_id')->whereRaw('(cr.journal_entry_id = je.id or cr.reversal_journal_id = je.id)'))
            ->leftJoin('ar_credit_notes as cn', fn ($j) => $j->on('cn.tenant_id', '=', 'je.tenant_id')->whereRaw('(cn.journal_entry_id = je.id or cn.reversal_journal_id = je.id)'))
            ->where('jl.tenant_id', $this->context->tenantId())->where('je.status', 'POSTED')->whereDate('je.posting_date', '<=', $asOf)
            ->whereIn('jl.account_id', $accountIds)
            ->groupByRaw("jl.account_id, coalesce(ai.customer_id, cr.customer_id, cn.customer_id), (je.journal_type = 'OPENING' or orig.journal_type = 'OPENING')")
            ->selectRaw("jl.account_id, coalesce(ai.customer_id, cr.customer_id, cn.customer_id) as customer, (je.journal_type = 'OPENING' or orig.journal_type = 'OPENING') as is_opening, sum(jl.debit - jl.credit) as balance");
        $this->scope->restrictLines($query);

        $out = $empty;
        foreach ($query->get() as $row) {
            $amount = BigDecimal::of((string) $row->balance);
            $out['total'] = $out['total']->plus($amount);
            $out['by_account'][$row->account_id] = ($out['by_account'][$row->account_id] ?? BigDecimal::zero())->plus($amount);
            if ($row->is_opening) {
                $out['opening'] = $out['opening']->plus($amount);

                continue;
            }
            $out['transactional'] = $out['transactional']->plus($amount);
            if ($row->customer !== null) {
                $out['by_customer'][$row->customer] = ($out['by_customer'][$row->customer] ?? BigDecimal::zero())->plus($amount);
            }
        }

        return $out;
    }
}
