<?php

namespace App\Domain\Reconciliation\Services;

/**
 * The outcome of one reconciliation at one cut-off date (OA5 contract shared by the engine, the dashboard, close readiness and exports).
 * Amounts are decimal strings (NUMERIC(20,4) semantics, brick/math in the services). `status` is derived from the recomputed difference
 * only: a person's explanation or assignment never changes it.
 *
 *   MATCHED         |difference| <= tolerance, both sides complete
 *   MISMATCH        |difference| >  tolerance
 *   INCOMPLETE      a side could not be computed in full (data scope narrower than the tenant, missing mapping / control account)
 *   NOT_APPLICABLE  the module / data for this type is not available to the tenant
 */
final class ReconciliationResult
{
    public const MATCHED = 'MATCHED';

    public const MISMATCH = 'MISMATCH';

    public const INCOMPLETE = 'INCOMPLETE';

    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const AP_GL = 'AP_GL';

    public const AR_GL = 'AR_GL';

    public const CASH_BANK_GL = 'CASH_BANK_GL';

    public const FIXED_ASSET_GL = 'FIXED_ASSET_GL';

    public const TAX_GL = 'TAX_GL';

    public const FX_GL = 'FX_GL';

    public const TYPES = [self::AP_GL, self::AR_GL, self::CASH_BANK_GL, self::FIXED_ASSET_GL, self::TAX_GL, self::FX_GL];

    /**
     * @param  list<array<string,mixed>>  $components  per-component detail, e.g. [{code,label,source_balance,gl_balance,difference,status}]
     * @param  list<string>  $notes  human-readable reasons (why INCOMPLETE / NOT_APPLICABLE, what was compared)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $asOf,
        public readonly string $status,
        public readonly ?string $sourceBalance,
        public readonly ?string $glBalance,
        public readonly ?string $difference,
        public readonly string $tolerance = '0.0000',
        public readonly bool $complete = true,
        public readonly array $components = [],
        public readonly array $notes = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type, 'as_of' => $this->asOf, 'status' => $this->status,
            'source_balance' => $this->sourceBalance, 'gl_balance' => $this->glBalance, 'difference' => $this->difference,
            'tolerance' => $this->tolerance, 'complete' => $this->complete, 'components' => $this->components, 'notes' => $this->notes,
        ];
    }
}
