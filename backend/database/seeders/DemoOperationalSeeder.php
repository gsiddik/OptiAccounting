<?php

namespace Database\Seeders;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\OperationalSetupService;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\CashBank\Models\CashTransaction;
use App\Domain\CashBank\Services\BankStatementService;
use App\Domain\CashBank\Services\CashBankAccountService;
use App\Domain\CashBank\Services\CashTransactionService;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Services\ExpenseCategoryService;
use App\Domain\Expense\Services\ExpenseService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Services\ApInvoiceService;
use App\Domain\Payables\Services\PaymentTermService;
use App\Domain\Payables\Services\VendorPaymentService;
use App\Domain\Payables\Services\VendorService;
use App\Domain\Receivables\Models\Customer;
use App\Domain\Receivables\Services\ArCreditNoteService;
use App\Domain\Receivables\Services\ArInvoiceService;
use App\Domain\Receivables\Services\CustomerReceiptService;
use App\Domain\Receivables\Services\CustomerService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Demo payables, expenses, receivables and cash/bank for one tenant whose demo books already exist (called by DemoSeeder after DemoAccountingSeeder; never
 * by DatabaseSeeder). Everything goes through the same services as the API, with the accountant preparing and the manager approving and
 * posting, so the segregation-of-duties policy and every accounting invariant apply to the demo data too. Dates are relative to today and stay
 * inside the open periods (DemoAccountingSeeder closes January and soft-closes February).
 */
class DemoOperationalSeeder
{
    private TenantContext $context;

    private string $floor;

    private string $today;

    /** @var array<string,string> */
    private array $accounts = [];

    public function seed(Tenant $tenant, User $accountant, User $manager): void
    {
        $this->context = app(TenantContext::class);
        $this->context->runAs($tenant->id, function () use ($accountant, $manager) {
            $today = now(config('app.timezone'));
            $this->today = $today->toDateString();
            $this->floor = $today->month >= 3 ? "{$today->year}-03-01" : "{$today->year}-01-01";
            $this->accounts = Account::query()->pluck('id', 'code')->all();
            $jakarta = Branch::query()->where('code', 'JKT')->value('id');

            $this->as($manager, function () use ($today) {
                app(OperationalSetupService::class)->applyDefaults("{$today->year}-01-01");
                app(PaymentTermService::class)->applyDefaults();
                app(ExpenseCategoryService::class)->applyDefaults();
            });

            $vendors = $this->as($manager, fn () => $this->vendors());
            $bank = $this->as($manager, fn () => $this->cashAccounts($jakarta));
            $this->payables($vendors, $bank['bca'], $accountant, $manager, $jakarta);
            $this->expenses($vendors, $bank['kas'], $accountant, $manager, $jakarta);
            $this->receivables($bank['bca'], $accountant, $manager, $jakarta);
            $this->cashAndReconciliation($bank['bca'], $accountant, $manager, $jakarta);
        });
    }

    /** @return array<string,Vendor> */
    private function vendors(): array
    {
        $term = fn (string $code) => PaymentTerm::query()->where('code', $code)->value('id');
        $service = app(VendorService::class);
        $make = fn (string $code, string $name, string $term, array $extra = []) => $service->create($extra + ['code' => $code, 'name' => $name, 'payment_term_id' => $term]);

        return [
            'makmur' => $make('SUMBER', 'PT Sumber Makmur', $term('NET30'), ['email' => 'tagihan@sumbermakmur.demo.test']),
            'listrik' => $make('LISTRIK', 'PT Listrik Nusantara', $term('NET14')),
            'servis' => $make('SERVIS', 'CV Armada Servis', $term('NET30')),
            'atk' => $make('ATK', 'Toko ATK Sejahtera', $term('COD')),
        ];
    }

