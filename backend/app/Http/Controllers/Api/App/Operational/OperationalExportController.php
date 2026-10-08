<?php

namespace App\Http\Controllers\Api\App\Operational;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\CsvExporter;
use App\Domain\Accounting\Support\ListExport;
use App\Domain\Accounting\Support\ListFilters;
use App\Domain\Audit\Services\AuditService;
use App\Domain\CashBank\Models\BankStatement;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\CashBank\Services\BankStatementService;
use App\Domain\CashBank\Services\CashBankLedgerService;
use App\Domain\CashBank\Services\CashBankReconciliationService;
use App\Domain\CashBank\Services\CashTransactionService;
use App\Domain\Expense\Services\ExpenseService;
use App\Domain\Payables\Services\ApInvoiceService;
use App\Domain\Payables\Services\ApReconciliationService;
use App\Domain\Payables\Services\ApSubledgerService;
use App\Domain\Payables\Services\VendorPaymentService;
use App\Domain\Payables\Services\VendorService;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports of the OA2 lists and reconciliations (permission `accounting.report.export` per module feature). Every export runs the
 * same service query as its list, so tenant, data scope and filters are identical to the screen; each download is audited with its
 * filters and row count, and is capped (`EXPORT_TOO_LARGE`). Only plain business data leaves: no bank account numbers, no attachments.
 */
