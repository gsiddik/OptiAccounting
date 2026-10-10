<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Receivables\Models\ArInvoice;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The accounts receivable subledger. There is no stored receivable balance: outstanding = posted invoice total - effective receipt
 * allocations - posted credit notes, derived on every read from the documents themselves. `figures` adds the derived columns to an
 * invoice query; `rowsAsOf` replays the same rule for any past date (a receipt or credit note counts from its posting date until the
 * posting date of its reversal), which is what aging and the AR-to-GL reconciliation use. Every query is limited by tenant and by the
 * user's data scope.
 */
class ArSubledgerService
{
    /** SQL: what has been received on the invoice row `ar_invoices` right now. */
    public const RECEIVED_SQL = '(select coalesce(sum(a.amount), 0)::numeric(20,4) from ar_receipt_allocations a where a.tenant_id = ar_invoices.tenant_id and a.ar_invoice_id = ar_invoices.id and a.is_effective)';

    /** SQL: what posted credit notes have taken off the invoice right now. */
    public const CREDITED_SQL = "(select coalesce(sum(c.total_amount), 0)::numeric(20,4) from ar_credit_notes c where c.tenant_id = ar_invoices.tenant_id and c.ar_invoice_id = ar_invoices.id and c.status = 'POSTED')";

    /** SQL: the functional value the effective receipts released from the invoice (the amount itself for a functional invoice). */
    public const CARRYING_SQL = '(select coalesce(sum(coalesce(a.carrying_amount, a.amount)), 0)::numeric(20,4) from ar_receipt_allocations a where a.tenant_id = ar_invoices.tenant_id and a.ar_invoice_id = ar_invoices.id and a.is_effective)';

    public function __construct(private readonly DocumentScope $scope, private readonly TenantContext $context) {}

    /** Today's business date in the tenant's timezone (the date overdue is judged on). */
    public function today(): string
    {
        $tenant = $this->context->tenantId() ? Tenant::query()->find($this->context->tenantId()) : null;

        return $tenant?->businessDate() ?? now()->toDateString();
    }

    /** Add received_amount, credited_amount, outstanding_amount and payment_status to a model query on ar_invoices (outstanding exists for POSTED invoices only). */
    public function figures(Builder $query): Builder
    {
        $received = self::RECEIVED_SQL;
        $credited = self::CREDITED_SQL;
        $carrying = self::CARRYING_SQL;

        return $query->addSelect('ar_invoices.*')->selectRaw(
            "{$received} as received_amount, {$credited} as credited_amount,
             case when ar_invoices.status = 'POSTED' then ar_invoices.total_amount - {$received} - {$credited} else 0 end as outstanding_amount,
             case when ar_invoices.status = 'POSTED' then coalesce(ar_invoices.functional_total_amount, ar_invoices.total_amount) - {$carrying} - {$credited} else 0 end as outstanding_functional,
             case when ar_invoices.status <> 'POSTED' then null when {$received} + {$credited} = 0 then 'UNPAID' when {$received} + {$credited} >= ar_invoices.total_amount then 'PAID' else 'PARTIALLY_PAID' end as payment_status"
        );
    }

    /** Settlement filters: payment_status (UNPAID|PARTIALLY_PAID|PAID), open (outstanding > 0), overdue (due before today and still open), due_within (days). */
    public function filter(Builder $query, array $filter, string $today): Builder
    {
        $settled = '('.self::RECEIVED_SQL.' + '.self::CREDITED_SQL.')';
        $open = "ar_invoices.status = 'POSTED' and ar_invoices.total_amount - {$settled} > 0";

        return $query
            ->when($filter['payment_status'] ?? null, fn ($q, $v) => $q->where('ar_invoices.status', 'POSTED')->whereRaw(match ($v) {
                'UNPAID' => "{$settled} = 0", 'PAID' => "{$settled} >= ar_invoices.total_amount", default => "{$settled} > 0 and {$settled} < ar_invoices.total_amount",
            }))
            ->when($filter['open'] ?? false, fn ($q) => $q->whereRaw($open))
            ->when($filter['overdue'] ?? false, fn ($q) => $q->whereRaw($open)->whereDate('ar_invoices.due_date', '<', $today))
            ->when($filter['due_within'] ?? null, fn ($q, $days) => $q->whereRaw($open)->whereDate('ar_invoices.due_date', '>=', $today)
                ->whereDate('ar_invoices.due_date', '<=', date('Y-m-d', strtotime("{$today} +{$days} days"))));
    }

    /**
     * Posted invoices of one customer in one currency (the functional one unless named) that still have an outstanding amount, oldest due date
     * first (the order a receipt is applied in). A receipt settles invoices of its own currency only.
     */
    public function openInvoices(string $customerId, ?string $currency = null): Collection
    {
        $currency ??= (string) AccountingProfile::query()->value('functional_currency');
        $query = ArInvoice::query()->with(['customer', 'branch'])->where('ar_invoices.customer_id', $customerId)->where('ar_invoices.status', ArInvoice::POSTED)->where('ar_invoices.currency', $currency);
        $this->scope->restrict($query->getQuery(), 'ar_invoices');

        return $this->figures($query)->whereRaw('ar_invoices.total_amount - '.self::RECEIVED_SQL.' - '.self::CREDITED_SQL.' > 0')
            ->orderBy('ar_invoices.due_date')->orderBy('ar_invoices.posting_date')->orderBy('ar_invoices.document_number')->get();
    }

