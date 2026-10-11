<?php

namespace App\Domain\Reconciliation\Services\Adapters;

use App\Domain\Payables\Services\ApReconciliationService;
use App\Domain\Reconciliation\Services\ReconciliationResult;

/** AP subledger against the AP control accounts (OA2 `ApReconciliationService`, module ACCOUNTING_AP). */
class ApGlReconciliation extends ControlAccountReconciliation
{
    public function __construct(private readonly ApReconciliationService $service) {}

    public function type(): string
    {
        return ReconciliationResult::AP_GL;
    }

    public function module(): string
    {
        return 'ACCOUNTING_AP';
    }

    protected function report(string $asOf): array
    {
        return $this->service->report($asOf);
    }

    protected function vocabulary(): array
    {
        return ['subject' => 'accounts payable', 'role' => 'ACCOUNTS_PAYABLE', 'entity' => 'vendor', 'list' => 'vendors', 'subledger' => 'AP'];
    }
}
