<?php

namespace Tests\Feature\Operational;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch I: the posting rules and account mappings the OA2 events depend on, and the audit trail of the whole module. */
class OperationalRulesAndAuditTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private const EVENTS = ['AP_INVOICE_RECOGNIZED', 'VENDOR_PAYMENT', 'EXPENSE_RECOGNIZED', 'EXPENSE_PAID', 'CASH_PAYMENT', 'CASH_RECEIPT'];

    /** @return list<string> */
    private function actions(): array
    {
        return DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->distinct()->pluck('action')->all();
    }

    private Tenant $tenant;

    public function test_default_rules_cover_every_oa2_event_are_idempotent_and_audited(): void
    {
        $this->tenant = $this->accountingTenant('alpha');
        $this->signedIn($this->tenant);
        $before = $this->getJson(self::AP.'/operational-rules')->assertOk()->json('data');
        $this->assertSame(self::EVENTS, array_column($before, 'event_type'));
        $this->assertSame([false], array_unique(array_column($before, 'ready')));

        $first = $this->postJson(self::AP.'/operational-rules/defaults', ['effective_from' => '2026-01-01'])->assertCreated()->json();
        $this->assertSame(self::EVENTS, $first['created']);
        $this->assertSame([], $first['skipped']);
        $this->assertSame([true], array_unique(array_column($this->getJson(self::AP.'/operational-rules')->json('data'), 'ready')));

        $again = $this->postJson(self::AP.'/operational-rules/defaults')->assertCreated()->json();
        $this->assertSame([], $again['created']);
        $this->assertSame(['ALREADY_PUBLISHED'], array_unique(array_column($again['skipped'], 'reason')));
        $this->assertSame(6, DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->count()); // nothing was duplicated or overwritten

        $this->assertSame(6, DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', 'accounting.posting_rule.published')->count());
        $this->as($this->memberToken($this->tenant, ['accounting.posting_rule.view']))->postJson(self::AP.'/operational-rules/defaults')->assertStatus(403);
    }

    public function test_accounts_payable_can_only_be_used_by_the_events_of_the_payables_subledger(): void
    {
        $this->tenant = $this->payablesTenant('alpha');
        $client = $this->signedIn($this->tenant);
        $cases = [
            'CASH_PAYMENT' => [['side' => 'DEBIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'amount'], ['side' => 'CREDIT', 'account_role' => 'CASH_BANK_ACCOUNT', 'amount_key' => 'amount']],
            'CASH_RECEIPT' => [['side' => 'DEBIT', 'account_role' => 'CASH_BANK_ACCOUNT', 'amount_key' => 'amount'], ['side' => 'CREDIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'amount']],
            'EXPENSE_PAID' => [['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'total'], ['side' => 'CREDIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'total']],
        ];
        foreach ($cases as $event => $lines) {
            $rule = $client->postJson(self::AP.'/posting-rules', ['code' => "BAD-{$event}", 'event_type' => $event, 'name' => 'Salah', 'lines' => $lines])->assertCreated()->json();
            $client->postJson(self::AP."/posting-rules/{$rule['id']}/publish", ['effective_from' => '2026-06-01'])
                ->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_ROLE_RESTRICTED')->assertJsonPath('details.account_role', 'ACCOUNTS_PAYABLE');
            $this->assertSame('DRAFT', DB::table('posting_rules')->where('id', $rule['id'])->value('status'));
            $this->assertSame(1, DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->where('event_type', $event)->where('status', 'PUBLISHED')->count()); // the standard rule stays the only one
        }
        // The subledger's own events may use it.
        $own = $client->postJson(self::AP.'/posting-rules', ['code' => 'AP-V2', 'event_type' => 'VENDOR_PAYMENT', 'name' => 'Pembayaran vendor v2', 'lines' => [
            ['side' => 'DEBIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'amount'], ['side' => 'CREDIT', 'account_role' => 'CASH_BANK_ACCOUNT', 'amount_key' => 'amount'],
        ]])->assertCreated()->json();
        $client->postJson(self::AP."/posting-rules/{$own['id']}/publish", ['effective_from' => '2026-06-01'])->assertOk();
    }

    public function test_roles_that_the_document_names_cannot_be_given_a_tenant_mapping(): void
    {
        $this->tenant = $this->payablesTenant('alpha');
        $this->signedIn($this->tenant);
        foreach (['CASH_BANK_ACCOUNT', 'DOCUMENT_ACCOUNT'] as $role) {
            $this->putJson(self::AP.'/account-mappings', ['account_role' => $role, 'account_id' => $this->account($this->tenant, '6900')->id, 'effective_from' => '2026-01-01'])
                ->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_ROLE_DOCUMENT_BOUND');
        }
        $this->assertSame(0, DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->whereIn('account_role', ['CASH_BANK_ACCOUNT', 'DOCUMENT_ACCOUNT'])->count());
    }

    public function test_the_whole_module_leaves_an_audit_trail_without_bank_numbers(): void
    {
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false]);
        $vendor = $this->vendor($this->tenant, 'V-1');
        $bank = $this->cashAccount($this->tenant, 'BCA', 'BANK', '1120', ['account_number' => '9876543210987']);
        $this->signedIn($this->tenant);

        $unattributed = fn () => DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->whereNull('actor_user_id')->count();
        $setup = $unattributed(); // rows written by the test's own setup, outside any request

        // Master data: a payment term and a vendor financial profile change, a cash/bank account re-mapped.
        $term = DB::table('payment_terms')->where('tenant_id', $this->tenant->id)->where('code', 'NET30')->value('id');
        $this->patchJson(self::AP."/payment-terms/{$term}", ['name' => 'Net 30 hari'])->assertOk();
        $this->patchJson(self::AP."/vendors/{$vendor->id}", ['payment_term_id' => $term])->assertOk();
        $this->patchJson(self::AP."/cash-bank-accounts/{$bank->id}", ['account_id' => $this->account($this->tenant, '1160')->id])->assertOk();
        $this->patchJson(self::AP."/cash-bank-accounts/{$bank->id}", ['account_id' => $this->account($this->tenant, '1120')->id])->assertOk();

        // Documents through their whole life.
        $receipt = $this->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $bank->id, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => '2000000',
            'transaction_date' => '2026-03-01', 'purpose' => 'Modal', 'description' => 'Modal'])->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-receipts/{$receipt}/post")->assertOk();
        $this->postJson(self::AP."/cash-receipts/{$receipt}/reverse", ['reason' => 'Salah akun'])->assertOk();
        $invoice = $this->postedInvoice($vendor);
        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '300000'], ['payment_date' => '2026-03-10', 'posting_date' => '2026-03-10']);
        $this->postJson(self::AP."/vendor-payments/{$payment['id']}/reverse", ['reason' => 'Dobel', 'posting_date' => '2026-03-12'])->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$invoice['id']}/reverse", ['reason' => 'Dobel', 'posting_date' => '2026-03-13'])->assertOk();
        $category = $this->expenseCategory($this->tenant, 'UTIL');
        $expense = $this->postedExpense($this->paidExpenseBody($category->id, $bank->id));
        $this->postJson(self::AP."/expenses/{$expense['id']}/reverse", ['reason' => 'Salah', 'posting_date' => '2026-03-14'])->assertOk();

        // Reconciliation and a critical export.
        $statement = $this->postJson(self::AP.'/bank-statements', ['cash_bank_account_id' => $bank->id, 'reference' => 'BCA-03', 'statement_date' => '2026-03-31', 'closing_balance' => '0',
            'items' => [['item_date' => '2026-03-01', 'description' => 'Setoran', 'amount' => '2000000']]])->assertCreated()->json();
        $line = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.id', DB::table('cash_transactions')->where('id', $receipt)->value('journal_entry_id'))
            ->where('l.account_id', $this->account($this->tenant, '1120')->id)->value('l.id');
        $item = $statement['items'][0]['id'];
        $this->postJson(self::AP."/bank-statements/{$statement['id']}/items/{$item}/match", ['journal_line_id' => $line])->assertOk();
        $this->postJson(self::AP."/bank-statements/{$statement['id']}/items/{$item}/unmatch")->assertOk();
        $this->postJson(self::AP."/bank-statements/{$statement['id']}/items/{$item}/exception", ['notes' => 'Belum dicatat'])->assertOk();
        $this->postJson(self::AP."/bank-statements/{$statement['id']}/complete")->assertOk();
        $this->get(self::AP.'/ap-invoices/export')->assertOk()->streamedContent();

        $actions = $this->actions();
        foreach ([
            'payables.payment_term.updated', 'payables.vendor.created', 'payables.vendor.financial_profile_changed',
            'cash_bank.account.created', 'cash_bank.account.mapping_changed',
            'payables.ap_invoice.created', 'payables.ap_invoice.submitted', 'payables.ap_invoice.approved', 'payables.ap_invoice.posted', 'payables.ap_invoice.reversed',
            'payables.vendor_payment.created', 'payables.vendor_payment.allocated', 'payables.vendor_payment.posted', 'payables.vendor_payment.reversed', 'payables.vendor_payment.allocation_released',
            'expense.expense.created', 'expense.expense.submitted', 'expense.expense.approved', 'expense.expense.posted', 'expense.expense.reversed',
            'cash_bank.transaction.created', 'cash_bank.transaction.posted', 'cash_bank.transaction.reversed',
            'cash_bank.statement.created', 'cash_bank.statement.item_matched', 'cash_bank.statement.item_unmatched', 'cash_bank.statement.item_flagged', 'cash_bank.statement.completed',
            'payables.report.exported',
        ] as $expected) {
            $this->assertContains($expected, $actions, "missing audit action {$expected}");
        }

        // Every row names its tenant and actor, and nothing the trail holds contains a bank account number.
        $this->assertSame($setup, $unattributed()); // everything done through the API names the person who did it
        $this->assertSame(0, DB::table('audit_logs')->whereRaw('(changes::text like ? or context::text like ?)', ['%9876543210987%', '%9876543210987%'])->count());
        $this->assertSame(0, DB::table('document_transitions')->where('reason', 'like', '%9876543210987%')->count());
    }
}