    /** @return array{bca:CashBankAccount,kas:CashBankAccount} */
    private function cashAccounts(?string $branchId): array
    {
        $service = app(CashBankAccountService::class);

        return [
            'bca' => $service->create(['code' => 'BCA-OPS', 'name' => 'BCA Operasional', 'kind' => 'BANK', 'bank_name' => 'Bank Central Asia', 'account_number' => '0123456789',
                'account_id' => $this->accounts['1120'], 'branch_id' => $branchId]),
            'kas' => $service->create(['code' => 'KAS-KECIL', 'name' => 'Kas Kecil Jakarta', 'kind' => 'CASH', 'account_id' => $this->accounts['1110'], 'branch_id' => $branchId]),
        ];
    }

    /** @param array<string,Vendor> $vendors */
    private function payables(array $vendors, CashBankAccount $bank, User $accountant, User $manager, ?string $branchId): void
    {
        $invoices = app(ApInvoiceService::class);
        $payments = app(VendorPaymentService::class);
        $make = fn (Vendor $vendor, string $number, string $description, string $amount, int $daysAgo, array $extra = []) => $this->as($accountant, fn () => $invoices->create($extra + [
            'vendor_id' => $vendor->id, 'vendor_invoice_number' => $number, 'document_date' => $this->date($daysAgo), 'posting_date' => $this->date($daysAgo),
            'description' => $description, 'branch_id' => $branchId, 'lines' => [['description' => $description, 'amount' => $amount]],
        ], $accountant));
        $submit = fn ($invoice) => $this->as($accountant, fn () => $invoices->submit($invoice, $accountant));
        $approve = fn ($invoice) => $this->as($manager, fn () => $invoices->approve($invoice, $manager));
        $post = fn ($invoice) => $this->as($manager, fn () => $invoices->post($invoice, $manager));
        $posted = function (Vendor $vendor, string $number, string $description, string $amount, int $daysAgo, array $extra = []) use ($make, $submit, $approve, $post) {
            return $post($approve($submit($make($vendor, $number, $description, $amount, $daysAgo, $extra))));
        };

        $large = $posted($vendors['makmur'], 'SM-2026-0147', 'Pengadaan suku cadang armada', '12500000', 52);
        $power = $posted($vendors['listrik'], 'PLN-0098', 'Tagihan listrik kantor pusat', '4440000', 24);
        $service = $posted($vendors['servis'], 'AS-311', 'Servis berkala kendaraan operasional', '8000000', 9);
        $submit($make($vendors['atk'], 'ATK-5521', 'Alat tulis dan kertas', '1350000', 4));
        $approve($submit($make($vendors['makmur'], 'SM-2026-0161', 'Oli dan pelumas', '3200000', 2)));
        $make($vendors['servis'], 'AS-318', 'Penggantian ban armada (draf)', '6400000', 0);

        // Partly paid, fully paid and one payment still waiting for approval.
        $pay = fn (Vendor $vendor, array $allocations, int $daysAgo, string $reference) => $this->as($accountant, fn () => $payments->create([
            'vendor_id' => $vendor->id, 'cash_bank_account_id' => $bank->id, 'payment_date' => $this->date($daysAgo), 'posting_date' => $this->date($daysAgo),
            'amount' => (string) array_sum($allocations), 'payment_method' => 'TRANSFER', 'reference' => $reference, 'branch_id' => $branchId,
            'allocations' => array_map(fn ($id, $amount) => ['ap_invoice_id' => $id, 'amount' => (string) $amount], array_keys($allocations), $allocations),
        ], $accountant));
        $settle = function ($payment) use ($payments, $accountant, $manager) {
            $payment = $this->as($accountant, fn () => $payments->submit($payment, $accountant));
            $payment = $this->as($manager, fn () => $payments->approve($payment, $manager));

            return $this->as($manager, fn () => $payments->post($payment, $manager));
        };
        $settle($pay($vendors['makmur'], [$large->id => 5000000], 30, 'TRF-0001'));
        $settle($pay($vendors['listrik'], [$power->id => 4440000], 12, 'TRF-0002'));
        $waiting = $pay($vendors['servis'], [$service->id => 3000000], 1, 'TRF-0003');
        $this->as($accountant, fn () => $payments->submit($waiting, $accountant));
    }

