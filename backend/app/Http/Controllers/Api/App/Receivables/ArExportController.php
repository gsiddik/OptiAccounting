<?php

namespace App\Http\Controllers\Api\App\Receivables;

use App\Domain\Accounting\Support\CsvExporter;
use App\Domain\Accounting\Support\ListExport;
use App\Domain\Accounting\Support\ListFilters;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Receivables\Services\ArCreditNoteService;
use App\Domain\Receivables\Services\ArInvoiceService;
use App\Domain\Receivables\Services\ArReconciliationService;
use App\Domain\Receivables\Services\ArSubledgerService;
use App\Domain\Receivables\Services\CustomerReceiptService;
use App\Domain\Receivables\Services\CustomerService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports of the OA3 lists and the AR reconciliation (permission `accounting.report.export` per module feature). Every export runs the
 * same service query as its list, so tenant, data scope and filters are identical to the screen; each download is audited with its filters and
 * row count and is capped (`EXPORT_TOO_LARGE`). The aging export lives with the aging report (ArReportController).
 */
class ArExportController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly CustomerService $customers,
        private readonly ArInvoiceService $invoices,
        private readonly CustomerReceiptService $receipts,
        private readonly ArCreditNoteService $creditNotes,
        private readonly ArReconciliationService $reconciliation,
        private readonly ArSubledgerService $subledger,
        private readonly AuditService $audit,
    ) {
        parent::__construct($context);
    }

    public function customers(Request $request): StreamedResponse
    {
        $filter = $request->validate(ListFilters::customers());
        $rows = ListExport::rows($this->customers->query($filter));
        $this->audited('customers', $filter, $rows->count());

        return CsvExporter::stream('pelanggan.csv',
            ['Kode', 'Nama', 'Nama legal', 'Status', 'Kontak', 'Email', 'Telepon', 'NPWP', 'PKP', 'Termin pembayaran', 'Mata uang', 'Batas kredit', 'Akun piutang', 'Akun pendapatan default'],
            $rows->map(fn ($c) => [$c->code, $c->name, $c->legal_name, $c->status, $c->contact_name, $c->email, $c->phone, $c->tax_id, $c->tax_registered ? 'Ya' : 'Tidak',
                $c->paymentTerm?->name, $c->default_currency, $c->credit_limit === null ? null : ListExport::number($c->credit_limit), $c->receivableAccount?->code, $c->defaultRevenueAccount?->code]));
    }

    public function invoices(Request $request): StreamedResponse
    {
        $filter = $this->listFilter($request, ListFilters::arInvoices(), ['mine', 'open', 'overdue']);
        $rows = ListExport::rows($this->invoices->query($filter));
        $this->audited('ar_invoices', $filter, $rows->count());

        return CsvExporter::stream('faktur-pelanggan.csv',
            ['No. dokumen', 'Pelanggan', 'Nama pelanggan', 'Referensi pelanggan', 'Tanggal dokumen', 'Tanggal posting', 'Jatuh tempo', 'Status', 'Status pelunasan', 'Mata uang',
                'Subtotal', 'Diskon', 'Pajak', 'Biaya lain', 'Total', 'Diterima', 'Nota kredit', 'Saldo', 'Cabang', 'Unit bisnis', 'Keterangan'],
            $rows->map(fn ($i) => [$i->document_number, $i->customer?->code, $i->customer?->name, $i->customer_reference, $this->date($i->document_date), $this->date($i->posting_date),
                $this->date($i->due_date), $i->status, $i->payment_status, $i->currency, ListExport::number($i->subtotal_amount), ListExport::number($i->discount_amount), ListExport::number($i->tax_amount),
                ListExport::number($i->other_charges_amount), ListExport::number($i->total_amount), ListExport::number($i->received_amount), ListExport::number($i->credited_amount), ListExport::number($i->outstanding_amount),
                $i->branch?->name, $i->businessUnit?->name, $i->description]));
    }

    public function receipts(Request $request): StreamedResponse
    {
        $filter = $this->listFilter($request, ListFilters::receipts(), ['mine']);
        $rows = ListExport::rows($this->receipts->query($filter));
        $this->audited('customer_receipts', $filter, $rows->count());

        return CsvExporter::stream('penerimaan-pelanggan.csv',
            ['No. dokumen', 'Pelanggan', 'Nama pelanggan', 'Tanggal terima', 'Tanggal posting', 'Status', 'Kas/Bank', 'Metode', 'Mata uang', 'Jumlah', 'Dialokasikan', 'Cabang', 'Referensi', 'Keterangan'],
            $rows->map(fn ($r) => [$r->document_number, $r->customer?->code, $r->customer?->name, $this->date($r->receipt_date), $this->date($r->posting_date), $r->status, $r->cashBankAccount?->code,
                $r->receipt_method, $r->currency, ListExport::number($r->amount), ListExport::number($r->allocated_amount), $r->branch?->name, $r->reference, $r->description]));
    }

    public function creditNotes(Request $request): StreamedResponse
    {
        $filter = $this->listFilter($request, ListFilters::creditNotes(), ['mine']);
        $rows = ListExport::rows($this->creditNotes->query($filter));
        $this->audited('ar_credit_notes', $filter, $rows->count());

        return CsvExporter::stream('nota-kredit.csv',
            ['No. dokumen', 'Pelanggan', 'Nama pelanggan', 'Faktur', 'Tanggal dokumen', 'Tanggal posting', 'Status', 'Mata uang', 'Subtotal', 'Pajak', 'Total', 'Cabang', 'Alasan', 'Referensi'],
            $rows->map(fn ($n) => [$n->document_number, $n->customer?->code, $n->customer?->name, $n->invoice?->document_number, $this->date($n->document_date), $this->date($n->posting_date), $n->status,
                $n->currency, ListExport::number($n->subtotal_amount), ListExport::number($n->tax_amount), ListExport::number($n->total_amount), $n->branch?->name, $n->reason, $n->reference]));
    }

    public function reconciliation(Request $request): StreamedResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'customer_id' => ['nullable', 'uuid']]);
        $report = $this->reconciliation->report($data['as_of'] ?? $this->subledger->today(), $data['customer_id'] ?? null);
        $this->audited('ar_reconciliation', $data, count($report['customers']));

        return CsvExporter::stream("rekonsiliasi-piutang_{$report['as_of']}.csv",
            ['Pelanggan', 'Nama pelanggan', 'Saldo buku besar', 'Saldo sub-ledger', 'Selisih', 'Status'],
            (function () use ($report) {
                foreach ($report['customers'] as $c) {
                    yield [$c['customer_code'], $c['customer_name'], ListExport::number($c['gl_balance']), ListExport::number($c['subledger_balance']), ListExport::number($c['difference']), $c['status']];
                }
                yield ['', 'Total (transaksional)', ListExport::number($report['gl_transactional_balance']), ListExport::number($report['subledger_balance']), ListExport::number($report['difference']), $report['status']];
                yield ['', 'Komponen saldo awal (di luar perbandingan)', ListExport::number($report['opening_balance_component']), '', '', ''];
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
    private function audited(string $report, array $filter, int $rows): void
    {
        $this->audit->record('receivables.report.exported', 'report', $report, null, ['filters' => array_filter($filter, fn ($v) => $v !== null && $v !== false), 'rows' => $rows]);
    }
}