class OperationalExportController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly VendorService $vendors,
        private readonly ApInvoiceService $invoices,
        private readonly VendorPaymentService $payments,
        private readonly ExpenseService $expenses,
        private readonly CashTransactionService $transactions,
        private readonly CashBankLedgerService $ledger,
        private readonly CashBankReconciliationService $cashReconciliation,
        private readonly ApReconciliationService $apReconciliation,
        private readonly ApSubledgerService $subledger,
        private readonly BankStatementService $statements,
        private readonly DocumentScope $scope,
        private readonly AuditService $audit,
    ) {
        parent::__construct($context);
    }

    public function vendors(Request $request): StreamedResponse
    {
        $filter = $request->validate(ListFilters::vendors());
        $rows = ListExport::rows($this->vendors->query($filter));
        $this->audited('payables.report.exported', 'vendors', $filter, $rows->count());

        return CsvExporter::stream('vendor.csv',
            ['Kode', 'Nama', 'Nama legal', 'Status', 'Kontak', 'Email', 'Telepon', 'NPWP', 'PKP', 'Termin pembayaran', 'Mata uang', 'Akun utang', 'Akun beban default'],
            $rows->map(fn ($v) => [$v->code, $v->name, $v->legal_name, $v->status, $v->contact_name, $v->email, $v->phone, $v->tax_id, $v->tax_registered ? 'Ya' : 'Tidak',
                $v->paymentTerm?->name, $v->default_currency, $v->payableAccount?->code, $v->defaultExpenseAccount?->code]));
    }

    public function invoices(Request $request): StreamedResponse
    {
        $filter = $this->listFilter($request, ListFilters::invoices(), ['mine', 'open', 'overdue']);
        $rows = ListExport::rows($this->invoices->query($filter));
        $this->audited('payables.report.exported', 'ap_invoices', $filter, $rows->count());

        return CsvExporter::stream('faktur-vendor.csv',
            ['No. dokumen', 'Asal', 'Vendor', 'Nama vendor', 'No. faktur vendor', 'Tanggal dokumen', 'Tanggal posting', 'Jatuh tempo', 'Status', 'Status bayar', 'Mata uang',
                'Subtotal', 'Diskon', 'Pajak', 'Biaya lain', 'Total', 'Dibayar', 'Saldo', 'Cabang', 'Unit bisnis', 'Keterangan'],
            $rows->map(fn ($i) => [$i->document_number, $i->origin, $i->vendor?->code, $i->vendor?->name, $i->vendor_invoice_number, $this->date($i->document_date), $this->date($i->posting_date),
                $this->date($i->due_date), $i->status, $i->payment_status, $i->currency, ListExport::number($i->subtotal_amount), ListExport::number($i->discount_amount), ListExport::number($i->tax_amount),
                ListExport::number($i->other_charges_amount), ListExport::number($i->total_amount), ListExport::number($i->paid_amount), ListExport::number($i->outstanding_amount),
                $i->branch?->name, $i->businessUnit?->name, $i->description]));
    }

    public function payments(Request $request): StreamedResponse
    {
        $filter = $this->listFilter($request, ListFilters::payments(), ['mine']);
        $rows = ListExport::rows($this->payments->query($filter));
        $this->audited('payables.report.exported', 'vendor_payments', $filter, $rows->count());

        return CsvExporter::stream('pembayaran-vendor.csv',
            ['No. dokumen', 'Vendor', 'Nama vendor', 'Tanggal bayar', 'Tanggal posting', 'Status', 'Kas/Bank', 'Metode', 'Mata uang', 'Jumlah', 'Dialokasikan', 'Cabang', 'Referensi', 'Keterangan'],
            $rows->map(fn ($p) => [$p->document_number, $p->vendor?->code, $p->vendor?->name, $this->date($p->payment_date), $this->date($p->posting_date), $p->status, $p->cashBankAccount?->code,
                $p->payment_method, $p->currency, ListExport::number($p->amount), ListExport::number($p->allocated_amount), $p->branch?->name, $p->reference, $p->description]));
    }

    public function expenses(Request $request): StreamedResponse
    {
        $filter = $this->listFilter($request, ListFilters::expenses(), ['mine']);
        $rows = ListExport::rows($this->expenses->query($filter));
        $this->audited('expense.report.exported', 'expenses', $filter, $rows->count());

        return CsvExporter::stream('beban.csv',
            ['No. dokumen', 'Penyelesaian', 'Kategori', 'Vendor / penerima', 'Tanggal beban', 'Tanggal posting', 'Jatuh tempo', 'Status', 'Kas/Bank', 'Mata uang', 'Neto', 'Pajak', 'Total', 'Cabang', 'Referensi', 'Keterangan'],
            $rows->map(fn ($e) => [$e->document_number, $e->settlement, $e->category?->name, $e->vendor?->name ?? $e->payee_name, $this->date($e->expense_date), $this->date($e->posting_date), $this->date($e->due_date),
                $e->status, $e->cashBankAccount?->code, $e->currency, ListExport::number($e->net_amount), ListExport::number($e->tax_amount), ListExport::number($e->total_amount), $e->branch?->name, $e->reference, $e->description]));
    }

    /** Cash payments or receipts, by the kind the route fixes. */
    public function cashTransactions(Request $request): StreamedResponse
    {
        $kind = $request->route()->defaults['kind'] ?? abort(404);
        $filter = $this->listFilter($request, ListFilters::cashTransactions(), ['mine']);
        $rows = ListExport::rows($this->transactions->query($kind, $filter));
        $this->audited('cash_bank.report.exported', $kind === 'PAYMENT' ? 'cash_payments' : 'cash_receipts', $filter, $rows->count());

        return CsvExporter::stream($kind === 'PAYMENT' ? 'pembayaran-kas.csv' : 'penerimaan-kas.csv',
            ['No. dokumen', 'Tanggal transaksi', 'Tanggal posting', 'Status', 'Kas/Bank', 'Akun lawan', 'Mata uang', 'Jumlah', 'Tujuan', 'Pihak', 'Cabang', 'Referensi', 'Keterangan'],
            $rows->map(fn ($t) => [$t->document_number, $this->date($t->transaction_date), $this->date($t->posting_date), $t->status, $t->cashBankAccount?->code, $t->counterAccount?->code,
                $t->currency, ListExport::number($t->amount), $t->purpose, $t->counterparty_name, $t->branch?->name, $t->reference, $t->description]));
    }

    /** The book side of one cash/bank account: posted ledger lines with their running balance and match state. */
    public function accountMovements(Request $request, CashBankAccount $cashBankAccount): StreamedResponse
    {
        $this->scope->authorize($cashBankAccount);
        $filter = $request->validate(ListFilters::accountMovements());
        $filter['matched'] = $request->has('matched') ? $request->boolean('matched') : null;
        $page = $this->ledger->lines($cashBankAccount, $filter, ListExport::max() + 1, 1);
        if ($page->total() > ListExport::max()) {
            throw new DomainException('The export has more than '.ListExport::max().' rows; narrow the filters.', 'EXPORT_TOO_LARGE', 422, ['rows' => $page->total(), 'max_rows' => ListExport::max()]);
        }
        $this->audited('cash_bank.report.exported', 'cash_bank_movements', ['cash_bank_account_id' => $cashBankAccount->id] + $filter, $page->total());

        return CsvExporter::stream("mutasi-{$cashBankAccount->code}.csv",
            ['Tanggal posting', 'No. jurnal', 'No. dokumen', 'Arah', 'Jumlah', 'Saldo berjalan', 'Cocok dengan mutasi', 'Referensi', 'Keterangan'],
            collect($page->items())->map(fn ($r) => [$r->posting_date, $r->journal_number, $r->document_number, $r->direction, ListExport::number($r->amount), ListExport::number($r->running_balance),
                $r->matched_statement, $r->reference, $r->description]));
    }

    public function apReconciliation(Request $request): StreamedResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'vendor_id' => ['nullable', 'uuid']]);
        $report = $this->apReconciliation->report($data['as_of'] ?? $this->subledger->today(), $data['vendor_id'] ?? null);
        $this->audited('payables.report.exported', 'ap_reconciliation', $data, count($report['vendors']));

        return CsvExporter::stream("rekonsiliasi-utang_{$report['as_of']}.csv",
            ['Vendor', 'Nama vendor', 'Saldo buku besar', 'Saldo sub-ledger', 'Selisih', 'Status'],
            (function () use ($report) {
                foreach ($report['vendors'] as $v) {
                    yield [$v['vendor_code'], $v['vendor_name'], ListExport::number($v['gl_balance']), ListExport::number($v['subledger_balance']), ListExport::number($v['difference']), $v['status']];
                }
                yield ['', 'Total (transaksional)', ListExport::number($report['gl_transactional_balance']), ListExport::number($report['subledger_balance']), ListExport::number($report['difference']), $report['status']];
                yield ['', 'Komponen saldo awal (di luar perbandingan)', ListExport::number($report['opening_balance_component']), '', '', ''];
            })());
    }

    public function cashBankReconciliation(Request $request): StreamedResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'cash_bank_account_id' => ['nullable', 'uuid']]);
        $report = $this->cashReconciliation->report($data['as_of'] ?? $this->subledger->today(), $data['cash_bank_account_id'] ?? null);
        $this->audited('cash_bank.report.exported', 'cash_bank_reconciliation', $data, count($report['accounts']));

        return CsvExporter::stream("rekonsiliasi-kas-bank_{$report['as_of']}.csv",
            ['Kode', 'Nama', 'Jenis', 'Akun buku besar', 'Saldo buku', 'Mutasi dokumen', 'Mutasi buku besar dari dokumen', 'Selisih dokumen', 'Status dokumen', 'Aktivitas lain',
                'Rekening koran', 'Saldo rekening koran', 'Buku - rekening koran', 'Status rekonsiliasi'],
            (function () use ($report) {
                foreach ($report['accounts'] as $a) {
                    $s = $a['statement'];
                    yield [$a['code'], $a['name'], $a['kind'], $a['gl_account']['code'], ListExport::number($a['book_balance']), ListExport::number($a['documents']['net']),
                        ListExport::number($a['documents']['ledger_net']), ListExport::number($a['documents']['difference']), $a['documents']['status'], ListExport::number($a['other_activity']),
                        $s['reference'] ?? '', $s ? ListExport::number($s['statement_balance']) : '', $s ? ListExport::number($s['book_minus_statement']) : '', $s['reconciliation'] ?? ''];
                }
            })());
    }

    /** One bank statement with its items and where each is matched. */
    public function statement(BankStatement $statement): StreamedResponse
    {
        $this->scope->authorize($statement);
        $loaded = $this->statements->load($statement);
        $items = DB::table('bank_statement_items as i')
            ->leftJoin('journal_lines as l', 'l.id', '=', 'i.matched_journal_line_id')
            ->leftJoin('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('i.tenant_id', $this->context->tenantId())->where('i.bank_statement_id', $statement->id)->orderBy('i.line_number')
            ->get(['i.line_number', 'i.item_date', 'i.description', 'i.reference', 'i.amount', 'i.status', 'i.notes', 'j.journal_number', 'j.posting_date']);
        $this->audited('cash_bank.report.exported', 'bank_statement', ['bank_statement_id' => $statement->id], $items->count());
        $summary = $loaded->summary;

        return CsvExporter::stream("rekening-koran_{$statement->reference}.csv",
            ['No', 'Tanggal', 'Keterangan', 'Referensi', 'Jumlah', 'Status', 'No. jurnal', 'Tanggal posting jurnal', 'Catatan'],
            (function () use ($loaded, $items, $summary) {
                yield ['', '', 'Rekening koran '.$loaded->reference.' ('.$loaded->cashBankAccount?->code.') per '.$loaded->statement_date->toDateString(), '', ListExport::number($loaded->closing_balance), $loaded->status, '', '', ''];
                foreach ($items as $i) {
                    yield [$i->line_number, $i->item_date, $i->description, $i->reference, ListExport::number($i->amount), $i->status, $i->journal_number, $i->posting_date, $i->notes];
                }
                yield ['', '', 'Selisih tidak terjelaskan', '', ListExport::number($summary['unexplained_difference']), $summary['status'], '', '', ''];
            })());
    }

    /**
     * @param  array<string,mixed>  $rules
     * @param  list<string>  $booleans
     * @return array<string,mixed>
     */
    private function listFilter(Request $request, array $rules, array $booleans): array
    {
        $filter = $request->validate($rules);
        foreach ($booleans as $key) {
            $filter[$key] = $request->boolean($key);
        }

        return $filter;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($value === null ? null : (string) $value);
    }

    /** @param  array<string,mixed>  $filter */
    private function audited(string $action, string $report, array $filter, int $rows): void
    {
        $this->audit->record($action, 'report', $report, null, ['filters' => array_filter($filter, fn ($v) => $v !== null && $v !== false), 'rows' => $rows]);
    }
}
