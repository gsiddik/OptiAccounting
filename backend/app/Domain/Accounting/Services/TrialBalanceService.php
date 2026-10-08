<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Support\Money;
use Brick\Math\BigDecimal;

/**
 * Trial balance for a date range from POSTED journal lines: opening, period debit and credit, ending, per account and
 * (optionally) rolled up the account hierarchy. Totals count posting accounts only; for a complete ledger scope each
 * pair of totals must be equal, and the report says so explicitly.
 */
class TrialBalanceService
{
    public function __construct(private readonly BalanceCalculator $balances) {}

    /**
     * @param  array<string,mixed>  $filters  period_id|from|to, branch_id, business_unit_id, cost_center_id, hierarchy, include_zero
     * @return array<string,mixed>
     */
    public function report(array $filters): array
    {
        $range = $this->balances->range($filters);
        unset($filters['account_id']);
        $totals = $this->balances->totals($range['from'], $range['to'], $filters);
        $includeZero = (bool) ($filters['include_zero'] ?? false);
        $hierarchy = (bool) ($filters['hierarchy'] ?? false);

        $accounts = Account::query()->orderBy('code')->get(['id', 'code', 'name', 'account_type', 'normal_balance', 'is_postable', 'parent_id', 'status']);
        $byId = $accounts->keyBy('id');

        // Own figures, then roll children into parents (deepest first) so a header shows the sum of everything below it.
        $own = [];
        foreach ($accounts as $account) {
            $t = $totals[$account->id] ?? null;
            $own[$account->id] = [
                'opening' => BigDecimal::of($t->opening_net ?? '0'), 'debit' => BigDecimal::of($t->debit ?? '0'), 'credit' => BigDecimal::of($t->credit ?? '0'),
            ];
        }
        $rolled = $own;
        $depth = [];
        foreach ($accounts as $account) {
            $depth[$account->id] = $this->depth($account, $byId);
        }
        foreach ($accounts->sortByDesc(fn ($a) => $depth[$a->id]) as $account) {
            if ($account->parent_id && isset($rolled[$account->parent_id])) {
                foreach (['opening', 'debit', 'credit'] as $k) {
                    $rolled[$account->parent_id][$k] = $rolled[$account->parent_id][$k]->plus($rolled[$account->id][$k]);
                }
            }
        }

        $active = fn (array $f) => ! $f['opening']->isZero() || ! $f['debit']->isZero() || ! $f['credit']->isZero();
        $rows = [];
        $sum = ['opening_debit' => BigDecimal::zero(), 'opening_credit' => BigDecimal::zero(), 'debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero(), 'ending_debit' => BigDecimal::zero(), 'ending_credit' => BigDecimal::zero()];

        foreach ($accounts as $account) {
            $isHeader = ! $account->is_postable;
            $figures = $isHeader ? $rolled[$account->id] : $own[$account->id];
            if (! $includeZero && ! $active($figures)) {
                continue;
            }
            if ($isHeader && ! $hierarchy) {
                continue;
            }

            [$openDebit, $openCredit] = BalanceCalculator::split($figures['opening']);
            [$endDebit, $endCredit] = BalanceCalculator::split($figures['opening']->plus($figures['debit'])->minus($figures['credit']));
            $rows[] = [
                'account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type, 'normal_balance' => $account->normal_balance,
                'parent_id' => $account->parent_id, 'depth' => $depth[$account->id], 'is_header' => $isHeader,
                'opening_debit' => Money::str($openDebit), 'opening_credit' => Money::str($openCredit),
                'debit' => Money::str($figures['debit']), 'credit' => Money::str($figures['credit']),
                'ending_debit' => Money::str($endDebit), 'ending_credit' => Money::str($endCredit),
            ];

            if (! $isHeader) {
                foreach (['opening_debit' => $openDebit, 'opening_credit' => $openCredit, 'debit' => $figures['debit'], 'credit' => $figures['credit'], 'ending_debit' => $endDebit, 'ending_credit' => $endCredit] as $k => $v) {
                    $sum[$k] = $sum[$k]->plus($v);
                }
            }
        }

        $complete = $this->balances->complete($filters);
        $pair = fn (string $d, string $c) => ['debit' => Money::str($sum[$d]), 'credit' => Money::str($sum[$c]), 'difference' => Money::str($sum[$d]->minus($sum[$c])), 'equal' => $sum[$d]->isEqualTo($sum[$c])];
        $reconciliation = ['opening' => $pair('opening_debit', 'opening_credit'), 'movement' => $pair('debit', 'credit'), 'ending' => $pair('ending_debit', 'ending_credit')];

        return [
            'range' => $range,
            'data' => $rows,
            'totals' => array_map(fn ($v) => Money::str($v), $sum),
            'reconciliation' => $reconciliation + ['reconciled' => $complete ? ($reconciliation['opening']['equal'] && $reconciliation['movement']['equal'] && $reconciliation['ending']['equal']) : null],
            'complete' => $complete,
        ];
    }

    private function depth(Account $account, $byId): int
    {
        $depth = 0;
        for ($node = $account; $node->parent_id !== null && isset($byId[$node->parent_id]) && $depth < 20; $depth++) {
            $node = $byId[$node->parent_id];
        }

        return $depth;
    }
}