    /** Customers, invoices in every state, partly and fully settled ones, a credit note and a receipt waiting for approval (OA3). */
    private function receivables(CashBankAccount $bank, User $accountant, User $manager, ?string $branchId): void
    {
        $term = fn (string $code) => PaymentTerm::query()->where('code', $code)->value('id');
        $customers = app(CustomerService::class);
        $make = fn (string $code, string $name, string $termCode, array $extra = []) => $this->as($manager, fn () => $customers->create($extra + ['code' => $code, 'name' => $name, 'payment_term_id' => $term($termCode)]));
        /** @var array<string,Customer> $c */
        $c = [
            'logistik' => $make('LOGISTIK', 'PT Logistik Nusantara', 'NET30', ['email' => 'keuangan@logistiknusantara.demo.test', 'credit_limit' => '50000000']),
            'retail' => $make('RETAIL', 'CV Retail Mandiri', 'NET14'),
            'tambang' => $make('TAMBANG', 'PT Tambang Karya', 'NET30'),
            'toko' => $make('TOKO', 'Toko Maju Jaya', 'COD'),
        ];

        $invoices = app(ArInvoiceService::class);
        $receipts = app(CustomerReceiptService::class);
        $notes = app(ArCreditNoteService::class);
        $draft = fn (Customer $customer, string $reference, string $description, string $amount, int $daysAgo) => $this->as($accountant, fn () => $invoices->create([
            'customer_id' => $customer->id, 'customer_reference' => $reference, 'document_date' => $this->date($daysAgo), 'posting_date' => $this->date($daysAgo),
            'description' => $description, 'branch_id' => $branchId, 'lines' => [['description' => $description, 'amount' => $amount]],
        ], $accountant));
        $submit = fn ($invoice) => $this->as($accountant, fn () => $invoices->submit($invoice, $accountant));
        $approve = fn ($invoice) => $this->as($manager, fn () => $invoices->approve($invoice, $manager));
        $post = fn ($invoice) => $this->as($manager, fn () => $invoices->post($invoice, $manager));
        $posted = fn (Customer $customer, string $reference, string $description, string $amount, int $daysAgo) => $post($approve($submit($draft($customer, $reference, $description, $amount, $daysAgo))));

        $old = $posted($c['logistik'], 'PO-LN-0412', 'Jasa angkut kontainer bulanan', '18500000', 58);
        $mid = $posted($c['retail'], 'PO-RM-118', 'Distribusi barang retail', '9600000', 26);
        $new = $posted($c['tambang'], 'PO-TK-077', 'Sewa armada dan pengemudi', '22000000', 11);
        $cod = $posted($c['toko'], 'PO-TMJ-31', 'Pengiriman paket ekspres', '2750000', 3);
        $submit($draft($c['logistik'], 'PO-LN-0455', 'Jasa angkut tambahan', '4200000', 2));
        $approve($submit($draft($c['retail'], 'PO-RM-131', 'Distribusi barang retail minggu ini', '3300000', 1)));
        $draft($c['tambang'], 'PO-TK-081', 'Sewa armada (draf)', '7500000', 0);

        // Partly settled, fully settled, a receipt waiting for approval.
        $collect = fn (Customer $customer, array $allocations, int $daysAgo, string $reference) => $this->as($accountant, fn () => $receipts->create([
            'customer_id' => $customer->id, 'cash_bank_account_id' => $bank->id, 'receipt_date' => $this->date($daysAgo), 'posting_date' => $this->date($daysAgo),
            'amount' => (string) array_sum($allocations), 'receipt_method' => 'TRANSFER', 'reference' => $reference, 'branch_id' => $branchId,
            'allocations' => array_map(fn ($id, $amount) => ['ar_invoice_id' => $id, 'amount' => (string) $amount], array_keys($allocations), $allocations),
        ], $accountant));
        $settle = function ($receipt) use ($receipts, $accountant, $manager) {
            $receipt = $this->as($accountant, fn () => $receipts->submit($receipt, $accountant));
            $receipt = $this->as($manager, fn () => $receipts->approve($receipt, $manager));

            return $this->as($manager, fn () => $receipts->post($receipt, $manager));
        };
        $settle($collect($c['logistik'], [$old->id => 10000000], 40, 'TRF-IN-0001'));
        $settle($collect($c['retail'], [$mid->id => 9600000], 15, 'TRF-IN-0002'));
        $waiting = $collect($c['tambang'], [$new->id => 8000000], 1, 'TRF-IN-0003');
        $this->as($accountant, fn () => $receipts->submit($waiting, $accountant));

        // A posted credit note for a partial return on the oldest invoice.
        $note = $this->as($accountant, fn () => $notes->create([
            'ar_invoice_id' => $old->id, 'document_date' => $this->date(20), 'posting_date' => $this->date(20), 'reason' => 'Retur sebagian layanan yang tidak terlaksana',
            'lines' => [['description' => 'Pengurangan jasa angkut', 'amount' => '1500000']],
        ], $accountant));
        $note = $this->as($accountant, fn () => $notes->submit($note, $accountant));
        $note = $this->as($manager, fn () => $notes->approve($note, $manager));
        $this->as($manager, fn () => $notes->post($note, $manager));
    }