    /**
     * Invoices that were receivable on $asOf with what was still outstanding that day. An invoice counts from its posting date until the
     * posting date of its reversal; a receipt or credit note reduces it from its own posting date until the posting date of its reversal.
     *
     * @param  array{customer_id?:?string,branch_id?:?string,business_unit_id?:?string,cost_center_id?:?string}  $filter
     * @return Collection<int,object> one row per invoice with outstanding_asof > 0
     */
    public function rowsAsOf(string $asOf, array $filter = [], bool $includeSettled = false): Collection
    {
        $received = '(select coalesce(sum(a.amount), 0) from ar_receipt_allocations a join customer_receipts p on p.tenant_id = a.tenant_id and p.id = a.customer_receipt_id
                      where a.tenant_id = i.tenant_id and a.ar_invoice_id = i.id and a.effective_at is not null and p.posting_date <= ?
                      and (p.status = \'POSTED\' or p.reversal_posting_date > ?))';
        $credited = '(select coalesce(sum(c.total_amount), 0) from ar_credit_notes c where c.tenant_id = i.tenant_id and c.ar_invoice_id = i.id
                      and c.posting_date <= ? and (c.status = \'POSTED\' or (c.status = \'REVERSED\' and c.reversal_posting_date > ?)))';
        $carrying = '(select coalesce(sum(coalesce(a.carrying_amount, a.amount)), 0) from ar_receipt_allocations a join customer_receipts p on p.tenant_id = a.tenant_id and p.id = a.customer_receipt_id
                      where a.tenant_id = i.tenant_id and a.ar_invoice_id = i.id and a.effective_at is not null and p.posting_date <= ?
                      and (p.status = \'POSTED\' or p.reversal_posting_date > ?))';
        $settled = "({$received} + {$credited})";

        $query = DB::table('ar_invoices as i')->join('customers as v', fn ($j) => $j->on('v.id', '=', 'i.customer_id')->on('v.tenant_id', '=', 'i.tenant_id'))
            ->where('i.tenant_id', $this->context->tenantId())
            ->whereIn('i.status', ['POSTED', 'REVERSED'])->whereDate('i.posting_date', '<=', $asOf)
            ->where(fn ($q) => $q->where('i.status', 'POSTED')->orWhereDate('i.reversal_posting_date', '>', $asOf))
            ->when($filter['customer_id'] ?? null, fn ($q, $x) => $q->where('i.customer_id', $x))
            ->when($filter['branch_id'] ?? null, fn ($q, $x) => $q->where('i.branch_id', $x))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $x) => $q->where('i.business_unit_id', $x))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $x) => $q->where('i.cost_center_id', $x))
            ->selectRaw("i.id, i.document_number, i.customer_reference, i.customer_id, v.code as customer_code, v.name as customer_name, i.posting_date, i.due_date, i.branch_id, i.business_unit_id,
                         i.cost_center_id, i.receivable_account_id, i.currency, i.exchange_rate, i.total_amount, {$received} as received_asof, {$credited} as credited_asof, i.total_amount - {$settled} as outstanding_asof,
                         coalesce(i.functional_total_amount, i.total_amount) as functional_total_amount, coalesce(i.functional_total_amount, i.total_amount) - {$carrying} - {$credited} as outstanding_functional_asof",
                [$asOf, $asOf, $asOf, $asOf, $asOf, $asOf, $asOf, $asOf, $asOf, $asOf, $asOf, $asOf]);

        $this->scope->restrict($query, 'i');
        if (! $includeSettled) {
            $query->whereRaw("i.total_amount - {$settled} > 0", [$asOf, $asOf, $asOf, $asOf]);
        }

        return $query->orderBy('v.code')->orderBy('i.due_date')->orderBy('i.document_number')->get();
    }

    /** Total outstanding on $asOf over the user's scope in functional currency (the figure the control account is compared with), as a decimal string. */
    public function totalAsOf(string $asOf, array $filter = []): string
    {
        return Money::str(Money::sum($this->rowsAsOf($asOf, $filter)->pluck('outstanding_functional_asof')->all()));
    }

    /**
     * The control account a posted journal debited for a receivable (an invoice): found in the stored posting snapshot and checked against
     * the document total, so the subledger and its control account always move together.
     */
    public function controlAccount(JournalEntry $journal, string $total): string
    {
        $debits = collect($journal->posting_snapshot['lines'] ?? [])->where('account_role', 'ACCOUNTS_RECEIVABLE')->where('side', 'DEBIT');
        $accounts = $debits->pluck('account_id')->unique();
        if ($accounts->count() !== 1 || ! Money::sum($debits->pluck('amount'))->isEqualTo($total)) {
            throw new DomainException('The AR invoice posting rule must debit the accounts receivable role with the invoice total.', 'AR_POSTING_RULE_INVALID', 422);
        }

        return $accounts->first();
    }
}
