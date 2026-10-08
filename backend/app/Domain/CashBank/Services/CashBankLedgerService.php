<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Support\Money;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Support\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The transaction history of a cash or bank account: the posted journal lines of its mapped GL account, with the document that caused
 * each, a running balance and whether a bank statement item has been matched to it. It is a read model over the ledger; nothing here
 * is stored, so it can never disagree with the general ledger.
 */
class CashBankLedgerService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{from?:?string,to?:?string,matched?:?bool,q?:?string,direction?:?string}  $filter
     */
    public function lines(CashBankAccount $account, array $filter, int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        $tenant = $this->context->tenantId();
        $opening = isset($filter['from']) ? $this->balanceBefore($account->account_id, $filter['from']) : '0';

        $inner = DB::table('journal_lines as l')
            ->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->leftJoin('bank_statement_items as i', 'i.matched_journal_line_id', '=', 'l.id')
            ->leftJoin('bank_statements as s', fn ($join) => $join->on('s.id', '=', 'i.bank_statement_id')->on('s.tenant_id', '=', 'i.tenant_id'))
            ->where('l.tenant_id', $tenant)->where('l.account_id', $account->account_id)->where('j.status', 'POSTED')
            ->when($filter['from'] ?? null, fn ($q, $d) => $q->where('j.posting_date', '>=', $d))
            ->when($filter['to'] ?? null, fn ($q, $d) => $q->where('j.posting_date', '<=', $d))
            ->selectRaw('l.id as journal_line_id, j.id as journal_entry_id, j.journal_number, j.journal_type, j.source_type, j.source_id, j.posting_date,
                         coalesce(l.description, j.description) as description, j.description as journal_description, coalesce(l.reference, j.reference) as reference, l.debit, l.credit,
                         i.id as matched_item_id, s.reference as matched_statement, s.id as matched_statement_id,
                         (?::numeric + sum(l.debit - l.credit) over (order by j.posting_date, j.journal_number, l.line_number rows between unbounded preceding and current row)) as running_balance,
                         l.line_number', [Money::str($opening)]);

        $outer = DB::query()->fromSub($inner, 't')
            ->when(($filter['matched'] ?? null) !== null, fn ($q) => $filter['matched'] ? $q->whereNotNull('matched_item_id') : $q->whereNull('matched_item_id'))
            ->when($filter['direction'] ?? null, fn ($q, $d) => $d === 'IN' ? $q->where('debit', '>', 0) : $q->where('credit', '>', 0))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(description, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(journal_description, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(reference, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(journal_number, \'\')) like ?', [$like]));
            });

        $total = (clone $outer)->count();
        $rows = $outer->orderBy('posting_date')->orderBy('journal_number')->orderBy('line_number')->forPage($page, $perPage)->get();
        $rows->transform(function ($row) {
            $row->debit = Money::str($row->debit);
            $row->credit = Money::str($row->credit);
            $row->running_balance = Money::str($row->running_balance);
            $row->direction = (float) $row->debit > 0 ? 'IN' : 'OUT';
            $row->amount = Money::str((float) $row->debit > 0 ? $row->debit : $row->credit);
            $row->document_number = null;
            unset($row->line_number, $row->journal_description);

            return $row;
        });
        $this->attachDocumentNumbers($rows->all());

        return new LengthAwarePaginator($rows, $total, $perPage, $page, ['path' => request()->url()]);
    }

    /** The balance of the GL account before $date (posted lines only). */
    public function balanceBefore(string $glAccountId, string $date): string
    {
        $balance = DB::table('journal_lines as l')->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->where('l.tenant_id', $this->context->tenantId())->where('l.account_id', $glAccountId)->where('j.status', 'POSTED')->where('j.posting_date', '<', $date)
            ->selectRaw('coalesce(sum(l.debit - l.credit), 0) as b')->value('b');

        return Money::str((string) $balance);
    }

    /** Book lines of the account that no statement item has been matched to, up to $date: the reconciling items on the book side. */
    public function unmatched(string $glAccountId, string $asOf): object
    {
        return DB::table('journal_lines as l')->join('journal_entries as j', fn ($join) => $join->on('j.id', '=', 'l.journal_entry_id')->on('j.tenant_id', '=', 'l.tenant_id'))
            ->leftJoin('bank_statement_items as i', 'i.matched_journal_line_id', '=', 'l.id')
            ->where('l.tenant_id', $this->context->tenantId())->where('l.account_id', $glAccountId)->where('j.status', 'POSTED')->where('j.posting_date', '<=', $asOf)->whereNull('i.id')
            ->selectRaw('count(*) as lines, coalesce(sum(l.debit - l.credit), 0) as net')->first();
    }

    /** @param list<object> $rows */
    private function attachDocumentNumbers(array $rows): void
    {
        $tables = ['vendor_payment' => 'vendor_payments', 'expense' => 'expenses', 'cash_transaction' => 'cash_transactions', 'ap_invoice' => 'ap_invoices'];
        foreach ($tables as $type => $table) {
            $ids = collect($rows)->where('source_type', $type)->pluck('source_id')->unique()->values()->all();
            if ($ids === []) {
                continue;
            }
            $numbers = DB::table($table)->where('tenant_id', $this->context->tenantId())->whereIn('id', $ids)->pluck('document_number', 'id');
            foreach ($rows as $row) {
                if ($row->source_type === $type) {
                    $row->document_number = $numbers[$row->source_id] ?? null;
                }
            }
        }
    }
}
