<?php

namespace Tests\Feature\Operational;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Payables\Services\ApSubledgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch I: the operational counters on the accounting home; authoritative figures, gated per section, scoped, tenant-isolated. */
class OperationalSummaryTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private const URL = self::AP.'/operational-summary';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false]);
        $this->signedIn($this->tenant);
    }

    /** The tenant's business date shifted by $days (the figures are relative to it; the clock is not faked, entitlements are time-bound). */
    private function day(int $days = 0): string
    {
        return Carbon::parse($this->inTenant($this->tenant, fn () => app(ApSubledgerService::class)->today()))->addDays($days)->toDateString();
    }

    private function dueInvoice(object $vendor, string $amount, string $due, array $override = []): array
    {
        $term = (string) DB::table('payment_terms')->where('tenant_id', $this->tenant->id)->where('code', 'CUSTOM')->value('id');

        return $this->postedInvoice($vendor, ['lines' => [['description' => 'Jasa', 'amount' => $amount]], 'payment_term_id' => $term, 'due_date' => $due, 'document_date' => '2026-03-05', 'posting_date' => '2026-03-05'] + $override);
    }

    /** Draft -> submitted (and approved for the second), never posted. */
    private function pendingInvoice(object $vendor, bool $approve): string
    {
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        if ($approve) {
            $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();
        }

        return $id;
    }

    public function test_figures_come_from_posted_documents_and_the_ledger(): void
    {
        $vendor = $this->vendor($this->tenant, 'V1');
        $bank = $this->cashAccount($this->tenant, 'BCA', 'BANK', '1120');
        $cash = $this->cashAccount($this->tenant, 'KAS', 'CASH', '1110');
        $this->signedIn($this->tenant);
        $overdue = $this->dueInvoice($vendor, '200000', $this->day(-10));
        $this->dueInvoice($vendor, '100000', $this->day(5)); // due in 5 days
        $this->dueInvoice($vendor, '500000', $this->day(60)); // open, not near
        $paid = $this->dueInvoice($vendor, '700000', $this->day(-30));
        $this->postedPayment($vendor, $bank->id, [$paid['id'] => '700000', $overdue['id'] => '50000']);
        $draft = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id'); // a draft counts nowhere
        $this->pendingInvoice($vendor, false);
        $this->pendingInvoice($vendor, true);

        $submittedPayment = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$overdue['id'] => '10000']))->assertCreated()->json('id');
        $this->postJson(self::AP."/vendor-payments/{$submittedPayment}/submit")->assertOk();
        $category = $this->expenseCategory($this->tenant, 'UTIL');
        $this->postJson(self::AP.'/expenses/'.$this->postJson(self::AP.'/expenses', $this->paidExpenseBody($category->id, $cash->id))->assertCreated()->json('id').'/submit')->assertOk();
        $this->approvedExpense($this->paidExpenseBody($category->id, $cash->id));
        $receipt = $this->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $cash->id, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => '3000000',
            'transaction_date' => '2026-03-01', 'purpose' => 'Modal', 'description' => 'Modal kas'])->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-receipts/{$receipt}/post")->assertOk();
        $this->postJson(self::AP."/cash-receipts/{$this->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $bank->id, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => '1000000', 'transaction_date' => '2026-03-02', 'purpose' => 'Modal', 'description' => 'Modal bank'])->json('id')}/post")->assertOk();

        $summary = $this->getJson(self::URL)->assertOk()->json();
        $this->assertSame($this->day(), $summary['business_date']);
        // Outstanding: 150.000 (overdue invoice after its 50.000 payment) + 100.000 + 500.000; the fully paid invoice and every unposted one are out.
        $this->assertSame(['750000.0000', 3], [$summary['payables']['outstanding']['amount'], $summary['payables']['outstanding']['invoices']]);
        $this->assertSame(['150000.0000', 1], [$summary['payables']['overdue']['amount'], $summary['payables']['overdue']['invoices']]);
        $this->assertSame([7, '100000.0000', 1], [$summary['payables']['due_soon']['days'], $summary['payables']['due_soon']['amount'], $summary['payables']['due_soon']['invoices']]);
        $this->assertSame([1, 1], [$summary['payables']['pending_approval'], $summary['payables']['awaiting_posting']]);
        $this->assertSame(['pending_approval' => 1, 'awaiting_posting' => 0], $summary['payments']);
        $this->assertSame(['pending_approval' => 1, 'awaiting_posting' => 1], $summary['expenses']);
        // Book balance: the receipts (4.000.000) minus the 700.000 + 50.000 paid from the bank.
        $this->assertSame(['3250000.0000', '3000000.0000', '250000.0000', 2], [$summary['cash_bank']['book_balance'], $summary['cash_bank']['cash'], $summary['cash_bank']['bank'], $summary['cash_bank']['accounts']]);
        $this->assertTrue($summary['complete']);

        // Reversing a posted invoice takes it out of every figure; the figures follow the ledger, not a cache.
        $this->postJson(self::AP.'/ap-invoices/'.$this->dueInvoice($this->vendor($this->tenant, 'V9'), '40000', $this->day(-3))['id'].'/reverse', ['reason' => 'Salah input', 'posting_date' => '2026-03-20'])->assertOk();
        $after = $this->getJson(self::URL)->assertOk()->json();
        $this->assertSame($summary['payables'], $after['payables']);
        $this->assertSame(1, DB::table('ap_invoices')->where('id', $draft)->where('status', 'DRAFT')->count());
    }

    public function test_a_section_appears_only_when_the_user_may_open_its_list(): void
    {
        $vendor = $this->vendor($this->tenant, 'V1');
        $this->signedIn($this->tenant);
        $this->dueInvoice($vendor, '100000', $this->day(5));

        $sections = fn (string $token) => collect($this->as($token)->getJson(self::URL)->assertOk()->json())->only(['payables', 'payments', 'expenses', 'cash_bank'])->map(fn ($s) => $s !== null)->all();
        $none = ['payables' => false, 'payments' => false, 'expenses' => false, 'cash_bank' => false];
        $this->assertEquals($none, $sections($this->memberToken($this->tenant, ['accounting.journal.view'])));
        $this->assertEquals(['payables' => true] + $none, $sections($this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.ap_invoice.view'])));
        $this->assertEquals(['expenses' => true] + $none, $sections($this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.expense.view'])));
        $this->assertEquals(['cash_bank' => true] + $none, $sections($this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.cash_bank.view'])));
        $this->assertEquals(['payments' => true] + $none, $sections($this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.ap_payment.view'])));
        $this->as($this->memberToken($this->tenant, ['accounting.ap_invoice.view']))->getJson(self::URL)->assertStatus(403); // reached like the home: journal view

        // A module the tenant lost closes its section even for a user who holds the permission; READ_ONLY keeps showing the numbers.
        $all = $this->memberToken($this->tenant, null);
        $module = fn (string $state) => DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', DB::table('modules')->where('code', 'ACCOUNTING_AP')->value('id'))->update(['state' => $state]);
        $module('READ_ONLY');
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->assertSame('100000.0000', $this->as($all)->getJson(self::URL)->assertOk()->json('payables.outstanding.amount'));
        $module('DISABLED');
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->assertEquals(['payables' => false, 'payments' => false, 'expenses' => true, 'cash_bank' => true], $sections($all));
        $module('ACTIVE');
        app(AccessCache::class)->touchTenant($this->tenant->id);

        // Without the accounting core the home itself is closed.
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', DB::table('modules')->where('code', 'ACCOUNTING_CORE')->value('id'))->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->as($all)->getJson(self::URL)->assertStatus(403);
    }

    public function test_figures_are_scoped_to_the_data_scope_and_never_cross_tenants(): void
    {
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $vendor = $this->vendor($this->tenant, 'V1');
        $northBank = $this->cashAccount($this->tenant, 'BCA-N', 'BANK', '1120', ['branch_id' => $north]);
        $southBank = $this->cashAccount($this->tenant, 'BCA-S', 'BANK', '1160', ['branch_id' => $south]);
        $this->signedIn($this->tenant);
        $this->dueInvoice($vendor, '100000', $this->day(5), ['branch_id' => $north]);
        $this->dueInvoice($vendor, '900000', $this->day(5), ['branch_id' => $south]);
        foreach ([[$northBank, '1000000', $north], [$southBank, '8000000', $south]] as [$account, $amount, $branch]) {
            $id = $this->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $account->id, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => $amount,
                'transaction_date' => '2026-03-01', 'purpose' => 'Modal', 'description' => 'Modal', 'branch_id' => $branch])->assertCreated()->json('id');
            $this->postJson(self::AP."/cash-receipts/{$id}/post")->assertOk();
        }

        $other = $this->payablesTenant('bravo', ['sod_creator_not_approver' => false]);
        $this->signedIn($other);
        $theirs = $this->vendor($other, 'B1');
        $this->postedInvoice($theirs, ['lines' => [['description' => 'x', 'amount' => '7777777']]]);

        $this->signedIn($this->tenant);
        $whole = $this->getJson(self::URL)->assertOk();
        $whole->assertJsonPath('payables.outstanding.amount', '1000000.0000')->assertJsonPath('cash_bank.book_balance', '9000000.0000')->assertJsonPath('complete', true);

        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.journal.view', 'accounting.ap_invoice.view', 'accounting.cash_bank.view'], 'BRANCH', $north));
        $scoped->getJson(self::URL)->assertOk()->assertJsonPath('payables.outstanding.amount', '100000.0000')->assertJsonPath('payables.outstanding.invoices', 1)
            ->assertJsonPath('cash_bank.book_balance', '1000000.0000')->assertJsonPath('cash_bank.accounts', 1)->assertJsonPath('complete', false);

        $this->signedIn($other);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('payables.outstanding.amount', '7777777.0000')->assertJsonPath('cash_bank.accounts', 0)->assertJsonPath('cash_bank.book_balance', '0.0000');
    }
}
