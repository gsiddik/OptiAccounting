<?php

namespace App\Domain\Reconciliation\Services;

/**
 * Central reconciliation framework (OA5 batch F). CONTRACT used by close readiness (Closing) and the dashboard; the body is implemented in
 * batches F-H. Every method recomputes from authoritative data at one explicit cut-off date and reads the tenant from TenantContext.
 */
class ReconciliationEngine
{
    /** Live computation, nothing persisted. A type whose module / data is unavailable returns NOT_APPLICABLE; a narrower data scope returns INCOMPLETE. */
    public function compute(string $type, string $asOf): ReconciliationResult
    {
        throw new \LogicException('ReconciliationEngine::compute is implemented in OA5 batch F.');
    }

    /** @return array<string,ReconciliationResult> every ReconciliationResult::TYPES entry, keyed by type */
    public function computeAll(string $asOf): array
    {
        throw new \LogicException('ReconciliationEngine::computeAll is implemented in OA5 batch F.');
    }
}
