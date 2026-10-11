<?php

namespace App\Http\Controllers\Api\App\Reporting;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Entitlement\Services\ModuleAvailability;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Services\ComparativeReportService;
use App\Domain\Shared\DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The filter vocabulary shared by every ledger and statement endpoint, so a trial balance, a general ledger, a statement and its export accept
 * exactly the same parameters with the same meaning. Rules only describe the shape of the input; tenant ownership is never taken from it
 * (an id of another tenant simply matches nothing) and the data scope is applied by the query layer.
 */
trait ValidatesReportFilters
{
    /** @return array<string,mixed> */
    protected function dimensionRules(): array
    {
        return ['branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid']];
    }

    /** @return array<string,mixed> */
    protected function rangeRules(): array
    {
        return ['period_id' => ['nullable', 'uuid'], 'fiscal_year_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']];
    }

    /** @return array<string,mixed> */
    protected function accountRules(): array
    {
        return [
            'account_type' => ['nullable', Rule::in(Account::TYPES)], 'account_ids' => ['nullable', 'array', 'max:200'], 'account_ids.*' => ['uuid'],
            'account_code_from' => ['nullable', 'string', 'max:30'], 'account_code_to' => ['nullable', 'string', 'max:30'],
        ];
    }

    /** @return array<string,mixed> */
    protected function currencyRules(): array
    {
        return ['currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/']];
    }

    /** @return array<string,mixed> */
    protected function accountBalanceFilters(Request $request): array
    {
        $filters = $request->validate($this->rangeRules() + $this->dimensionRules() + $this->accountRules() + [
            'account_id' => ['nullable', 'uuid'], 'as_of' => ['nullable', 'date_format:Y-m-d'], 'hierarchy' => ['nullable', 'boolean'], 'include_zero' => ['nullable', 'boolean'],
        ]);
        $this->guardAccountRange($filters);
        $filters['hierarchy'] = $request->boolean('hierarchy');
        $filters['include_zero'] = $request->boolean('include_zero');

        return $filters;
    }

    /**
     * The filters of a balance sheet: an as-of date (default: the business date), the definition, the dimensions and zero lines.
     *
     * @return array<string,mixed>
     */
    protected function balanceSheetFilters(Request $request): array
    {
        $filters = $request->validate($this->dimensionRules() + ['as_of' => ['nullable', 'date_format:Y-m-d'], 'definition_id' => ['nullable', 'uuid'], 'include_zero' => ['nullable', 'boolean']]);
        $filters['include_zero'] = $request->boolean('include_zero');

        return $filters;
    }

    /**
     * The filters of a period statement (profit and loss, cash flow): a period, a fiscal year or dates, the definition, the dimensions and zero
     * lines. A period names its own dates, so combining it with others is refused rather than silently resolved.
     *
     * @return array<string,mixed>
     */
    protected function periodStatementFilters(Request $request): array
    {
        $filters = $request->validate($this->rangeRules() + $this->dimensionRules() + ['definition_id' => ['nullable', 'uuid'], 'include_zero' => ['nullable', 'boolean']]);
        $this->guardPeriodChoice($filters);
        $filters['include_zero'] = $request->boolean('include_zero');

        return $filters;
    }

    /**
     * The filters of a comparative report: the statement, the comparison mode (a budget also names the budget) and the statement's own dates.
     *
     * @return array<string,mixed>
     */
    protected function comparativeFilters(Request $request): array
    {
        $filters = $request->validate($this->rangeRules() + $this->dimensionRules() + [
            'statement' => ['required', Rule::in(ReportDefinition::TYPES)], 'mode' => ['required', Rule::in(ComparativeReportService::MODES)],
            'as_of' => ['nullable', 'date_format:Y-m-d'], 'definition_id' => ['nullable', 'uuid'], 'include_zero' => ['nullable', 'boolean'],
            'budget_id' => ['nullable', 'required_if:mode,BUDGET', 'uuid'], 'version_id' => ['nullable', 'uuid'],
        ]);
        $filters['include_zero'] = $request->boolean('include_zero');
        $this->guardStatementDates($filters);

        return $filters;
    }

    /**
     * The filters of a drill-down: the statement and the code of the line, then exactly the filters of that statement.
     *
     * @return array<string,mixed>
     */
    protected function drilldownFilters(Request $request): array
    {
        $head = $request->validate(['statement' => ['required', Rule::in(ReportDefinition::TYPES)], 'line' => ['required', 'string', 'max:60']]);
        $filters = $head['statement'] === ReportDefinition::BALANCE_SHEET ? $this->balanceSheetFilters($request) : $this->periodStatementFilters($request);
        $this->guardStatementDates($head + $request->only(['as_of', 'period_id', 'fiscal_year_id', 'from', 'to']));

        return $head + $filters;
    }

    /**
     * A balance sheet is dated by `as_of`, a profit and loss or cash flow by a period; mixing them up would silently ignore a parameter.
     *
     * @param  array<string,mixed>  $filters
     */
    protected function guardStatementDates(array $filters): void
    {
        $asOf = ! empty($filters['as_of']);
        $range = ! empty($filters['period_id']) || ! empty($filters['fiscal_year_id']) || ! empty($filters['from']) || ! empty($filters['to']);
        if (($filters['statement'] === ReportDefinition::BALANCE_SHEET && $range) || ($filters['statement'] !== ReportDefinition::BALANCE_SHEET && $asOf)) {
            throw new DomainException($filters['statement'] === ReportDefinition::BALANCE_SHEET
                ? 'A balance sheet is dated by as_of, not by a period or range.' : 'A period statement is dated by a period or from / to, not by as_of.', 'DATE_FILTER_INVALID', 422, ['statement' => $filters['statement']]);
        }
        $this->guardPeriodChoice($filters);
    }

    /** @param  array<string,mixed>  $filters */
    protected function guardPeriodChoice(array $filters): void
    {
        if (! empty($filters['period_id']) && (! empty($filters['fiscal_year_id']) || ! empty($filters['from']) || ! empty($filters['to']))) {
            throw new DomainException('A period already names its dates; give either period_id or a fiscal year / date range.', 'DATE_FILTER_CONFLICT', 422);
        }
    }

    /** @param  array<string,mixed>  $filters */
    protected function guardAccountRange(array $filters): void
    {
        if (! empty($filters['account_code_from']) && ! empty($filters['account_code_to']) && $filters['account_code_from'] > $filters['account_code_to']) {
            throw new DomainException('The first account code is after the last.', 'ACCOUNT_RANGE_INVALID', 422);
        }
    }

    /**
     * A transaction-currency filter only exists where foreign currencies do: the functional currency is always a valid value, any other
     * needs the multi-currency module.
     *
     * @param  array<string,mixed>  $filters
     */
    protected function guardCurrencyFilter(array $filters): void
    {
        $currency = $filters['currency'] ?? null;
        if ($currency === null || $currency === AccountingProfile::query()->value('functional_currency')) {
            return;
        }
        if (! app(ModuleAvailability::class)->active('ACCOUNTING_MULTI_CURRENCY')) {
            throw new DomainException('Filtering by a foreign transaction currency needs the multi-currency module.', 'CURRENCY_FILTER_UNAVAILABLE', 422, ['currency' => $currency]);
        }
    }
}
