<?php

namespace App\Domain\Reconciliation\Services\Adapters;

use App\Domain\Receivables\Services\ArReconciliationService;
use App\Domain\Reconciliation\Services\ReconciliationResult;

/** AR subledger against the AR control accounts (OA3 `ArReconciliationService`, module ACCOUNTING_AR). */
class ArGlReconciliation extends ControlAccountReconciliation
{
    public function __construct(private readonly ArReconciliationService $service) {}

    public function type(): string
    {
        return ReconciliationResult::AR_GL;
    }

    public function module(): string
    {
        return 'ACCOUNTING_AR';
    }

    protected function report(string $asOf): array
    {
        return $this->service->report($asOf);
    }

    protected function vocabulary(): array
    {
        return ['subject' => 'accounts receivable', 'role' => 'ACCOUNTS_RECEIVABLE', 'entity' => 'customer', 'list' => 'customers', 'subledger' => 'AR'];
    }
}
