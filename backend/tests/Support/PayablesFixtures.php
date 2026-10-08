<?php

namespace Tests\Support;

use App\Domain\Accounting\Services\OperationalSetupService;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Services\PaymentTermService;
use App\Domain\Payables\Services\VendorService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Test helpers for the OA2 modules; combine with AccountingFixtures and Fixtures. */
trait PayablesFixtures
{
    protected const AP = '/api/v1/app/accounting';

    /** An accounting tenant that has the standard OA2 posting rules published from the start of its fiscal year and the standard payment terms. */
    protected function payablesTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        $tenant = $this->accountingTenant($code, profile: $profile);
        $this->inTenant($tenant, function () {
            app(OperationalSetupService::class)->applyDefaults('2026-01-01');
            app(PaymentTermService::class)->applyDefaults();
        });

        return $tenant;
    }

    protected function vendor(Tenant $tenant, string $code = 'V1', array $attributes = []): Vendor
    {
        return $this->inTenant($tenant, fn () => app(VendorService::class)->create($attributes + ['code' => $code, 'name' => "Vendor {$code}"]));
    }

    /** @return array<string,mixed> a one-line invoice body (amount 1.000.000 to the general expense account by role unless told otherwise) */
    protected function invoiceBody(Vendor $vendor, array $override = []): array
    {
        return $override + [
            'vendor_id' => $vendor->id, 'vendor_invoice_number' => 'INV-'.substr(uniqid(), -6), 'document_date' => '2026-03-10', 'posting_date' => '2026-03-10',
            'description' => 'Tagihan layanan', 'lines' => [['description' => 'Jasa konsultasi', 'amount' => '1000000']],
        ];
    }

    /** A bearer token for a new member of $tenant holding exactly $permissions (null = all); use with $this->as($token) so several users can alternate in one test. */
    protected function memberToken(Tenant $tenant, ?array $permissions = null, string $scope = 'TENANT'): string
    {
        [$user] = $this->member($tenant, $permissions, scope: $scope);

        return $this->tenantToken($user, $tenant);
    }

    /** A cash or bank account mapped to GL account $glCode (1120 Bank, 1110 Kas by default). */
    protected function cashAccount(Tenant $tenant, string $code = 'BCA', string $kind = 'BANK', ?string $glCode = null, array $attributes = []): CashBankAccount
    {
        $glCode ??= $kind === 'BANK' ? '1120' : '1110';

        $id = $this->asMember($tenant)->postJson(self::AP.'/cash-bank-accounts', $attributes + [
            'code' => $code, 'name' => "Akun {$code}", 'kind' => $kind, 'account_id' => $this->account($tenant, $glCode)->id,
        ] + ($kind === 'BANK' ? ['bank_name' => 'Bank Contoh'] : []))->assertCreated()->json('id');

        return $this->inTenant($tenant, fn () => CashBankAccount::query()->findOrFail($id));
    }

    /** @return list<string> ids of branches with the given codes */
    protected function branches(Tenant $tenant, string ...$codes): array
    {
        $org = app(OrganizationService::class);

        return $this->inTenant($tenant, fn () => array_map(fn (string $code) => $org->createBranch($tenant->id, ['code' => $code, 'name' => "Cabang {$code}"])->id, $codes));
    }

    /** A token for a member holding $permissions whose data scope is BRANCH (inside $branchId) or OWN. */
    protected function scopedToken(Tenant $tenant, array $permissions, string $type, ?string $branchId = null): string
    {
        [$user, $membership] = $this->member($tenant, $permissions);
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->delete();
        DB::table('data_scopes')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'tenant_user_id' => $membership->id, 'scope_type' => $type,
            'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->tenantToken($user, $tenant);
    }

    /** Create, submit, approve and post a vendor invoice as the already-authenticated client (the profile must not forbid one person doing all four). @return array<string,mixed> the posted invoice */
    protected function postedInvoice(Vendor $vendor, array $override = []): array
    {
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, $override))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();

        return $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk()->json();
    }

    /** A vendor payment body allocating the given amounts [invoice id => amount]; the payment amount is their sum unless told otherwise. */
    protected function paymentBody(Vendor $vendor, string $cashBankAccountId, array $allocations, array $override = []): array
    {
        $rows = [];
        $sum = BigDecimal::zero();
        foreach ($allocations as $invoiceId => $amount) {
            $rows[] = ['ap_invoice_id' => $invoiceId, 'amount' => (string) $amount];
            $sum = $sum->plus((string) $amount);
        }

        return $override + [
            'vendor_id' => $vendor->id, 'cash_bank_account_id' => $cashBankAccountId, 'payment_date' => '2026-03-20', 'posting_date' => '2026-03-20',
            'amount' => (string) $sum->toScale(4), 'payment_method' => 'TRANSFER', 'reference' => 'TRF-'.substr(uniqid(), -5), 'allocations' => $rows,
        ];
    }

    /** Create, submit, approve and post a payment as the already-authenticated client. @return array<string,mixed> the posted payment */
    protected function postedPayment(Vendor $vendor, string $cashBankAccountId, array $allocations, array $override = []): array
    {
        $id = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $cashBankAccountId, $allocations, $override))->assertCreated()->json('id');
        $this->postJson(self::AP."/vendor-payments/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$id}/approve")->assertOk();

        return $this->postJson(self::AP."/vendor-payments/{$id}/post")->assertOk()->json();
    }

    /** An expense category created through the API as an administrator; the account (code) or role it classifies to is optional. */
    protected function expenseCategory(Tenant $tenant, string $code = 'UTIL', array $attributes = []): ExpenseCategory
    {
        $id = $this->asMember($tenant)->postJson(self::AP.'/expense-categories', $attributes + ['code' => $code, 'name' => "Kategori {$code}"])->assertCreated()->json('id');

        return $this->inTenant($tenant, fn () => ExpenseCategory::query()->findOrFail($id));
    }

    /** @return array<string,mixed> a payable expense body (Rp 1.000.000 net plus Rp 110.000 tax) */
    protected function payableExpenseBody(string $categoryId, Vendor $vendor, array $override = []): array
    {
        return $override + [
            'settlement' => 'PAYABLE', 'expense_category_id' => $categoryId, 'vendor_id' => $vendor->id, 'expense_date' => '2026-03-12', 'description' => 'Biaya listrik Maret',
            'net_amount' => '1000000', 'tax_amount' => '110000', 'supporting_document' => 'KW-001',
        ];
    }

    /** @return array<string,mixed> a directly paid expense body (Rp 200.000, no tax) */
    protected function paidExpenseBody(string $categoryId, string $cashBankAccountId, array $override = []): array
    {
        return $override + [
            'settlement' => 'DIRECT_PAID', 'expense_category_id' => $categoryId, 'cash_bank_account_id' => $cashBankAccountId, 'expense_date' => '2026-03-12',
            'description' => 'Parkir dan tol', 'net_amount' => '200000', 'payee_name' => 'Operator parkir',
        ];
    }

    /** Create, submit and approve an expense as the already-authenticated client; returns its id. */
    protected function approvedExpense(array $body): string
    {
        $id = $this->postJson(self::AP.'/expenses', $body)->assertCreated()->json('id');
        $this->postJson(self::AP."/expenses/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/expenses/{$id}/approve")->assertOk();

        return $id;
    }

    /** Create, submit, approve and post an expense as the already-authenticated client. @return array<string,mixed> the posted expense */
    protected function postedExpense(array $body): array
    {
        $id = $this->approvedExpense($body);

        return $this->postJson(self::AP."/expenses/{$id}/post")->assertOk()->json();
    }

    /** Debit minus credit of the POSTED lines on a GL account (a decimal string, 4 places), optionally up to a posting date. */
    protected function glBalance(Tenant $tenant, string $code, ?string $asOf = null): string
    {
        $id = $this->account($tenant, $code)->id;

        return (string) BigDecimal::of((string) DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.tenant_id', $tenant->id)->where('j.status', 'POSTED')->where('l.account_id', $id)->when($asOf, fn ($q, $d) => $q->where('j.posting_date', '<=', $d))
            ->selectRaw('coalesce(sum(l.debit),0) - coalesce(sum(l.credit),0) as b')->value('b'))->toScale(4);
    }

    /** Count and totals of every POSTED journal line of the tenant: a document that must not reach the ledger leaves this unchanged. */
    protected function glFigures(Tenant $tenant): object
    {
        return DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.tenant_id', $tenant->id)->where('j.status', 'POSTED')
            ->selectRaw('count(*) as lines, coalesce(sum(l.debit),0) as debit, coalesce(sum(l.credit),0) as credit')->first();
    }
}
