<?php

namespace App\Domain\Tax\Services;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\Money;
use App\Domain\Shared\DomainException;
use App\Domain\Tax\Models\TaxTransaction;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The tax report (OA4 §37). It reads only the frozen tax transactions of POSTED documents, never a recomputation, so a rate change today
 * cannot move last quarter's figures. A document that was reversed counts in the period it was posted and is taken out again in the period
 * of its reversal (basis `posting_date`), or never counts at all (basis `tax_date`, where the reversal belongs to the same tax point).
 *
 * Output tax is what the tax authority is owed; input tax is split into the recoverable part (a receivable from the authority) and the
 * non-recoverable part (a cost already inside the expense). `net_payable` = output - recoverable input; negative is a refund position.
 * Data scope narrows the report like every other list, and `complete` says whether it saw the whole tenant.
 * The tax adjustments of AR credit notes are entered by hand until credit notes take tax codes; they are shown beside the report, informational,
 * and are not part of `net_payable`.
 */
class TaxReportService
{
    public const BASES = ['posting_date', 'tax_date'];

    public function __construct(private readonly DocumentScope $documents, private readonly TenantContext $context) {}

    // ------------------------------------------------------------------------------------------------ transactions

    /** The tax transaction list: POSTED unless a status is named, same filters as the summary. */
    public function transactions(array $filter): Builder
    {
        $this->assertBasis($filter);
        $query = TaxTransaction::query();
        $this->documents->restrict($query, 'tax_transactions');
        $basis = $filter['basis'] ?? 'posting_date';

        return $query
            ->where('status', $filter['status'] ?? TaxTransaction::POSTED)
            ->when($filter['date_from'] ?? null, fn ($q, $v) => $q->whereDate($basis, '>=', $v))
            ->when($filter['date_to'] ?? null, fn ($q, $v) => $q->whereDate($basis, '<=', $v))
            ->when($filter['tax_code_id'] ?? null, fn ($q, $v) => $q->where('tax_code_id', $v))
            ->when($filter['tax_type'] ?? null, fn ($q, $v) => $q->where('tax_type', $v))
            ->when($filter['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($filter['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', $v))
            ->when($filter['counterparty_id'] ?? null, fn ($q, $v) => $q->where('counterparty_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->orderByDesc($basis)->orderBy('document_number')->orderBy('line_number');
    }

    // ------------------------------------------------------------------------------------------------ summary

    /** @return array<string,mixed> */
    public function summary(array $filter): array
    {
        $this->assertBasis($filter);
        $basis = $filter['basis'] ?? 'posting_date';
        $tenantId = $this->context->tenantId();

        $base = fn () => $this->filtered(DB::table('tax_transactions')->where('tax_transactions.tenant_id', $tenantId), $filter);
        $columns = ['tax_transactions.tax_code_id', 'tax_transactions.tax_code', 'tax_transactions.tax_name', 'tax_transactions.tax_type', 'tax_transactions.direction',
            'tax_transactions.treatment', 'tax_transactions.is_recoverable', 'tax_transactions.rate'];

        $dateOf = fn (string $column) => fn (QueryBuilder $q) => $q->when($filter['date_from'] ?? null, fn ($w, $v) => $w->whereDate($column, '>=', $v))
            ->when($filter['date_to'] ?? null, fn ($w, $v) => $w->whereDate($column, '<=', $v));

        // the original postings, then the reversals as negative movements
        $original = $dateOf("tax_transactions.{$basis}")($base()->whereIn('tax_transactions.status', [TaxTransaction::POSTED, TaxTransaction::REVERSED]))
            ->select($columns)->selectRaw('1 as sign, tax_transactions.base_amount, tax_transactions.tax_amount');
        $reversal = $base()->where('tax_transactions.status', TaxTransaction::REVERSED)->join('journal_entries as rj', 'rj.id', '=', 'tax_transactions.reversal_journal_id');
        $reversal = $dateOf($basis === 'posting_date' ? 'rj.posting_date' : 'tax_transactions.tax_date')($reversal)
            ->select($columns)->selectRaw('-1 as sign, tax_transactions.base_amount, tax_transactions.tax_amount');

        $rows = DB::query()->fromSub($original->unionAll($reversal), 'e')
            ->select('tax_code_id', 'tax_code', 'tax_name', 'tax_type', 'direction', 'treatment', 'is_recoverable', 'rate')
            ->selectRaw('sum(sign * base_amount) as base_amount, sum(sign * tax_amount) as tax_amount, sum(sign) as transactions')
            ->groupBy('tax_code_id', 'tax_code', 'tax_name', 'tax_type', 'direction', 'treatment', 'is_recoverable', 'rate')
            ->orderBy('direction')->orderBy('tax_code')->orderBy('rate')->get();

        $out = $inRecoverable = $inCost = $outBase = $inBase = BigDecimal::zero();
        $list = [];
        foreach ($rows as $row) {
            $tax = BigDecimal::of($row->tax_amount);
            $baseAmount = BigDecimal::of($row->base_amount);
            if ($row->direction === TaxDocumentService::OUTPUT) {
                $out = $out->plus($tax);
                $outBase = $outBase->plus($baseAmount);
            } else {
                $inBase = $inBase->plus($baseAmount);
                $row->is_recoverable ? $inRecoverable = $inRecoverable->plus($tax) : $inCost = $inCost->plus($tax);
            }
            $list[] = [
                'tax_code_id' => $row->tax_code_id, 'tax_code' => $row->tax_code, 'tax_name' => $row->tax_name, 'tax_type' => $row->tax_type, 'direction' => $row->direction,
                'treatment' => $row->treatment, 'is_recoverable' => (bool) $row->is_recoverable, 'rate' => (string) $row->rate,
                'base_amount' => Money::str($baseAmount), 'tax_amount' => Money::str($tax), 'transactions' => (int) $row->transactions,
            ];
        }

        return [
            'basis' => $basis,
            'filters' => array_filter(['date_from' => $filter['date_from'] ?? null, 'date_to' => $filter['date_to'] ?? null, 'tax_code_id' => $filter['tax_code_id'] ?? null, 'tax_type' => $filter['tax_type'] ?? null,
                'direction' => $filter['direction'] ?? null, 'source_type' => $filter['source_type'] ?? null, 'counterparty_id' => $filter['counterparty_id'] ?? null, 'branch_id' => $filter['branch_id'] ?? null], fn ($v) => $v !== null),
            'rows' => $list,
            'totals' => [
                'output_base' => Money::str($outBase), 'output_tax' => Money::str($out), 'input_base' => Money::str($inBase),
                'input_tax_recoverable' => Money::str($inRecoverable), 'input_tax_non_recoverable' => Money::str($inCost),
                'net_payable' => Money::str($out->minus($inRecoverable)),
            ],
            'credit_note_tax' => $this->creditNoteTax($filter),
            'complete' => $this->documents->isTenantWide(),
        ];
    }

    /** Tax entered by hand on posted AR credit notes in the period: informational, outside net_payable. */
    private function creditNoteTax(array $filter): array
    {
        $query = DB::table('ar_credit_notes')->where('ar_credit_notes.tenant_id', $this->context->tenantId())->where('ar_credit_notes.status', 'POSTED');
        $this->documents->restrict($query, 'ar_credit_notes');
        $query->when($filter['date_from'] ?? null, fn ($q, $v) => $q->whereDate('ar_credit_notes.posting_date', '>=', $v))
            ->when($filter['date_to'] ?? null, fn ($q, $v) => $q->whereDate('ar_credit_notes.posting_date', '<=', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('ar_credit_notes.branch_id', $v));

        return ['informational' => true, 'ar_credit_note_tax' => Money::str((string) ($query->sum('ar_credit_notes.tax_amount') ?? '0')), 'ar_credit_notes' => (int) $query->count()];
    }

    private function filtered(QueryBuilder $query, array $filter): QueryBuilder
    {
        $this->documents->restrict($query, 'tax_transactions');

        return $query
            ->when($filter['tax_code_id'] ?? null, fn ($q, $v) => $q->where('tax_transactions.tax_code_id', $v))
            ->when($filter['tax_type'] ?? null, fn ($q, $v) => $q->where('tax_transactions.tax_type', $v))
            ->when($filter['direction'] ?? null, fn ($q, $v) => $q->where('tax_transactions.direction', $v))
            ->when($filter['source_type'] ?? null, fn ($q, $v) => $q->where('tax_transactions.source_type', $v))
            ->when($filter['counterparty_id'] ?? null, fn ($q, $v) => $q->where('tax_transactions.counterparty_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('tax_transactions.branch_id', $v));
    }

    private function assertBasis(array $filter): void
    {
        if (isset($filter['basis']) && ! in_array($filter['basis'], self::BASES, true)) {
            throw new DomainException('The basis must be posting_date or tax_date.', 'TAX_REPORT_BASIS_INVALID', 422, ['field' => 'basis']);
        }
    }
}