    /** @param array<string,Vendor> $vendors */
    private function expenses(array $vendors, CashBankAccount $cash, User $accountant, User $manager, ?string $branchId): void
    {
        $expenses = app(ExpenseService::class);
        $category = fn (string $code) => ExpenseCategory::query()->where('code', $code)->value('id');
        $make = fn (array $body) => $this->as($accountant, fn () => $expenses->create($body + ['branch_id' => $branchId], $accountant));
        $post = function ($expense) use ($expenses, $accountant, $manager) {
            $expense = $this->as($accountant, fn () => $expenses->submit($expense, $accountant));
            $expense = $this->as($manager, fn () => $expenses->approve($expense, $manager));

            return $this->as($manager, fn () => $expenses->post($expense, $manager));
        };

        // A utility bill booked as a payable (it appears in the aging), a directly paid petty-cash expense, one waiting for approval, one draft.
        $post($make(['settlement' => 'PAYABLE', 'expense_category_id' => $category('UTILITIES'), 'vendor_id' => $vendors['listrik']->id, 'expense_date' => $this->date(6), 'posting_date' => $this->date(6),
            'description' => 'Air dan internet kantor', 'net_amount' => '1800000', 'tax_amount' => '198000', 'supporting_document' => 'KW-2210']));
        $post($make(['settlement' => 'DIRECT_PAID', 'expense_category_id' => $category('TRANSPORT'), 'cash_bank_account_id' => $cash->id, 'expense_date' => $this->date(3), 'posting_date' => $this->date(3),
            'description' => 'Parkir dan tol pengiriman', 'net_amount' => '185000', 'payee_name' => 'Operator parkir', 'supporting_document' => 'STRUK-0310']));
        $waiting = $make(['settlement' => 'DIRECT_PAID', 'expense_category_id' => $category('MEALS'), 'cash_bank_account_id' => $cash->id, 'expense_date' => $this->date(1), 'posting_date' => $this->date(1),
            'description' => 'Konsumsi rapat bulanan', 'net_amount' => '320000', 'payee_name' => 'Katering Ibu Sari']);
        $this->as($accountant, fn () => $expenses->submit($waiting, $accountant));
        $make(['settlement' => 'PAYABLE', 'expense_category_id' => $category('REPAIRS'), 'vendor_id' => $vendors['servis']->id, 'expense_date' => $this->date(0), 'posting_date' => $this->date(0),
            'description' => 'Perbaikan AC ruang server (draf)', 'net_amount' => '2750000', 'tax_amount' => '302500']);
    }

