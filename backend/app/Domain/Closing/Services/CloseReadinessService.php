<?php

namespace App\Domain\Closing\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Accounting\Services\BalanceCalculator;
use App\Domain\Accounting\Support\Money;
use App\Domain\Entitlement\Services\ModuleAvailability;
use App\Domain\Reconciliation\Services\ReconciliationEngine;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * THE place that answers "may this period be closed?" (OA5 design doc 6). Controllers, the closing service and the overview all call
 * evaluate(); no close check exists anywhere else.
 *
 * Every check is computed live from POSTED data with SQL aggregation (nothing is read back from a stored result), is module-aware (a check
 * of a module the tenant is not entitled to is SKIPPED, never required) and carries the severity the closing policy gives it. READY means no
 * BLOCK check failed. A reconciliation that is INCOMPLETE (missing mapping, narrower data scope, unavailable side) FAILS a BLOCK check:
 * the close is never allowed on missing evidence. Evaluating needs a tenant-wide data scope, because a readiness computed over a narrower
 * scope would be a lie.
 *
 * Callers that decide a close (hard close, reopen, year end) evaluate INSIDE their transaction after taking the ledger gate and the period
 * lock, so the answer cannot be overtaken by a posting.
 */
class CloseReadinessService
{
    /** Checks that read a reconciliation: check code => reconciliation type. */
    private const RECONCILIATIONS = [
        'AP_RECONCILIATION' => ReconciliationResult::AP_GL, 'AR_RECONCILIATION' => ReconciliationResult::AR_GL,
        'CASH_BANK_RECONCILIATION' => ReconciliationResult::CASH_BANK_GL, 'FIXED_ASSET_RECONCILIATION' => ReconciliationResult::FIXED_ASSET_GL,
        'TAX_RECONCILIATION' => ReconciliationResult::TAX_GL, 'FX_RECONCILIATION' => ReconciliationResult::FX_GL,
    ];

    /** Source documents that wait for a posting: module => [[table, label, statuses that mean "not posted yet"]]. A cash transaction has no approval step, so a draft is what waits. */
    private const DOCUMENTS = [
        'ACCOUNTING_AP' => [['ap_invoices', 'AP_INVOICE', ['SUBMITTED', 'APPROVED']], ['vendor_payments', 'VENDOR_PAYMENT', ['SUBMITTED', 'APPROVED']]],
        'ACCOUNTING_AR' => [['ar_invoices', 'AR_INVOICE', ['SUBMITTED', 'APPROVED']], ['customer_receipts', 'CUSTOMER_RECEIPT', ['SUBMITTED', 'APPROVED']], ['ar_credit_notes', 'AR_CREDIT_NOTE', ['SUBMITTED', 'APPROVED']]],
        'ACCOUNTING_EXPENSE' => [['expenses', 'EXPENSE', ['SUBMITTED', 'APPROVED']]],
        'ACCOUNTING_CASH_BANK' => [['cash_transactions', 'CASH_TRANSACTION', ['DRAFT']]],
    ];

    private const SAMPLE = 10;

    public function __construct(
        private readonly ClosingPolicyService $policy,
        private readonly ModuleAvailability $modules,
        private readonly ReconciliationEngine $reconciliations,
        private readonly BalanceCalculator $balances,
        private readonly AccountingScope $scope,
        private readonly ReportMappingCoverage $mapping,
        private readonly TenantContext $context,
    ) {}

    /** Every closing action and every readiness figure needs the whole ledger in sight. */
    public function assertFullScope(): void
    {
        if (! $this->scope->isTenantWide()) {
            throw new DomainException('Closing needs a tenant-wide data scope: a narrower scope cannot see the whole ledger.', 'CLOSING_REQUIRES_FULL_SCOPE', 403);
        }
    }

