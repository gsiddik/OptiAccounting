<?php

namespace App\Domain\Reconciliation\Services\Adapters;

use App\Domain\Accounting\Support\Money;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use Brick\Math\BigDecimal;

/**
 * One reconciliation type of the central engine. An adapter wraps the existing subledger / register services of its module (it never
 * recomputes subledger maths itself), compares the source with the general ledger at ONE cut-off date and answers a ReconciliationResult.
 * The engine has already established that the module is entitled and that the caller sees the whole tenant, so an adapter only decides
 * between MATCHED, MISMATCH, INCOMPLETE (a control account or mapping is missing) and NOT_APPLICABLE (no data to reconcile).
 *
 * Component convention (shared by every type): `{code, label, source_balance, gl_balance, difference, status, informational}` with
 * `difference = source - gl`. A compared component is MATCHED when |difference| <= tolerance, otherwise MISMATCH; an informational one
 * (an opening balance, a bank statement) is shown beside the comparison, carries status INFORMATIONAL and never decides the result.
 * Extra keys (`reference`, `breakdown`, ...) describe what a component is made of. Nothing here ever adjusts a balance.
 */
abstract class ReconciliationAdapter
{
    public const INFORMATIONAL = 'INFORMATIONAL';

    abstract public function type(): string;

    /** The module whose entitlement exposes this type (ModuleAvailability code). */
    abstract public function module(): string;

    /** Compare both sides as of $asOf (Y-m-d, end of day). The caller's data scope is tenant-wide. */
    abstract public function reconcile(string $asOf, BigDecimal $tolerance): ReconciliationResult;

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    protected function component(string $code, string $label, ?BigDecimal $source, ?BigDecimal $gl, BigDecimal $tolerance, bool $informational = false, array $extra = []): array
    {
        $difference = $source !== null && $gl !== null ? $source->minus($gl) : null;
        $status = $informational || $difference === null ? self::INFORMATIONAL : ($this->within($difference, $tolerance) ? ReconciliationResult::MATCHED : ReconciliationResult::MISMATCH);

        return [
            'code' => $code, 'label' => $label,
            'source_balance' => $source === null ? null : Money::str($source), 'gl_balance' => $gl === null ? null : Money::str($gl),
            'difference' => $difference === null ? null : Money::str($difference), 'status' => $status, 'informational' => $informational,
        ] + $extra;
    }

    protected function within(BigDecimal $difference, BigDecimal $tolerance): bool
    {
        return ! $difference->abs()->isGreaterThan($tolerance);
    }

    /**
     * MATCHED or MISMATCH from the compared components (a mismatching one decides), with the headline figures the type chooses.
     *
     * @param  list<array<string,mixed>>  $components
     * @param  list<string>  $notes
     */
    protected function outcome(string $asOf, BigDecimal $tolerance, ?BigDecimal $source, ?BigDecimal $gl, array $components, array $notes): ReconciliationResult
    {
        $mismatch = collect($components)->contains(fn ($c) => $c['status'] === ReconciliationResult::MISMATCH);
        $difference = $source !== null && $gl !== null ? $source->minus($gl) : null;
        if ($mismatch && $difference !== null && $this->within($difference, $tolerance)) {
            $notes[] = 'The headline figures offset each other within the tolerance, but at least one component does not match; see the components.';
        }

        return new ReconciliationResult(
            $this->type(), $asOf, $mismatch ? ReconciliationResult::MISMATCH : ReconciliationResult::MATCHED,
            $source === null ? null : Money::str($source), $gl === null ? null : Money::str($gl), $difference === null ? null : Money::str($difference),
            Money::str($tolerance), true, $components, $notes,
        );
    }

    /**
     * @param  list<string>  $notes  why the comparison could not be made in full
     */
    protected function incomplete(string $asOf, BigDecimal $tolerance, ?BigDecimal $source, ?BigDecimal $gl, array $notes, array $components = []): ReconciliationResult
    {
        return new ReconciliationResult(
            $this->type(), $asOf, ReconciliationResult::INCOMPLETE, $source === null ? null : Money::str($source), $gl === null ? null : Money::str($gl), null,
            Money::str($tolerance), false, $components, $notes,
        );
    }

    /** @param list<string> $notes */
    protected function notApplicable(string $asOf, BigDecimal $tolerance, array $notes): ReconciliationResult
    {
        return new ReconciliationResult($this->type(), $asOf, ReconciliationResult::NOT_APPLICABLE, null, null, null, Money::str($tolerance), true, [], $notes);
    }
}
