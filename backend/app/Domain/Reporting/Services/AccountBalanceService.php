<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Services\BalanceCalculator;
use App\Domain\Accounting\Services\TrialBalanceService;
use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Reporting\Support\AccountTree;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;

/**
 * Account balance report: the balance of one account, of an account range / type / list, or of an account group (a header account stands for
 * every posting account below it), either as of a date or over a period. Nothing is stored: the figures are the trial balance of the same
 * selection (so they equal the general ledger and the trial balance by construction), expressed in each account's normal-balance direction.
 *
 *   AS_OF   `balance` = cumulative POSTED balance up to the date (opening-balance journals included), the date defaults to the tenant's business date
 *   PERIOD  `opening`, `debit`, `credit`, `closing` over [from, to] (period / fiscal year / dates), same semantics as the trial balance
 *
 * Data scope and dimension filters narrow the lines, so a scoped user gets scoped balances and `complete` is false.
 */
class AccountBalanceService
{
    /** An id that matches no account: an account group whose accounts the other filters exclude must produce an empty report, not an unfiltered one. */
    private const NO_ACCOUNT = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly ReportQuery $query,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string,mixed>  $filters  account_id, account_ids, account_type, account_code_from/to, as_of | period_id | fiscal_year_id | from/to, branch_id, business_unit_id, cost_center_id, hierarchy, include_zero
     * @return array<string,mixed>
     */
    public function report(array $filters): array
    {
        $accounts = $this->query->accounts();
        $scopeFilters = $filters; // before the group is expanded into account ids: a group is a complete view of its own accounts
        $group = null;
        if (! empty($filters['account_id'])) {
            $group = $accounts[$filters['account_id']] ?? throw new DomainException('The account does not exist.', 'ACCOUNT_NOT_FOUND', 404);
            $under = (new AccountTree($accounts))->postingUnder($group->id);
            $listed = (array) ($filters['account_ids'] ?? []);
            $selected = $listed === [] ? $under : array_values(array_intersect($under, $listed));
            $filters['account_ids'] = $selected === [] ? [self::NO_ACCOUNT] : $selected;
        }
        unset($filters['account_id']);

        $dated = array_filter([$filters['period_id'] ?? null, $filters['fiscal_year_id'] ?? null, $filters['from'] ?? null, $filters['to'] ?? null]);
        if (! empty($filters['as_of']) && $dated !== []) {
            throw new DomainException('Give either as_of or a period / date range, not both.', 'DATE_FILTER_CONFLICT', 422);
        }
        $asOf = null;
        if ($dated === []) {
            $asOf = $filters['as_of'] ?? Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
            $filters['from'] = $filters['to'] = $asOf;
        }
        unset($filters['as_of']);

        $tb = $this->trialBalance->report($filters);
        $direction = $group?->normal_balance ?? 'DEBIT';

        $rows = [];
        foreach ($tb['data'] as $r) {
            $opening = BigDecimal::of($r['opening_debit'])->minus($r['opening_credit']);
            $closing = BigDecimal::of($r['ending_debit'])->minus($r['ending_credit']);
            $row = [
                'account_id' => $r['account_id'], 'code' => $r['code'], 'name' => $r['name'], 'account_type' => $r['account_type'], 'normal_balance' => $r['normal_balance'],
                'parent_id' => $r['parent_id'], 'depth' => $r['depth'], 'is_header' => $r['is_header'],
            ];
            $rows[] = $asOf !== null
                ? $row + ['balance' => Money::str(BalanceCalculator::signed($closing, $r['normal_balance']))]
                : $row + [
                    'opening' => Money::str(BalanceCalculator::signed($opening, $r['normal_balance'])), 'debit' => $r['debit'], 'credit' => $r['credit'],
                    'closing' => Money::str(BalanceCalculator::signed($closing, $r['normal_balance'])),
                ];
        }

        $t = $tb['totals'];
        $openingNet = BigDecimal::of($t['opening_debit'])->minus($t['opening_credit']);
        $closingNet = BigDecimal::of($t['ending_debit'])->minus($t['ending_credit']);
        $totals = ['normal_balance' => $direction, 'balance' => Money::str(BalanceCalculator::signed($closingNet, $direction)), 'closing_net_debit_minus_credit' => Money::str($closingNet)];
        if ($asOf === null) {
            $totals += [
                'opening' => Money::str(BalanceCalculator::signed($openingNet, $direction)), 'debit' => $t['debit'], 'credit' => $t['credit'],
                'closing' => $totals['balance'], 'opening_net_debit_minus_credit' => Money::str($openingNet),
            ];
        }

        return [
            'mode' => $asOf !== null ? 'AS_OF' : 'PERIOD',
            'as_of' => $asOf,
            'range' => $asOf !== null ? null : $tb['range'],
            'functional_currency' => AccountingProfile::query()->value('functional_currency'),
            'account' => $group?->only(['id', 'code', 'name', 'account_type', 'normal_balance', 'is_postable']),
            'filters' => $group === null ? $tb['filters'] : ['account_id' => $group->id] + array_diff_key($tb['filters'], ['account_ids' => 1]),
            'data' => $rows,
            'totals' => $totals,
            'complete' => $this->query->complete($scopeFilters),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
