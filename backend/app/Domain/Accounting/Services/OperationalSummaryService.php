<?php

namespace App\Domain\Accounting\Services;

use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Domain\Accounting\Support\Money;
use App\Domain\CashBank\Services\CashBankAccountService;
use App\Domain\Expense\Services\ExpenseService;
use App\Domain\Payables\Services\ApInvoiceService;
use App\Domain\Payables\Services\ApSubledgerService;
use App\Domain\Payables\Services\VendorPaymentService;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The OA2 additions to the accounting home: a few operational counters and balances, no analytics. Every figure is read through the
 * same service queries as the lists (tenant, data scope) and from authoritative data (POSTED documents, the ledger), and a section is
 * only returned when the user could open the matching list: module entitlement AND permission, decided by the central resolver.
 * A section the user may not see is `null`, not zero.
 */
class OperationalSummaryService
{
    public const DUE_SOON_DAYS = 7;

    public function __construct(
        private readonly EffectiveAccess $access,
        private readonly TenantContext $context,
        private readonly AccountingScope $scope,
        private readonly ApSubledgerService $subledger,
        private readonly ApInvoiceService $invoices,
        private readonly VendorPaymentService $payments,
        private readonly ExpenseService $expenses,
        private readonly CashBankAccountService $cashBank,
    ) {}

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $today = $this->subledger->today();

        return [
            'business_date' => $today,
            'payables' => $this->allowed('accounting.ap_invoice.view', 'ACCOUNTING_AP', 'VENDOR_INVOICE') ? $this->payables($today) : null,
            'payments' => $this->allowed('accounting.ap_payment.view', 'ACCOUNTING_AP', 'AP_PAYMENT') ? $this->paymentCounts() : null,
            'expenses' => $this->allowed('accounting.expense.view', 'ACCOUNTING_EXPENSE', 'EXPENSE') ? $this->expenseCounts() : null,
            'cash_bank' => $this->allowed('accounting.cash_bank.view', 'ACCOUNTING_CASH_BANK', 'CASH_BANK_ACCOUNT') ? $this->cashBank($today) : null,
            'complete' => $this->scope->isTenantWide(), // false: the figures cover only what the user's data scope reaches
        ];
    }

    private function allowed(string $permission, string $module, string $feature): bool
    {
        $user = $this->context->user();

        return $user !== null && $this->access->evaluate(new AccessRequest(user: $user, tenantId: $this->context->tenantId(), permission: $permission, module: $module, feature: $feature))->allowed;
    }

    /** @return array<string,mixed> */
    private function payables(string $today): array
    {
        $open = $this->totals($this->invoices->query(['open' => true]), 'outstanding_amount');
        $overdue = $this->totals($this->invoices->query(['overdue' => true]), 'outstanding_amount');
        $soon = $this->totals($this->invoices->query(['due_within' => self::DUE_SOON_DAYS]), 'outstanding_amount');
        $pending = $this->invoices->query([])->toBase()->whereIn('ap_invoices.status', ['SUBMITTED', 'APPROVED'])->reorder()->select('ap_invoices.status')->selectRaw('count(*) as n')->groupBy('ap_invoices.status')->pluck('n', 'status');

        return [
            'outstanding' => ['amount' => $open['amount'], 'invoices' => $open['count']],
            'overdue' => ['amount' => $overdue['amount'], 'invoices' => $overdue['count']],
            'due_soon' => ['days' => self::DUE_SOON_DAYS, 'amount' => $soon['amount'], 'invoices' => $soon['count']],
            'pending_approval' => (int) ($pending['SUBMITTED'] ?? 0),
            'awaiting_posting' => (int) ($pending['APPROVED'] ?? 0),
        ];
    }

    /** @return array<string,int> */
    private function paymentCounts(): array
    {
        $pending = $this->payments->query([])->toBase()->whereIn('vendor_payments.status', ['SUBMITTED', 'APPROVED'])->reorder()->select('vendor_payments.status')->selectRaw('count(*) as n')->groupBy('vendor_payments.status')->pluck('n', 'status');

        return ['pending_approval' => (int) ($pending['SUBMITTED'] ?? 0), 'awaiting_posting' => (int) ($pending['APPROVED'] ?? 0)];
    }

    /** @return array<string,int> */
    private function expenseCounts(): array
    {
        $pending = $this->expenses->query([])->toBase()->whereIn('expenses.status', ['SUBMITTED', 'APPROVED'])->reorder()->select('expenses.status')->selectRaw('count(*) as n')->groupBy('expenses.status')->pluck('n', 'status');

        return ['pending_approval' => (int) ($pending['SUBMITTED'] ?? 0), 'awaiting_posting' => (int) ($pending['APPROVED'] ?? 0)];
    }

    /** @return array<string,mixed> */
    private function cashBank(string $today): array
    {
        $accounts = $this->cashBank->query(['status' => null])->get();
        $balances = $this->cashBank->bookBalances($accounts->pluck('account_id')->all(), $today);
        $byKind = ['CASH' => '0', 'BANK' => '0'];
        foreach ($accounts as $account) {
            $byKind[$account->kind] = Money::str(BigDecimal::of($byKind[$account->kind])->plus($balances[$account->account_id]));
        }

        return [
            'book_balance' => Money::str(Money::sum($balances)), 'cash' => Money::str($byKind['CASH']), 'bank' => Money::str($byKind['BANK']),
            'accounts' => $accounts->count(), 'as_of' => $today,
        ];
    }

    /** @return array{amount:string,count:int} */
    private function totals(Builder $query, string $column): array
    {
        $row = DB::query()->fromSub($query->toBase()->reorder(), 't')->selectRaw("coalesce(sum({$column}), 0) as amount, count(*) as n")->first();

        return ['amount' => Money::str((string) $row->amount), 'count' => (int) $row->n];
    }
}
