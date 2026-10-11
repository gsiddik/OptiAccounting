<?php

namespace App\Domain\Reporting\Services;

use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;

/**
 * The in-service re-check of what a report needs beyond its route gate: the view permission of the statement a request names (the comparative,
 * drill-down and export routes serve several statements behind one route and one permission), and the budget permission plus module for a
 * comparison with the budget. It asks the central resolver, so it can never disagree with the route gate; it decides nothing itself.
 */
class ReportAccess
{
    /** The permission that opens each statement on screen; its exports, comparatives and drill-downs need it too. */
    public const VIEW = [
        ReportDefinition::BALANCE_SHEET => 'accounting.reporting.balance_sheet.view',
        ReportDefinition::PROFIT_LOSS => 'accounting.reporting.pnl.view',
        ReportDefinition::CASH_FLOW => 'accounting.reporting.cash_flow.view',
    ];

    public function __construct(private readonly EffectiveAccess $access, private readonly TenantContext $context) {}

    /** The caller may view the statement (permission, with the financial statements feature of the reporting module). */
    public function assertStatement(string $statementType): void
    {
        $this->assert(self::VIEW[$statementType], 'ACCOUNTING_REPORTING', 'FINANCIAL_STATEMENTS');
    }

    /** The caller may read budgets: the budget permission and the budget module entitled. */
    public function assertBudget(): void
    {
        $this->assert('accounting.budget.view', 'ACCOUNTING_BUDGET', 'BUDGET');
    }

    /** The caller may view the general ledger (the screen behind the account balance report). */
    public function assertLedger(): void
    {
        $this->assert('accounting.gl.view', 'ACCOUNTING_REPORTING', 'FINANCIAL_STATEMENTS');
    }

    private function assert(string $permission, string $module, string $feature): void
    {
        $user = $this->context->user() ?? throw new DomainException('Reports need a signed-in user.', 'UNAUTHENTICATED', 401);
        $decision = $this->access->evaluate(new AccessRequest($user, $this->context->tenantId(), $permission, $module, $feature));
        if (! $decision->allowed) {
            throw new DomainException($decision->message(), $decision->code, $decision->httpStatus(), ['permission' => $permission, 'module' => $module]);
        }
    }
}