    private function cashAndReconciliation(CashBankAccount $bank, User $accountant, User $manager, ?string $branchId): void
    {
        $cash = app(CashTransactionService::class);
        $book = function (string $kind, string $counter, string $amount, int $daysAgo, string $purpose) use ($cash, $bank, $accountant, $manager, $branchId) {
            $transaction = $this->as($accountant, fn () => $cash->create($kind, [
                'cash_bank_account_id' => $bank->id, 'counter_account_id' => $this->accounts[$counter], 'amount' => $amount, 'transaction_date' => $this->date($daysAgo),
                'purpose' => $purpose, 'description' => $purpose, 'branch_id' => $branchId,
            ], $accountant));

            return $this->as($manager, fn () => $cash->post($transaction, $manager));
        };

        $receipt = $book(CashTransaction::RECEIPT, '4100', '15000000', 8, 'Setoran penjualan tunai');
        $fee = $book(CashTransaction::PAYMENT, '6900', '150000', 7, 'Biaya administrasi bank');
        $book(CashTransaction::PAYMENT, '6900', '480000', 2, 'Biaya notaris dan legalisasi');
        $this->as($accountant, fn () => $cash->create(CashTransaction::RECEIPT, [
            'cash_bank_account_id' => $bank->id, 'counter_account_id' => $this->accounts['4100'], 'amount' => '2250000', 'transaction_date' => $this->date(0),
            'purpose' => 'Setoran penjualan hari ini (draf)', 'description' => 'Setoran penjualan hari ini (draf)', 'branch_id' => $branchId,
        ], $accountant));

        // A bank statement in progress: three lines matched to the books, one deposit that the books do not know yet.
        $line = fn (string $journalEntryId) => (string) DB::table('journal_lines')->where('journal_entry_id', $journalEntryId)->where('account_id', $bank->account_id)->value('id');
        $transferLine = (string) DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('l.account_id', $bank->account_id)
            ->where('j.source_type', 'vendor_payment')->where('l.credit', '5000000')->value('l.id');
        $statements = app(BankStatementService::class);
        $statement = $this->as($accountant, fn () => $statements->create([
            'cash_bank_account_id' => $bank->id, 'reference' => 'BCA-'.substr($this->today, 0, 7), 'statement_date' => $this->today, 'closing_balance' => '0',
            'items' => [
                ['item_date' => $this->date(8), 'description' => 'Setoran tunai', 'amount' => '15000000'],
                ['item_date' => $this->date(7), 'description' => 'Biaya admin bulanan', 'amount' => '-150000'],
                ['item_date' => $this->date(30), 'description' => 'Transfer ke PT Sumber Makmur', 'amount' => '-5000000'],
                ['item_date' => $this->date(5), 'description' => 'Bunga tabungan', 'amount' => '75000'],
            ],
        ], $accountant));
        $items = $statement->items->sortBy('line_number')->values();
        foreach ([[0, $line($receipt->journal_entry_id)], [1, $line($fee->journal_entry_id)], [2, $transferLine]] as [$index, $journalLine]) {
            if ($journalLine !== '') {
                $this->as($accountant, fn () => $statements->match($items[$index], $journalLine, $accountant));
            }
        }
    }

    /** The date $daysAgo before today, never before the first open period and never after today. */
    private function date(int $daysAgo): string
    {
        return max($this->floor, date('Y-m-d', strtotime($this->today." -{$daysAgo} days")));
    }

    private function as(User $user, callable $work): mixed
    {
        $previous = $this->context->user();
        $this->context->setUser($user);
        try {
            return $work();
        } finally {
            $this->context->setUser($previous);
        }
    }
}
