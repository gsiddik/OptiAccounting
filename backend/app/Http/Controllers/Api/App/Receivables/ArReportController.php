<?php

namespace App\Http\Controllers\Api\App\Receivables;

use App\Domain\Accounting\Support\CsvExporter;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Receivables\Services\ArAgingService;
use App\Domain\Receivables\Services\ArReconciliationService;
use App\Domain\Receivables\Services\ArSubledgerService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** AR aging and the AR-to-GL reconciliation. Both read the subledger through the user's data scope; the export is audited. */
class ArReportController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly ArAgingService $aging,
        private readonly ArReconciliationService $reconciliation,
        private readonly ArSubledgerService $subledger,
        private readonly AuditService $audit,
    ) {
        parent::__construct($context);
    }

    public function aging(Request $request): JsonResponse
    {
        [$asOf, $buckets, $filter] = $this->agingInput($request);

        return response()->json($this->aging->report($asOf, $buckets, $filter, $request->boolean('detail')));
    }

    public function exportAging(Request $request): StreamedResponse
    {
        [$asOf, $buckets, $filter] = $this->agingInput($request);
        $report = $this->aging->report($asOf, $buckets, $filter, true);
        $this->audit->record('receivables.report.exported', 'report', 'ar_aging', null, ['as_of' => $asOf, 'filters' => $filter, 'buckets' => $buckets, 'invoices' => count($report['invoices'])]);

        return CsvExporter::stream("umur-piutang_{$asOf}.csv",
            ['Pelanggan', 'Nama pelanggan', 'No. dokumen', 'Referensi pelanggan', 'Tanggal posting', 'Jatuh tempo', 'Hari lewat', 'Kelompok', 'Nilai faktur', 'Diterima', 'Nota kredit', 'Saldo', 'Mata uang', 'Kurs', 'Saldo fungsional'],
            (function () use ($report) {
                foreach ($report['invoices'] as $i) {
                    yield [$i['customer_code'], $i['customer_name'], $i['document_number'], $i['customer_reference'],
                        $i['posting_date'], $i['due_date'], $i['days_overdue'], $i['bucket'], CsvExporter::number($i['total_amount']), CsvExporter::number($i['received_amount']), CsvExporter::number($i['credited_amount']), CsvExporter::number($i['outstanding_amount']),
                        $i['currency'], CsvExporter::number($i['exchange_rate']), CsvExporter::number($i['outstanding_functional'])];
                }
                yield ['', 'Total (mata uang fungsional)', '', '', '', '', '', '', '', '', '', '', '', '', CsvExporter::number($report['totals']['total'])];
            })());
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'customer_id' => ['nullable', 'uuid']]);

        return response()->json($this->reconciliation->report($data['as_of'] ?? $this->subledger->today(), $data['customer_id'] ?? null));
    }

    /** @return array{0:string,1:?list<int>,2:array<string,?string>} */
    private function agingInput(Request $request): array
    {
        $data = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'], 'buckets' => ['nullable', 'string', 'max:60', 'regex:/^\d{1,4}(,\d{1,4}){0,7}$/'],
            'customer_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'detail' => ['nullable', 'boolean'],
        ]);

        return [
            $data['as_of'] ?? $this->subledger->today(),
            isset($data['buckets']) ? array_map('intval', explode(',', $data['buckets'])) : null,
            array_intersect_key($data, array_flip(['customer_id', 'branch_id', 'business_unit_id', 'cost_center_id'])),
        ];
    }
}
