<?php

namespace App\Domain\Payables\Services;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Payables\Models\ApInvoice;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The accounts payable subledger. There is no stored payable balance: outstanding = posted invoice total - effective payment
 * allocations, derived on every read from the documents themselves. `figures` adds the derived columns to an invoice query;
 * `rowsAsOf` replays the same rule for any past date (a payment counts from its posting date until the posting date of its reversal),
 * which is what aging and the AP-to-GL reconciliation use. Every query is limited by tenant and by the user's data scope.
 */
class ApSubledgerService
{
    public function __construct(private readonly DocumentScope $scope, private readonly TenantContext $context) {}

    /** Today's business date in the tenant's timezone (the date overdue is judged on). */
    public function today(): string
    {
        $tenant = $this->context->tenantId() ? Tenant::query()->find($this->context->tenantId()) : null;

        return $tenant?->businessDate() ?? now()->toDateString();
    }

    /** SQL: what has been paid on the invoice row `ap_invoices` right now. */
    public const PAID_SQL = '(select coalesce(sum(a.amount), 0) from ap_payment_allocations a where a.tenant_id = ap_invoices.tenant_id and a.ap_invoice_id = ap_invoices.id and a.is_effective)';

    /** Add paid_amount, outstanding_amount and payment_status to a model query on ap_invoices (outstanding exists for POSTED invoices only). */
    public function figures(Builder $query): Builder
    {
        $paid = self::PAID_SQL;

        return $query->addSelect('ap_invoices.*')->selectRaw(
            "{$paid} as paid_amount, case when ap_invoices.status = 'POSTED' then ap_invoices.total_amount - {$paid} else 0 end as outstanding_amount,
             case when ap_invoices.status <> 'POSTED' then null when {$paid} = 0 then 'UNPAID' when {$paid} >= ap_invoices.total_amount then 'PAID' else 'PARTIALLY_PAID' end as payment_status"
        );
    }

    /** Settlement filters: payment_status (UNPAID|PARTIALLY_PAID|PAID), open (outstanding > 0), overdue (due before today and still open), due_within (days). */
    public function filter(Builder $query, array $filter, string $today): Builder
    {
        $paid = self::PAID_SQL;
        $open = "ap_invoices.status = 'POSTED' and ap_invoices.total_amount - {$paid} > 0";

        return $query
            ->when($filter['payment_status'] ?? null, fn ($q, $v) => $q->where('ap_invoices.status', 'POSTED')->whereRaw(match ($v) {
                'UNPAID' => "{$paid} = 0", 'PAID' => "{$paid} >= ap_invoices.total_amount", default => "{$paid} > 0 and {$paid} < ap_invoices.total_amount",
            }))
            ->when($filter['open'] ?? false, fn ($q) => $q->whereRaw($open))
            ->when($filter['overdue'] ?? false, fn ($q) => $q->whereRaw($open)->whereDate('ap_invoices.due_date', '<', $today))
            ->when($filter['due_within'] ?? null, fn ($q, $days) => $q->whereRaw($open)->whereDate('ap_invoices.due_date', '>=', $today)
                ->whereDate('ap_invoices.due_date', '<=', date('Y-m-d', strtotime("{$today} +{$days} days"))));
    }

    /** Posted invoices of one vendor that still have an outstanding amount, oldest due date first (the order a payment is applied in). */
    public function openInvoices(string $vendorId): Collection
    {
        $query = ApInvoice::query()->with(['vendor', 'branch'])->where('ap_invoices.vendor_id', $vendorId)->where('ap_invoices.status', ApInvoice::POSTED);
        $this->scope->restrict($query->getQuery(), 'ap_invoices');

        return $this->figures($query)->whereRaw('ap_invoices.total_amount - '.self::PAID_SQL.' > 0')
            ->orderBy('ap_invoices.due_date')->orderBy('ap_invoices.posting_date')->orderBy('ap_invoices.document_number')->get();
    }

    /**
     * Invoices that were payable on $asOf with what was still outstanding that day. An invoice counts from its posting date until the
     * posting date of its reversal; a payment reduces it from the payment's posting date until the posting date of the payment's reversal.
     *
     * @param  array{vendor_id?:?string,branch_id?:?string,business_unit_id?:?string,cost_center_id?:?string}  $filter
     * @return Collection<int,object> one row per invoice with outstanding_asof > 0
     */
    public function rowsAsOf(string $asOf, array $filter = [], bool $includeSettled = false): Collection
    {
        $paid = '(select coalesce(sum(a.amount), 0) from ap_payment_allocations a join vendor_payments p on p.tenant_id = a.tenant_id and p.id = a.vendor_payment_id
                  where a.tenant_id = i.tenant_id and a.ap_invoice_id = i.id and a.effective_at is not null and p.posting_date <= ?
                  and (p.status = \'POSTED\' or p.reversal_posting_date > ?))';

        $query = DB::table('ap_invoices as i')->join('vendors as v', fn ($j) => $j->on('v.id', '=', 'i.vendor_id')->on('v.tenant_id', '=', 'i.tenant_id'))
            ->where('i.tenant_id', $this->context->tenantId())
            ->whereIn('i.status', ['POSTED', 'REVERSED'])->whereDate('i.posting_date', '<=', $asOf)
            ->where(fn ($q) => $q->where('i.status', 'POSTED')->orWhereDate('i.reversal_posting_date', '>', $asOf))
            ->when($filter['vendor_id'] ?? null, fn ($q, $x) => $q->where('i.vendor_id', $x))
            ->when($filter['branch_id'] ?? null, fn ($q, $x) => $q->where('i.branch_id', $x))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $x) => $q->where('i.business_unit_id', $x))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $x) => $q->where('i.cost_center_id', $x))
            ->selectRaw("i.id, i.document_number, i.vendor_invoice_number, i.vendor_id, v.code as vendor_code, v.name as vendor_name, i.posting_date, i.due_date, i.branch_id, i.business_unit_id,
                         i.cost_center_id, i.payable_account_id, i.total_amount, {$paid} as paid_asof, i.total_amount - {$paid} as outstanding_asof", [$asOf, $asOf, $asOf, $asOf]);

        $this->scope->restrict($query, 'i');
        if (! $includeSettled) {
            $query->whereRaw("i.total_amount - {$paid} > 0", [$asOf, $asOf]);
        }

        return $query->orderBy('v.code')->orderBy('i.due_date')->orderBy('i.document_number')->get();
    }

    /** Total outstanding on $asOf over the user's scope, as a decimal string. */
    public function totalAsOf(string $asOf, array $filter = []): string
    {
        return Money::str(Money::sum($this->rowsAsOf($asOf, $filter)->pluck('outstanding_asof')->all()));
    }
}