    /** @param string $target SOFT | HARD: a soft close does not gate on the manual checklist, a hard close does */
    public function evaluate(AccountingPeriod $period, string $target = ReadinessResult::HARD): ReadinessResult
    {
        $this->assertFullScope();
        $start = $period->start_date->toDateString();
        $end = $period->end_date->toDateString();
        $severities = $this->policy->severities();

        $checks = [];
        foreach (ClosingPolicyService::CHECKS as $code => [$title, , $module]) {
            $severity = $target === ReadinessResult::SOFT && $code === 'CHECKLIST' ? ClosingPolicyService::WARN : $severities[$code];
            $base = ['code' => $code, 'title' => $title, 'severity' => $severity];

            if ($module !== null && ! $this->modules->active($module)) {
                $checks[] = $base + $this->skipped('Modul tidak berlangganan; pemeriksaan tidak diperlukan.');
            } elseif ($severity === ClosingPolicyService::OFF) {
                $checks[] = $base + $this->skipped('Dinonaktifkan oleh kebijakan penutupan.');
            } else {
                $outcome = $this->run($code, $period, $start, $end);
                $checks[] = $base + match ($outcome['status']) {
                    'PASS' => ['result' => 'PASS', 'message' => $outcome['message'], 'details' => $outcome['details']],
                    'SKIPPED' => $this->skipped($outcome['message'], $outcome['details']),
                    default => ['result' => $severity === ClosingPolicyService::BLOCK ? 'FAIL' : 'WARN', 'message' => $outcome['message'], 'details' => $outcome['details']],
                };
            }
        }

        return new ReadinessResult($period->id, $period->code, $target, $checks, now()->toIso8601String());
    }

    /** @return array{status:string,message:string,details:array<string,mixed>} status PASS | FINDING | SKIPPED */
    private function run(string $code, AccountingPeriod $period, string $start, string $end): array
    {
        return match ($code) {
            'TRIAL_BALANCE' => $this->trialBalance($start, $end),
            'PENDING_JOURNALS' => $this->journals('PENDING_JOURNALS', ['SUBMITTED', 'APPROVED'], $start, $end, 'jurnal diajukan atau disetujui yang belum diposting'),
            'DRAFT_JOURNALS' => $this->journals('DRAFT_JOURNALS', ['DRAFT'], $start, $end, 'jurnal draf'),
            'PENDING_DOCUMENTS' => $this->pendingDocuments($start, $end),
            'DEPRECIATION_COMPLETE' => $this->depreciation($end),
            'PREVIOUS_PERIODS_CLOSED' => $this->previousPeriods($start),
            'REPORT_MAPPING' => $this->reportMapping($end),
            'CHECKLIST' => $this->checklist($period),
            default => $this->reconciliation($code, $end),
        };
    }

    // ------------------------------------------------------------------------------------------ the checks

    private function trialBalance(string $start, string $end): array
    {
        $row = $this->balances->base([])->where('je.posting_date', '<=', $end)
            ->selectRaw('coalesce(sum(jl.debit), 0) as debit, coalesce(sum(jl.credit), 0) as credit')
            ->selectRaw("coalesce(sum(case when je.posting_date >= ? and je.journal_type <> 'OPENING' then jl.debit else 0 end), 0) as period_debit", [$start])
            ->selectRaw("coalesce(sum(case when je.posting_date >= ? and je.journal_type <> 'OPENING' then jl.credit else 0 end), 0) as period_credit", [$start])
            ->first();
        $difference = Money::str(BigDecimal::of($row->debit)->minus($row->credit));
        $periodDifference = Money::str(BigDecimal::of($row->period_debit)->minus($row->period_credit));
        $details = [
            'as_of' => $end, 'total_debit' => Money::str($row->debit), 'total_credit' => Money::str($row->credit), 'difference' => $difference,
            'period_debit' => Money::str($row->period_debit), 'period_credit' => Money::str($row->period_credit), 'period_difference' => $periodDifference,
        ];
        $balanced = BigDecimal::of($difference)->isZero();

        return $balanced
            ? $this->pass("Total debit sama dengan total kredit sampai {$end}.", $details)
            : $this->finding("Neraca saldo tidak seimbang sampai {$end}: selisih {$difference}.", $details);
    }

