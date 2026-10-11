<?php

namespace App\Domain\Reconciliation\Services\Adapters;

use App\Domain\Reconciliation\Services\ReconciliationResult;
use Brick\Math\BigDecimal;

/**
 * The shared shape of the two subledger reconciliations (AP and AR): the open documents of the subledger against the control accounts of the
 * general ledger at the same date. The subledger / ledger maths stays in the module's own reconciliation service (the one behind the legacy
 * `reconciliation/ap|ar` routes); this class only normalises its report:
 *
 *   SUBLEDGER_VS_GL    compared. Source = what the documents say is outstanding that day, GL = the posted lines of the control accounts that belong to
 *                      those documents (so the figures equal the legacy report). The mismatching counterparties are listed in `breakdown` (at most 50).
 *   OPENING_BALANCE    informational. An opening balance posted straight to a control account has no documents behind it; it is shown, not compared.
 *   CONTROL_ACCOUNT    informational, one per control account: its whole GL balance.
 *
 * No control account at all with an empty subledger is a trivial MATCHED (nothing to compare); documents without any control account are INCOMPLETE.
 */
abstract class ControlAccountReconciliation extends ReconciliationAdapter
{
    private const BREAKDOWN_LIMIT = 50;

    /** @return array<string,mixed> the legacy report of the module for $asOf */
    abstract protected function report(string $asOf): array;

    /** Names for notes and labels: ['subject' => 'accounts payable', 'role' => 'ACCOUNTS_PAYABLE', 'entity' => 'vendor', 'list' => 'vendors', 'subledger' => 'AP']. @return array<string,string> */
    abstract protected function vocabulary(): array;

    public function reconcile(string $asOf, BigDecimal $tolerance): ReconciliationResult
    {
        $v = $this->vocabulary();
        $report = $this->report($asOf);
        $subledger = BigDecimal::of($report['subledger_balance']);
        $gl = BigDecimal::of($report['gl_transactional_balance']);
        $opening = BigDecimal::of($report['opening_balance_component']);

        if ($report['control_accounts'] === []) {
            if ($subledger->isZero()) {
                return $this->outcome($asOf, $tolerance, BigDecimal::zero(), BigDecimal::zero(), [], ["No {$v['subject']} control account is configured and the subledger is empty: there is nothing to compare."]);
            }

            return $this->incomplete($asOf, $tolerance, $subledger, null, ["The subledger has open documents but no {$v['subject']} control account is mapped (role {$v['role']}), so the ledger side cannot be determined."]);
        }

        $differing = collect($report[$v['list']])->where('status', ReconciliationResult::MISMATCH)->values();
        $breakdown = $differing->take(self::BREAKDOWN_LIMIT)->map(fn ($row) => [
            "{$v['entity']}_id" => $row["{$v['entity']}_id"], 'code' => $row["{$v['entity']}_code"], 'name' => $row["{$v['entity']}_name"],
            'source_balance' => $row['subledger_balance'], 'gl_balance' => $row['gl_balance'],
            'difference' => BigDecimal::of($row['subledger_balance'])->minus($row['gl_balance'])->toScale(4)->__toString(),
        ])->all();

        $components = [$this->component('SUBLEDGER_VS_GL', ucfirst($v['subject']).' subledger against the control accounts', $subledger, $gl, $tolerance, false, [
            'breakdown' => $breakdown, 'breakdown_total' => $differing->count(), 'breakdown_truncated' => $differing->count() > self::BREAKDOWN_LIMIT,
        ])];
        if (! $opening->isZero()) {
            $components[] = $this->component('OPENING_BALANCE', 'Opening balance posted straight to the control accounts (no documents behind it, not compared)', null, $opening, $tolerance, true);
        }
        foreach ($report['control_accounts'] as $account) {
            $components[] = $this->component('CONTROL_ACCOUNT', "Control account {$account['code']} {$account['name']}", null, BigDecimal::of($account['gl_balance']), $tolerance, true, [
                'reference' => ['account_id' => $account['id'], 'code' => $account['code'], 'name' => $account['name']],
            ]);
        }

        return $this->outcome($asOf, $tolerance, $subledger, $gl, $components, [
            "Compared: open {$v['subledger']} documents as of the date (functional currency) against the posted ledger lines of the {$v['subject']} control accounts that belong to those documents.",
        ]);
    }
}
