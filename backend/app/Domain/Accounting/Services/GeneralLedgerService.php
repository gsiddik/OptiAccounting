<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Support\Money;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;

/** The general ledger: posted journal lines per account with a running balance, derived live from POSTED lines only. */
class GeneralLedgerService
{
    public const EXPORT_LIMIT = 50000;

    public function __construct(private readonly BalanceCalculator $balances) {}

    /**
     * @param  array<string,mixed>  $filters  period_id|from|to, account_id, branch_id, business_unit_id, cost_center_id, q
     * @return array<string,mixed>
     */
    public function report(array $filters, int $page = 1, int $perPage = 50): array
    {
        $range = $this->balances->range($filters);
        $account = ! empty($filters['account_id']) ? Account::query()->findOrFail($filters['account_id']) : null;
        $totals = $this->balances->totals($range['from'], $range['to'], $filters);

        $lines = $this->linesQuery($range, $filters);
        $total = (clone $lines)->count();
        $rows = $lines->orderBy('a.code')->orderBy('je.posting_date')->orderBy('je.posted_at')->orderBy('je.id')->orderBy('jl.line_number')
            ->forPage($page, $perPage)->get();

        $search = ! empty($filters['q']);
        $data = $rows->map(function ($row) use ($totals, $search) {
            $opening = BigDecimal::of($totals[$row->account_id]->opening_net ?? '0');
            $running = $search ? null : Money::str(BalanceCalculator::signed($opening->plus($row->running_net), $row->normal_balance));

            return [
                'line_id' => $row->id, 'posting_date' => $row->posting_date, 'journal_id' => $row->journal_id, 'journal_number' => $row->journal_number,
                'journal_type' => $row->journal_type, 'source_type' => $row->source_type, 'source_id' => $row->source_id,
                'journal_description' => $row->journal_description, 'journal_reference' => $row->journal_reference,
                'description' => $row->description, 'reference' => $row->reference,
                'account' => ['id' => $row->account_id, 'code' => $row->code, 'name' => $row->name, 'normal_balance' => $row->normal_balance],
                'branch_id' => $row->branch_id, 'business_unit_id' => $row->business_unit_id, 'cost_center_id' => $row->cost_center_id,
                'debit' => Money::str($row->debit), 'credit' => Money::str($row->credit), 'running_balance' => $running,
            ];
        })->all();

        return [
            'range' => $range,
            'account' => $account?->only(['id', 'code', 'name', 'account_type', 'normal_balance']),
            'summary' => $this->summary($account, $totals),
            'data' => $data,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
            'complete' => $this->balances->complete($filters),
        ];
    }

    /**
     * Every line of the range for a CSV export (bounded), in ledger order, with the same filters and scope as the screen.
     * The size check happens here, before any output starts.
     *
     * @return iterable<array{row:object,running:string}>
     */
    public function exportRows(array $filters): iterable
    {
        $range = $this->balances->range($filters);
        $totals = $this->balances->totals($range['from'], $range['to'], $filters);
        $query = $this->linesQuery($range, $filters);
        if ((clone $query)->count() > self::EXPORT_LIMIT) {
            throw new DomainException('Too many lines to export; narrow the date range or the account.', 'EXPORT_TOO_LARGE', 422, ['limit' => self::EXPORT_LIMIT]);
        }
        $query->orderBy('a.code')->orderBy('je.posting_date')->orderBy('je.posted_at')->orderBy('je.id')->orderBy('jl.line_number');

        return (function () use ($query, $totals, $filters) {
            foreach ($query->cursor() as $row) {
                $opening = BigDecimal::of($totals[$row->account_id]->opening_net ?? '0');
                yield [
                    'row' => $row,
                    'running' => empty($filters['q']) ? Money::str(BalanceCalculator::signed($opening->plus($row->running_net), $row->normal_balance)) : '',
                ];
            }
        })();
    }

    private function linesQuery(array $range, array $filters)
    {
        $query = $this->balances->base($filters)
            ->join('accounts as a', fn ($join) => $join->on('a.id', '=', 'jl.account_id')->on('a.tenant_id', '=', 'jl.tenant_id'))
            ->where('je.journal_type', '<>', 'OPENING')
            ->whereBetween('je.posting_date', [$range['from'], $range['to']])
            ->select([
                'jl.id', 'jl.account_id', 'jl.description', 'jl.reference', 'jl.debit', 'jl.credit', 'jl.branch_id', 'jl.business_unit_id', 'jl.cost_center_id',
                'je.posting_date', 'je.id as journal_id', 'je.journal_number', 'je.journal_type', 'je.source_type', 'je.source_id',
                'je.description as journal_description', 'je.reference as journal_reference', 'a.code', 'a.name', 'a.normal_balance',
            ]);

        // The running sum is taken over the whole filtered range before paging; a text search would make it meaningless, so it is skipped then.
        $query->selectRaw('sum(jl.debit - jl.credit) over (partition by jl.account_id order by je.posting_date, je.posted_at, je.id, jl.line_number rows between unbounded preceding and current row) as running_net');

        if (! empty($filters['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($filters['q'])).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(je.journal_number) like ?', [$like])->orWhereRaw('lower(je.reference) like ?', [$like])
                ->orWhereRaw('lower(je.description) like ?', [$like])->orWhereRaw('lower(jl.description) like ?', [$like])->orWhereRaw('lower(jl.reference) like ?', [$like])
                ->orWhereRaw('lower(je.source_id) like ?', [$like]));
        }

        return $query;
    }

    /** One account: opening / debit / credit / closing in its normal direction. All accounts: just the movement totals. */
    private function summary(?Account $account, $totals): array
    {
        if ($account === null) {
            $debit = Money::sum($totals->pluck('debit'));
            $credit = Money::sum($totals->pluck('credit'));

            return ['debit' => Money::str($debit), 'credit' => Money::str($credit)];
        }

        $row = $totals[$account->id] ?? null;
        $opening = BigDecimal::of($row->opening_net ?? '0');
        $debit = BigDecimal::of($row->debit ?? '0');
        $credit = BigDecimal::of($row->credit ?? '0');

        return [
            'normal_balance' => $account->normal_balance,
            'opening' => Money::str(BalanceCalculator::signed($opening, $account->normal_balance)),
            'debit' => Money::str($debit), 'credit' => Money::str($credit),
            'closing' => Money::str(BalanceCalculator::signed($opening->plus($debit)->minus($credit), $account->normal_balance)),
        ];
    }
}
