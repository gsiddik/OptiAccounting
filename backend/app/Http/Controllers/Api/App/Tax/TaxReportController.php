<?php

namespace App\Http\Controllers\Api\App\Tax;

use App\Domain\Accounting\Support\CsvExporter;
use App\Domain\Accounting\Support\ListExport;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Tax\Models\TaxCode;
use App\Domain\Tax\Models\TaxTransaction;
use App\Domain\Tax\Services\TaxReportService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The tax transaction list and the tax report (JSON and CSV). The export runs the same service call as the screen, so tenant, scope and filters are identical, and is audited. */
class TaxReportController extends AppController
{
    public function __construct(TenantContext $context, private readonly TaxReportService $report, private readonly AuditService $audit)
    {
        parent::__construct($context);
    }

    public function transactions(Request $request): JsonResponse
    {
        $filter = $this->filter($request) + $request->validate(['status' => ['nullable', Rule::in([TaxTransaction::DRAFT, TaxTransaction::POSTED, TaxTransaction::REVERSED])], 'per_page' => ['nullable', 'integer', 'between:1,200']]);

        return response()->json($this->report->transactions($filter)->paginate($filter['per_page'] ?? 50));
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->report->summary($this->filter($request)));
    }

    public function export(Request $request): StreamedResponse
    {
        $filter = $this->filter($request);
        $report = $this->report->summary($filter);
        $this->audit->record('tax.report.exported', 'report', 'tax_report', null, ['filters' => $report['filters'], 'basis' => $report['basis'], 'rows' => count($report['rows'])]);

        return CsvExporter::stream('tax-report.csv', ['Arah', 'Kode', 'Nama', 'Jenis', 'Tarif %', 'Dapat dikreditkan', 'Dasar', 'Pajak', 'Transaksi'],
            (function () use ($report) {
                foreach ($report['rows'] as $r) {
                    yield [$r['direction'] === 'OUTPUT' ? 'Keluaran' : 'Masukan', $r['tax_code'], $r['tax_name'], $r['tax_type'], CsvExporter::number($r['rate']), $r['is_recoverable'] ? 'Ya' : 'Tidak',
                        ListExport::number($r['base_amount']), ListExport::number($r['tax_amount']), $r['transactions']];
                }
                $t = $report['totals'];
                yield ['', 'Pajak keluaran', '', '', '', '', ListExport::number($t['output_base']), ListExport::number($t['output_tax']), ''];
                yield ['', 'Pajak masukan dapat dikreditkan', '', '', '', '', ListExport::number($t['input_base']), ListExport::number($t['input_tax_recoverable']), ''];
                yield ['', 'Pajak masukan tidak dapat dikreditkan (biaya)', '', '', '', '', '', ListExport::number($t['input_tax_non_recoverable']), ''];
                yield ['', 'Pajak kurang (lebih) bayar', '', '', '', '', '', ListExport::number($t['net_payable']), ''];
            })());
    }

    /** @return array<string,mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'], 'basis' => ['nullable', Rule::in(TaxReportService::BASES)],
            'tax_code_id' => ['nullable', 'uuid'], 'tax_type' => ['nullable', Rule::in(TaxCode::TYPES)], 'direction' => ['nullable', Rule::in(['INPUT', 'OUTPUT'])],
            'source_type' => ['nullable', 'string', 'max:40'], 'counterparty_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'],
        ]);
    }
}