    /** @param list<string> $statuses */
    private function journals(string $code, array $statuses, string $start, string $end, string $what): array
    {
        $query = fn () => DB::table('journal_entries')->where('tenant_id', $this->tenantId())->whereIn('status', $statuses)->whereBetween('posting_date', [$start, $end]);
        $byStatus = $query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
        $count = array_sum($byStatus);
        if ($count === 0) {
            return $this->pass("Tidak ada {$what} pada periode ini.", ['count' => 0]);
        }

        $sample = $query()->orderBy('posting_date')->orderBy('id')->limit(self::SAMPLE)->get(['id', 'journal_type', 'status', 'posting_date', 'description'])
            ->map(fn ($j) => ['id' => $j->id, 'journal_type' => $j->journal_type, 'status' => $j->status, 'posting_date' => $j->posting_date, 'description' => $j->description])->all();

        return $this->finding("{$count} {$what} pada periode ini.", ['count' => $count, 'by_status' => $byStatus, 'sample' => $sample]);
    }

    private function pendingDocuments(string $start, string $end): array
    {
        $counts = [];
        foreach (self::DOCUMENTS as $module => $tables) {
            if (! $this->modules->active($module)) {
                continue;
            }
            foreach ($tables as [$table, $label, $statuses]) {
                $n = DB::table($table)->where('tenant_id', $this->tenantId())->whereIn('status', $statuses)->whereBetween('posting_date', [$start, $end])->count();
                if ($n > 0) {
                    $counts[$label] = $n;
                }
            }
        }

        return $counts === []
            ? $this->pass('Tidak ada dokumen sumber yang menunggu posting pada periode ini.', ['count' => 0])
            : $this->finding(array_sum($counts).' dokumen sumber menunggu posting pada periode ini.', ['count' => array_sum($counts), 'by_document' => $counts]);
    }

    private function reconciliation(string $code, string $end): array
    {
        $result = $this->reconciliations->compute(self::RECONCILIATIONS[$code], $end);
        $details = $result->toArray();

        return match ($result->status) {
            ReconciliationResult::MATCHED => $this->pass("Cocok pada {$end}.", $details),
            ReconciliationResult::NOT_APPLICABLE => ['status' => 'SKIPPED', 'message' => 'Tidak berlaku bagi tenant ini.', 'details' => $details],
            ReconciliationResult::MISMATCH => $this->finding("Rekonsiliasi tidak cocok pada {$end}: selisih {$result->difference}.", $details),
            default => $this->finding("Rekonsiliasi belum lengkap pada {$end}; tidak boleh lolos tanpa bukti.", $details),
        };
    }

    private function depreciation(string $end): array
    {
        $due = fn () => DB::table('asset_depreciation_schedules as s')
            ->join('fixed_assets as a', fn ($join) => $join->on('a.id', '=', 's.fixed_asset_id')->on('a.tenant_id', '=', 's.tenant_id'))
            ->where('s.tenant_id', $this->tenantId())->where('a.status', 'ACTIVE')->whereIn('s.status', ['PLANNED', 'IN_RUN'])->where('s.period_end', '<=', $end);
        $total = $due()->selectRaw('count(*) as months, count(distinct s.fixed_asset_id) as assets, coalesce(sum(s.amount), 0) as amount, min(s.period_start) as earliest')->first();
        if ((int) $total->months === 0) {
            return $this->pass("Semua penyusutan sampai {$end} sudah diposting.", ['months' => 0]);
        }

        $sample = $due()->groupBy('a.id', 'a.asset_number', 'a.name')->orderBy('a.asset_number')->limit(self::SAMPLE)
            ->selectRaw('a.id, a.asset_number, a.name, count(*) as months, sum(s.amount) as amount')->get()
            ->map(fn ($r) => ['asset_id' => $r->id, 'asset_number' => $r->asset_number, 'name' => $r->name, 'months' => (int) $r->months, 'amount' => Money::str($r->amount)])->all();

        return $this->finding("{$total->months} bulan penyusutan pada {$total->assets} aset belum diposting sampai {$end}.", [
            'months' => (int) $total->months, 'assets' => (int) $total->assets, 'amount' => Money::str($total->amount), 'earliest_period_start' => $total->earliest, 'sample' => $sample,
        ]);
    }

