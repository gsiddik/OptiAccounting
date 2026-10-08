<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\BalanceCalculator;
use App\Domain\Accounting\Services\GeneralLedgerService;
use App\Domain\Accounting\Services\TrialBalanceService;
use App\Domain\Accounting\Support\CsvExporter;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** General ledger, trial balance and their CSV exports. Same service, same scope and same filters on screen and in the file. */
class LedgerReportController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly GeneralLedgerService $ledger,
        private readonly TrialBalanceService $trialBalance,
        private readonly AuditService $audit,
    ) {
        parent::__construct($context);
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $filters = $this->ledgerFilters($request);
        $paging = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,200']]);

        return response()->json($this->ledger->report($filters, (int) ($paging['page'] ?? 1), (int) ($paging['per_page'] ?? 50)));
    }

    public function trialBalance(Request $request): JsonResponse
    {
        return response()->json($this->trialBalance->report($this->trialBalanceFilters($request)));
    }

    public function exportGeneralLedger(Request $request): StreamedResponse
    {
        $filters = $this->ledgerFilters($request);
        $rows = $this->ledger->exportRows($filters);
        $range = app(BalanceCalculator::class)->range($filters);
        $this->audit->record('accounting.report.exported', 'report', 'general_ledger', null, ['filters' => $filters, 'range' => $range]);

        return CsvExporter::stream("buku-besar_{$range['from']}_{$range['to']}.csv",
            ['Tanggal', 'No. jurnal', 'Tipe', 'Kode akun', 'Nama akun', 'Keterangan jurnal', 'Keterangan baris', 'Referensi', 'Debit', 'Kredit', 'Saldo berjalan'],
            (function () use ($rows) {
                foreach ($rows as ['row' => $r, 'running' => $running]) {
                    yield [$r->posting_date, $r->journal_number, $r->journal_type, $r->code, $r->name, $r->journal_description, $r->description, $r->reference ?? $r->journal_reference,
                        CsvExporter::number(Money::str($r->debit)), CsvExporter::number(Money::str($r->credit)), CsvExporter::number($running)];
                }
            })());
    }

    public function exportTrialBalance(Request $request): StreamedResponse
    {
        $filters = $this->trialBalanceFilters($request);
        $report = $this->trialBalance->report($filters);
        $this->audit->record('accounting.report.exported', 'report', 'trial_balance', null, ['filters' => $filters, 'range' => $report['range']]);

        $number = fn (string $v) => CsvExporter::number($v);

        return CsvExporter::stream("neraca-saldo_{$report['range']['from']}_{$report['range']['to']}.csv",
            ['Kode akun', 'Nama akun', 'Tipe', 'Saldo awal debit', 'Saldo awal kredit', 'Mutasi debit', 'Mutasi kredit', 'Saldo akhir debit', 'Saldo akhir kredit'],
            (function () use ($report, $number) {
                foreach ($report['data'] as $r) {
                    yield [$r['code'], $r['name'], $r['account_type'], $number($r['opening_debit']), $number($r['opening_credit']), $number($r['debit']), $number($r['credit']), $number($r['ending_debit']), $number($r['ending_credit'])];
                }
                $t = $report['totals'];
                yield ['', 'Total', '', $number($t['opening_debit']), $number($t['opening_credit']), $number($t['debit']), $number($t['credit']), $number($t['ending_debit']), $number($t['ending_credit'])];
            })());
    }

    public function exportAccounts(): StreamedResponse
    {
        $accounts = Account::query()->orderBy('code')->get();
        $codes = $accounts->pluck('code', 'id');
        $this->audit->record('accounting.report.exported', 'report', 'chart_of_accounts', null, ['rows' => $accounts->count()]);

        return CsvExporter::stream('bagan-akun.csv', ['Kode akun', 'Nama akun', 'Tipe', 'Saldo normal', 'Induk', 'Akun posting', 'Akun kontrol', 'Status'],
            $accounts->map(fn (Account $a) => [$a->code, $a->name, $a->account_type, $a->normal_balance, $codes[$a->parent_id] ?? '', $a->is_postable ? 'ya' : 'tidak', $a->is_control ? 'ya' : 'tidak', $a->status]));
    }

    private function ledgerFilters(Request $request): array
    {
        return $request->validate([
            'period_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function trialBalanceFilters(Request $request): array
    {
        $filters = $request->validate([
            'period_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'hierarchy' => ['nullable', 'boolean'], 'include_zero' => ['nullable', 'boolean'],
        ]);
        $filters['hierarchy'] = $request->boolean('hierarchy');
        $filters['include_zero'] = $request->boolean('include_zero');

        return $filters;
    }
}
