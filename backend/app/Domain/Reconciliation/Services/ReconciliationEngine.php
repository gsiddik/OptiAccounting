<?php

namespace App\Domain\Reconciliation\Services;

use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Accounting\Support\Money;
use App\Domain\Entitlement\Services\ModuleAvailability;
use App\Domain\Reconciliation\Services\Adapters\ApGlReconciliation;
use App\Domain\Reconciliation\Services\Adapters\ArGlReconciliation;
use App\Domain\Reconciliation\Services\Adapters\CashBankGlReconciliation;
use App\Domain\Reconciliation\Services\Adapters\FixedAssetGlReconciliation;
use App\Domain\Reconciliation\Services\Adapters\FxGlReconciliation;
use App\Domain\Reconciliation\Services\Adapters\ReconciliationAdapter;
use App\Domain\Reconciliation\Services\Adapters\TaxGlReconciliation;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Central reconciliation framework (OA5 batch F). CONTRACT used by close readiness (Closing), the dashboard and the exports: every method
 * recomputes from authoritative data at one explicit cut-off date and reads the tenant from TenantContext. Nothing is persisted here
 * (ReconciliationRunService keeps the evidence), and nothing a person did can change the answer: the status is derived from the difference alone.
 *
 * One adapter per type of ReconciliationResult::TYPES wraps the module's own reconciliation; both of its sides use the SAME cut-off (`$asOf`,
 * end of that day; a period is just its end date). The engine decides what no adapter should:
 *
 *   NOT_APPLICABLE  the module of the type is not entitled (ModuleAvailability): the type exposes nothing, not even a figure;
 *   INCOMPLETE      the caller's data scope is narrower than the tenant: a reconciliation over part of the ledger would be a lie, so no figure is
 *                   given at all (a branch user cannot learn tenant-wide balances through it); adapters add INCOMPLETE for a missing control account;
 *   MISMATCH        |difference| > tolerance (closing_settings.reconciliation_tolerance, strict zero when the tenant never set one).
 *
 * Reads outside a caller's transaction run in one REPEATABLE READ snapshot, so postings that commit while the sides are being read cannot make
 * the two sides disagree; inside a caller's transaction (close readiness, which holds the ledger gate) the caller's snapshot rules.
 */
class ReconciliationEngine
{
    /** @var array<string,ReconciliationAdapter> */
    private array $adapters = [];

    public function __construct(
        ApGlReconciliation $ap,
        ArGlReconciliation $ar,
        CashBankGlReconciliation $cashBank,
        FixedAssetGlReconciliation $fixedAsset,
        TaxGlReconciliation $tax,
        FxGlReconciliation $fx,
        private readonly ModuleAvailability $modules,
        private readonly AccountingScope $scope,
        private readonly TenantContext $context,
    ) {
        foreach ([$ap, $ar, $cashBank, $fixedAsset, $tax, $fx] as $adapter) {
            $this->adapters[$adapter->type()] = $adapter;
        }
    }

    /** Live computation, nothing persisted. A type whose module / data is unavailable returns NOT_APPLICABLE; a narrower data scope returns INCOMPLETE. */
    public function compute(string $type, string $asOf): ReconciliationResult
    {
        $adapter = $this->adapter($type);
        $this->assertDate($asOf);

        return $this->snapshot(fn () => $this->evaluate($adapter, $asOf));
    }

    /** @return array<string,ReconciliationResult> every ReconciliationResult::TYPES entry, keyed by type */
    public function computeAll(string $asOf): array
    {
        $this->assertDate($asOf);

        return $this->snapshot(function () use ($asOf) {
            $results = [];
            foreach (ReconciliationResult::TYPES as $type) {
                $results[$type] = $this->evaluate($this->adapter($type), $asOf);
            }

            return $results;
        });
    }

    /** @return list<string> the types whose module the tenant is entitled to, in ReconciliationResult::TYPES order */
    public function availableTypes(): array
    {
        return array_values(array_filter(ReconciliationResult::TYPES, fn (string $type) => $this->available($type)));
    }

    public function available(string $type): bool
    {
        return $this->modules->active($this->adapter($type)->module());
    }

    /** The module that exposes a type (for the route layer's entitlement answer). */
    public function moduleOf(string $type): string
    {
        return $this->adapter($type)->module();
    }

    /** The absolute difference a tenant accepts as equal; strict zero until the closing settings name another. */
    public function tolerance(): BigDecimal
    {
        $value = DB::table('closing_settings')->where('tenant_id', $this->context->tenantId())->value('reconciliation_tolerance');

        return BigDecimal::of((string) ($value ?? '0'))->toScale(4);
    }

    /** `ap-gl` for AP_GL: the form of a type in URLs. */
    public static function slug(string $type): string
    {
        return strtolower(str_replace('_', '-', $type));
    }

    /** The type named by a URL slug, or null. */
    public static function typeOfSlug(string $slug): ?string
    {
        $type = strtoupper(str_replace('-', '_', $slug));

        return in_array($type, ReconciliationResult::TYPES, true) ? $type : null;
    }

    private function evaluate(ReconciliationAdapter $adapter, string $asOf): ReconciliationResult
    {
        $tolerance = $this->tolerance();
        if (! $this->modules->active($adapter->module())) {
            return new ReconciliationResult($adapter->type(), $asOf, ReconciliationResult::NOT_APPLICABLE, null, null, null, Money::str($tolerance), true, [], [
                'The module of this reconciliation is not available to the organization.',
            ]);
        }
        if (! $this->scope->isTenantWide()) {
            return new ReconciliationResult($adapter->type(), $asOf, ReconciliationResult::INCOMPLETE, null, null, null, Money::str($tolerance), false, [], [
                'Your data scope is narrower than the whole organization. A reconciliation over part of the ledger would not be a reconciliation, so no figure is given; run it with a tenant-wide data scope.',
            ]);
        }

        return $adapter->reconcile($asOf, $tolerance);
    }

    private function adapter(string $type): ReconciliationAdapter
    {
        return $this->adapters[$type] ?? throw new DomainException('Unknown reconciliation type.', 'RECONCILIATION_TYPE_UNKNOWN', 404, ['type' => $type]);
    }

    private function assertDate(string $asOf): void
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $asOf, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new DomainException('The as_of date must be a valid date (YYYY-MM-DD).', 'AS_OF_INVALID', 422, ['field' => 'as_of']);
        }
    }

    /** One snapshot for all reads of a computation, unless the caller already is inside a transaction. */
    private function snapshot(callable $work): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $work();
        }

        return DB::transaction(function () use ($work) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $work();
        });
    }
}