    private function previousPeriods(string $start): array
    {
        $open = DB::table('accounting_periods')->where('tenant_id', $this->tenantId())->where('end_date', '<', $start)->whereIn('status', [AccountingPeriod::OPEN, AccountingPeriod::SOFT_CLOSED]);
        $count = (clone $open)->count();
        if ($count === 0) {
            return $this->pass('Semua periode sebelumnya sudah ditutup.', ['count' => 0]);
        }

        $sample = $open->orderBy('start_date')->limit(self::SAMPLE)->get(['id', 'code', 'status'])->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'status' => $p->status])->all();

        return $this->finding("{$count} periode sebelumnya belum ditutup.", ['count' => $count, 'sample' => $sample]);
    }

    private function reportMapping(string $end): array
    {
        $coverage = $this->mapping->unmappedWithBalance($end);
        if ($coverage === null) {
            return ['status' => 'SKIPPED', 'message' => 'Belum ada definisi laporan keuangan yang berlaku; pemetaan tidak diperiksa.', 'details' => []];
        }
        $count = count($coverage['unmapped']);

        return $count === 0
            ? $this->pass('Semua akun bersaldo terpetakan pada definisi laporan yang berlaku.', ['definitions' => $coverage['definitions'], 'unmapped_count' => 0])
            : $this->finding("{$count} akun bersaldo belum terpetakan pada definisi laporan yang berlaku.", [
                'definitions' => $coverage['definitions'], 'unmapped_count' => $count, 'sample' => array_slice($coverage['unmapped'], 0, self::SAMPLE),
            ]);
    }

    /** Required manual items that are still pending, whether or not the period's item row exists yet (a template with no item counts as pending). */
    private function checklist(AccountingPeriod $period): array
    {
        $pending = $this->pendingChecklist($period->id);
        if ($pending === []) {
            return $this->pass('Semua butir wajib daftar periksa penutupan sudah selesai atau dikecualikan.', ['pending_count' => 0]);
        }

        return $this->finding(count($pending).' butir wajib daftar periksa penutupan belum selesai.', ['pending_count' => count($pending), 'pending' => array_slice($pending, 0, self::SAMPLE)]);
    }

    /** @return list<array{code:string,title:string}> */
    public function pendingChecklist(string $periodId): array
    {
        $tenant = $this->tenantId();
        $items = DB::table('closing_checklist_items')->where('tenant_id', $tenant)->where('accounting_period_id', $periodId)->where('required', true)->where('status', 'PENDING')
            ->get(['code', 'title', 'sort_order']);
        $templates = DB::table('closing_checklist_templates as t')->where('t.tenant_id', $tenant)->where('t.status', 'ACTIVE')->where('t.required', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('closing_checklist_items as i')->whereColumn('i.tenant_id', 't.tenant_id')->whereColumn('i.template_id', 't.id')->where('i.accounting_period_id', $periodId))
            ->get(['t.code', 't.title', 't.sort_order']);

        return $items->merge($templates)->unique('code')->sortBy([['sort_order', 'asc'], ['code', 'asc']])
            ->map(fn ($r) => ['code' => $r->code, 'title' => $r->title])->values()->all();
    }

    // ------------------------------------------------------------------------------------------ outcome shapes

    private function pass(string $message, array $details = []): array
    {
        return ['status' => 'PASS', 'message' => $message, 'details' => $details];
    }

    private function finding(string $message, array $details = []): array
    {
        return ['status' => 'FINDING', 'message' => $message, 'details' => $details];
    }

    /** @return array{result:string,message:string,details:array<string,mixed>} */
    private function skipped(string $message, array $details = []): array
    {
        return ['result' => 'SKIPPED', 'message' => $message, 'details' => $details];
    }

    private function tenantId(): string
    {
        return $this->context->tenantId() ?? throw new \LogicException('Close readiness requires a tenant context.');
    }
}
