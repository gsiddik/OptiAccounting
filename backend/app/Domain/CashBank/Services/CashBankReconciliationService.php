<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Accounting\Support\Money;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Cash/bank to general ledger reconciliation as of a date, per cash or bank account, keeping the two truths apart:
 *   book balance        what the ledger says (posted lines of the account's GL account);
 *   statement balance   what the bank says (the latest bank statement on or before the date), evidence only;
 *   documents           the posted OA2 documents that moved this account (vendor payments, directly paid expenses, cash payments and receipts),
 *                       compared with the ledger lines their journals (and reversals) put on the GL account.
 * Documents and ledger must agree (MATCHED); everything else the ledger holds (the opening balance, manual journals) is reported as
 * other activity, never as an error. A difference between book and bank is shown as the unexplained difference of the statement and
 * is never adjusted: this service reads, it does not write.
 */
class CashBankReconciliationService
{
    public function __construct(
        private readonly CashBankAccountService $accounts,
        private readonly AccountingScope $scope,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string,mixed> */
    public function report(string $asOf, ?string $cashBankAccountId = null): array
    {
        $rows = $this->accounts->query(['status' => null])->when($cashBankAccountId, fn ($q, $id) => $q->where('cash_bank_accounts.id', $id))->get();
        $balances = $this->accounts->bookBalances($rows->pluck('account_id')->all(), $asOf);

        $out = [];
        foreach ($rows as $account) {
            $out[] = $this->account($account, $asOf, BigDecimal::of($balances[$account->account_id]));
        }
        $mismatch = collect($out)->where('documents.status', 'MISMATCH')->count();

        return [
            'as_of' => $asOf, 'accounts' => $out, 'mismatched_accounts' => $mismatch, 'status' => $mismatch === 0 ? 'MATCHED' : 'MISMATCH',
            'book_balance' => Money::str(Money::sum(array_column($out, 'book_balance'))), 'complete' => $this->scope->isTenantWide(),
        ];
    }

    /** @return array<string,mixed> */
    private function account(CashBankAccount $account, string $asOf, BigDecimal $book): array
    {
        $tenant = $this->context->tenantId();
        $gl = $account->account_id;

        $documents = $this->documentsNet($tenant, $gl, $asOf);
        $fromDocuments = $this->ledgerFromDocuments($tenant, $gl, $asOf);
        $difference = $fromDocuments->minus($documents['net']);

        $statement = $account->kind === CashBankAccount::BANK ? DB::table('bank_statements')->where('tenant_id', $tenant)->where('cash_bank_account_id', $account->id)
            ->whereDate('statement_date', '<=', $asOf)->orderByDesc('statement_date')->orderByDesc('created_at')->first() : null;

        return [
            'cash_bank_account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'kind' => $account->kind, 'status' => $account->status,
            'gl_account' => ['id' => $gl, 'code' => $account->glAccount?->code, 'name' => $account->glAccount?->name],
            'book_balance' => Money::str($book),
            'documents' => [
                'receipts' => Money::str($documents['receipts']), 'cash_payments' => Money::str($documents['cash_payments']), 'vendor_payments' => Money::str($documents['vendor_payments']),
                'customer_receipts' => Money::str($documents['customer_receipts']),
                'paid_expenses' => Money::str($documents['paid_expenses']), 'net' => Money::str($documents['net']), 'ledger_net' => Money::str($fromDocuments),
                'difference' => Money::str($difference), 'status' => $difference->isZero() ? 'MATCHED' : 'MISMATCH',
            ],
            'other_activity' => Money::str($book->minus($fromDocuments)), // opening balance, manual journals and anything else the ledger holds on this account
            'statement' => $statement === null ? null : [
                'id' => $statement->id, 'reference' => $statement->reference, 'statement_date' => $statement->statement_date, 'status' => $statement->status,
                'statement_balance' => Money::str($statement->closing_balance), 'book_minus_statement' => Money::str($book->minus($statement->closing_balance)),
                'unexplained_difference' => $statement->unexplained_difference === null ? null : Money::str($statement->unexplained_difference),
                'reconciliation' => $statement->unexplained_difference === null ? 'IN_PROGRESS' : (BigDecimal::of($statement->unexplained_difference)->isZero() ? 'RECONCILED' : 'DIFFERENCE'),
            ],
        ];
    }

    /**
     * What the OA2 and OA3 documents say moved this GL account up to $asOf, from the documents themselves: a posted document counts from its
     * posting date, and not any more once its reversal is also posted on or before $asOf.
     *
     * @return array{receipts:BigDecimal,cash_payments:BigDecimal,vendor_payments:BigDecimal,customer_receipts:BigDecimal,paid_expenses:BigDecimal,net:BigDecimal}
     */
    private function documentsNet(string $tenant, string $gl, string $asOf): array
    {
        $live = fn (string $table) => DB::table($table)->where('tenant_id', $tenant)->where('gl_account_id', $gl)->whereDate('posting_date', '<=', $asOf)
            ->where(fn ($q) => $q->where('status', 'POSTED')->orWhere(fn ($r) => $r->where('status', 'REVERSED')->whereDate('reversal_posting_date', '>', $asOf)));
        $sum = fn ($query, string $column) => BigDecimal::of((string) $query->sum($column));

        $receipts = $sum($live('cash_transactions')->where('kind', 'RECEIPT'), 'amount');
        $payments = $sum($live('cash_transactions')->where('kind', 'PAYMENT'), 'amount');
        $vendor = $sum($live('vendor_payments'), 'amount');
        $customer = $sum($live('customer_receipts'), 'amount');
        $expenses = $sum($live('expenses')->where('settlement', 'DIRECT_PAID'), 'total_amount');

        return [
            'receipts' => $receipts, 'cash_payments' => $payments, 'vendor_payments' => $vendor, 'customer_receipts' => $customer, 'paid_expenses' => $expenses,
            'net' => $receipts->plus($customer)->minus($payments)->minus($vendor)->minus($expenses),
        ];
    }

    /** The net (debit minus credit) of the GL account's posted lines that come from those documents' journals and from their reversals, up to $asOf. */
    private function ledgerFromDocuments(string $tenant, string $gl, string $asOf): BigDecimal
    {
        $sql = "
            with doc_journals as (
                select journal_entry_id as jid from vendor_payments where tenant_id = :t1 and gl_account_id = :g1 and journal_entry_id is not null
                union all select reversal_journal_id from vendor_payments where tenant_id = :t2 and gl_account_id = :g2 and reversal_journal_id is not null
                union all select journal_entry_id from expenses where tenant_id = :t3 and gl_account_id = :g3 and settlement = 'DIRECT_PAID' and journal_entry_id is not null
                union all select reversal_journal_id from expenses where tenant_id = :t4 and gl_account_id = :g4 and settlement = 'DIRECT_PAID' and reversal_journal_id is not null
                union all select journal_entry_id from cash_transactions where tenant_id = :t5 and gl_account_id = :g5 and journal_entry_id is not null
                union all select reversal_journal_id from cash_transactions where tenant_id = :t6 and gl_account_id = :g6 and reversal_journal_id is not null
                union all select journal_entry_id from customer_receipts where tenant_id = :t7 and gl_account_id = :g7 and journal_entry_id is not null
                union all select reversal_journal_id from customer_receipts where tenant_id = :t8 and gl_account_id = :g8 and reversal_journal_id is not null
            )
            select coalesce(sum(l.debit - l.credit), 0) as net
            from journal_lines l join journal_entries j on j.id = l.journal_entry_id and j.tenant_id = l.tenant_id
            where l.tenant_id = :t and l.account_id = :g and j.status = 'POSTED' and j.posting_date <= :d and j.id in (select jid from doc_journals)";
        $bindings = ['t' => $tenant, 'g' => $gl, 'd' => $asOf];
        foreach (range(1, 8) as $i) {
            $bindings["t{$i}"] = $tenant;
            $bindings["g{$i}"] = $gl;
        }

        return BigDecimal::of((string) DB::selectOne($sql, $bindings)->net);
    }
}
