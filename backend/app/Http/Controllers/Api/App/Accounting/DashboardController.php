<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Identity\Models\Tenant;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/** Minimal Accounting Core home: where the books stand today. Readiness comes from the readiness endpoint. */
class DashboardController extends AppController
{
    public function __construct(TenantContext $context, private readonly AccountingScope $scope)
    {
        parent::__construct($context);
    }

    public function show(): JsonResponse
    {
        $today = Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
        $period = AccountingPeriod::query()->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first();
        $year = $period ? FiscalYear::query()->find($period->fiscal_year_id) : null;

        $counts = $this->scope->restrictJournals(JournalEntry::query())
            ->whereIn('status', [JournalEntry::DRAFT, JournalEntry::SUBMITTED, JournalEntry::APPROVED])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $recent = $this->scope->restrictJournals(JournalEntry::query())->where('status', JournalEntry::POSTED)
            ->orderByDesc('posted_at')->limit(5)->get(['id', 'journal_number', 'journal_type', 'posting_date', 'description', 'total_debit', 'currency']);

        return response()->json([
            'business_date' => $today,
            'fiscal_year' => $year?->only(['id', 'code', 'name', 'start_date', 'end_date', 'status']),
            'period' => $period?->only(['id', 'code', 'name', 'start_date', 'end_date', 'status']),
            'journals' => [
                'draft' => (int) ($counts[JournalEntry::DRAFT] ?? 0),
                'pending_approval' => (int) ($counts[JournalEntry::SUBMITTED] ?? 0),
                'awaiting_posting' => (int) ($counts[JournalEntry::APPROVED] ?? 0),
            ],
            'recent_posted' => $recent,
        ]);
    }
}
