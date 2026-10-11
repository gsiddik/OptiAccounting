<?php

namespace App\Domain\Reconciliation\Services\Adapters;

use App\Domain\CashBank\Services\CashBankReconciliationService;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use Brick\Math\BigDecimal;

/**
 * Cash and bank against the general ledger (OA2 `CashBankReconciliationService`, module ACCOUNTING_CASH_BANK). The three truths stay apart and
 * nothing is adjusted to make them agree:
 *
 *   CASH_BANK:<code>   compared, one per cash / bank account. Source = the operational cash and bank documents (receipts, payments, vendor
 *                      payments, customer receipts, directly paid expenses) as of the date; GL = the ledger lines those documents' journals put on the
 *                      account's GL account. `book_balance` (the whole GL balance of the account) and `other_activity` (opening balance, manual
 *                      journals: what the ledger holds beyond the documents) are reported inside the component, never as errors.
 *   STATEMENT:<code>   informational, where a bank statement exists on or before the date: the bank's closing balance against the book balance. A
 *                      difference there is a bank reconciliation matter (bank statements module), not a ledger error.
 *
 * Headline: operational documents against their ledger lines, summed over the accounts (equals the legacy `reconciliation/cash-bank` figures).
 */
class CashBankGlReconciliation extends ReconciliationAdapter
{
    public function __construct(private readonly CashBankReconciliationService $service) {}

    public function type(): string
    {
        return ReconciliationResult::CASH_BANK_GL;
    }

    public function module(): string
    {
        return 'ACCOUNTING_CASH_BANK';
    }

    public function reconcile(string $asOf, BigDecimal $tolerance): ReconciliationResult
    {
        $report = $this->service->report($asOf);
        $notes = ['Compared per account: the operational cash and bank documents as of the date against the ledger lines of their own journals. The book balance, other ledger activity and the bank statement are shown beside it and are not compared with the documents.'];
        if ($report['accounts'] === []) {
            return $this->outcome($asOf, $tolerance, BigDecimal::zero(), BigDecimal::zero(), [], ['There is no cash or bank account to reconcile.']);
        }

        $components = [];
        $source = $gl = BigDecimal::zero();
        foreach ($report['accounts'] as $account) {
            $documents = $account['documents'];
            $source = $source->plus($documents['net']);
            $gl = $gl->plus($documents['ledger_net']);
            $reference = ['cash_bank_account_id' => $account['cash_bank_account_id'], 'code' => $account['code'], 'name' => $account['name'], 'kind' => $account['kind'], 'status' => $account['status'], 'gl_account' => $account['gl_account']];
            $components[] = $this->component("CASH_BANK:{$account['code']}", "Operational documents against the ledger: {$account['code']} {$account['name']}", BigDecimal::of($documents['net']), BigDecimal::of($documents['ledger_net']), $tolerance, false, [
                'reference' => $reference, 'book_balance' => $account['book_balance'], 'other_activity' => $account['other_activity'],
                'documents' => array_diff_key($documents, ['difference' => 1, 'status' => 1, 'ledger_net' => 1, 'net' => 1]),
            ]);
            if ($account['statement'] !== null) {
                $statement = $account['statement'];
                $components[] = $this->component("STATEMENT:{$account['code']}", "Bank statement {$statement['reference']} of {$statement['statement_date']} against the book balance: {$account['code']} {$account['name']}",
                    BigDecimal::of($statement['statement_balance']), BigDecimal::of($account['book_balance']), $tolerance, true, [
                        'reference' => $reference, 'statement_id' => $statement['id'], 'statement_date' => $statement['statement_date'], 'statement_status' => $statement['status'],
                        'bank_reconciliation' => $statement['reconciliation'], 'unexplained_difference' => $statement['unexplained_difference'],
                    ]);
            }
        }

        return $this->outcome($asOf, $tolerance, $source, $gl, $components, $notes);
    }
}
