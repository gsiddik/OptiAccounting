<?php

namespace App\Domain\Payables\Services;

use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Accounting\Support\Money;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Proof that the AP subledger equals the AP control accounts of the general ledger on an as-of date. Both sides are read, never
 * adjusted: GL = credits minus debits of POSTED lines on the control accounts (those the AP role is mapped to, the vendor overrides
 * and every account an invoice was booked to); subledger = what the documents say is outstanding that day. An opening balance
 * posted straight to a control account has no documents behind it, so it is shown as its own component and excluded from the
 * comparison. A difference is reported as MISMATCH with the figures; nothing is posted to hide it. The vendor breakdown attributes each
 * GL line to a vendor through the document that owns its journal (the document, or its reversal).
 */
class ApReconciliationService
{
    public function __construct(private readonly ApSubledgerService $subledger, private readonly AccountingScope $scope, private readonly TenantContext $context) {}

    /** @return array<string,mixed> */
    public function report(string $asOf, ?string $vendorId = null): array
    {
        $tenant = $this->context->tenantId();
        $accountIds = $this->controlAccountIds();
        $accounts = DB::table('accounts')->where('tenant_id', $tenant)->whereIn('id', $accountIds)->orderBy('code')->get(['id', 'code', 'name']);

        $attributed = $this->glByVendor($asOf, $accountIds);
        $subledger = $this->subledger->rowsAsOf($asOf, $vendorId ? ['vendor_id' => $vendorId] : []);
        $subByVendor = [];
        foreach ($subledger as $row) {
            $subByVendor[$row->vendor_id] = ($subByVendor[$row->vendor_id] ?? BigDecimal::zero())->plus((string) $row->outstanding_functional_asof);
        }

        $vendorIds = array_values(array_unique(array_merge(array_keys($subByVendor), array_filter(array_keys($attributed['by_vendor'])))));
        if ($vendorId !== null) {
            $vendorIds = [$vendorId];
        }
        $names = DB::table('vendors')->where('tenant_id', $tenant)->whereIn('id', $vendorIds)->get(['id', 'code', 'name'])->keyBy('id');

        $vendors = [];
        foreach ($vendorIds as $id) {
            $gl = $attributed['by_vendor'][$id] ?? BigDecimal::zero();
            $sub = $subByVendor[$id] ?? BigDecimal::zero();
            if ($gl->isZero() && $sub->isZero()) {
                continue;
            }
            $vendors[] = [
                'vendor_id' => $id, 'vendor_code' => $names[$id]->code ?? null, 'vendor_name' => $names[$id]->name ?? null,
                'gl_balance' => Money::str($gl), 'subledger_balance' => Money::str($sub), 'difference' => Money::str($gl->minus($sub)),
                'status' => $gl->isEqualTo($sub) ? 'MATCHED' : 'MISMATCH',
            ];
        }
        usort($vendors, fn ($a, $b) => strcmp((string) $a['vendor_code'], (string) $b['vendor_code']));

        $glTransactional = $vendorId !== null ? ($attributed['by_vendor'][$vendorId] ?? BigDecimal::zero()) : $attributed['transactional'];
        $subTotal = Money::sum($subByVendor);
        $difference = $glTransactional->minus($subTotal);

        return [
            'as_of' => $asOf,
            'vendor_id' => $vendorId,
            'control_accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'gl_balance' => Money::str($attributed['by_account'][$a->id] ?? '0')])->all(),
            'gl_balance' => Money::str($vendorId !== null ? $glTransactional : $attributed['total']),
            'opening_balance_component' => Money::str($vendorId !== null ? '0' : $attributed['opening']),
            'gl_transactional_balance' => Money::str($glTransactional),
            'subledger_balance' => Money::str($subTotal),
            'difference' => Money::str($difference),
            'status' => $difference->isZero() ? 'MATCHED' : 'MISMATCH',
            'vendors' => $vendors,
            'complete' => $this->scope->isTenantWide(),
        ];
    }

    /** @return list<string> */
    private function controlAccountIds(): array
    {
        $tenant = $this->context->tenantId();

        return array_values(array_unique(array_merge(
            DB::table('account_mappings')->where('tenant_id', $tenant)->where('account_role', 'ACCOUNTS_PAYABLE')->pluck('account_id')->all(),
            DB::table('vendors')->where('tenant_id', $tenant)->whereNotNull('payable_account_id')->pluck('payable_account_id')->all(),
            DB::table('ap_invoices')->where('tenant_id', $tenant)->whereNotNull('payable_account_id')->distinct()->pluck('payable_account_id')->all(),
        )));
    }

    /**
     * GL payable balance (credit minus debit) of the control accounts up to $asOf, split by account, by origin (opening / documents) and by vendor.
     *
     * @param  list<string>  $accountIds
     * @return array{total:BigDecimal,opening:BigDecimal,transactional:BigDecimal,by_account:array<string,BigDecimal>,by_vendor:array<string,BigDecimal>}
     */
    private function glByVendor(string $asOf, array $accountIds): array
    {
        $empty = ['total' => BigDecimal::zero(), 'opening' => BigDecimal::zero(), 'transactional' => BigDecimal::zero(), 'by_account' => [], 'by_vendor' => []];
        if ($accountIds === []) {
            return $empty;
        }

        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', fn ($j) => $j->on('je.id', '=', 'jl.journal_entry_id')->on('je.tenant_id', '=', 'jl.tenant_id'))
            ->leftJoin('journal_entries as orig', fn ($j) => $j->on('orig.id', '=', 'je.reverses_journal_id')->on('orig.tenant_id', '=', 'je.tenant_id'))
            ->leftJoin('ap_invoices as ai', fn ($j) => $j->on('ai.tenant_id', '=', 'je.tenant_id')->whereRaw('(ai.journal_entry_id = je.id or ai.reversal_journal_id = je.id)'))
            ->leftJoin('vendor_payments as vp', fn ($j) => $j->on('vp.tenant_id', '=', 'je.tenant_id')->whereRaw('(vp.journal_entry_id = je.id or vp.reversal_journal_id = je.id)'))
            ->where('jl.tenant_id', $this->context->tenantId())->where('je.status', 'POSTED')->whereDate('je.posting_date', '<=', $asOf)
            ->whereIn('jl.account_id', $accountIds)
            ->groupByRaw("jl.account_id, coalesce(ai.vendor_id, vp.vendor_id), (je.journal_type = 'OPENING' or orig.journal_type = 'OPENING')")
            ->selectRaw("jl.account_id, coalesce(ai.vendor_id, vp.vendor_id) as vendor, (je.journal_type = 'OPENING' or orig.journal_type = 'OPENING') as is_opening, sum(jl.credit - jl.debit) as balance");
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
            if ($row->vendor !== null) {
                $out['by_vendor'][$row->vendor] = ($out['by_vendor'][$row->vendor] ?? BigDecimal::zero())->plus($amount);
            }
        }

        return $out;
    }
}
