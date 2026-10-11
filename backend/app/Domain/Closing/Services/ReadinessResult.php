<?php

namespace App\Domain\Closing\Services;

/**
 * The outcome of the close checks of one period at one moment (OA5 design doc 6). Nothing here is stored as truth: it is computed live
 * from the ledger, and a copy of `evidence()` is kept in the append-only closing history to record what the actor saw.
 *
 * Each check is {code, title, severity BLOCK|WARN, result PASS|FAIL|WARN|SKIPPED, message, details}. The result is READY when no BLOCK
 * check failed; WARN findings never stop a close. A check that was switched off or whose module is not entitled is SKIPPED, never PASS.
 */
final class ReadinessResult
{
    public const READY = 'READY';

    public const NOT_READY = 'NOT_READY';

    public const SOFT = 'SOFT';

    public const HARD = 'HARD';

    /** @param list<array{code:string,title:string,severity:string,result:string,message:string,details:array<string,mixed>}> $checks */
    public function __construct(
        public readonly string $periodId,
        public readonly string $periodCode,
        public readonly string $target,
        public readonly array $checks,
        public readonly string $evaluatedAt,
    ) {}

    public function result(): string
    {
        return $this->blocking() === [] ? self::READY : self::NOT_READY;
    }

    public function ready(): bool
    {
        return $this->result() === self::READY;
    }

    /** @return list<array<string,mixed>> the failing BLOCK checks */
    public function blocking(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => $c['severity'] === ClosingPolicyService::BLOCK && $c['result'] === 'FAIL'));
    }

    /** @return list<array<string,mixed>> */
    public function warnings(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => $c['result'] === 'WARN'));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'period_id' => $this->periodId, 'period_code' => $this->periodCode, 'target' => $this->target, 'result' => $this->result(),
            'blocking_count' => count($this->blocking()), 'warning_count' => count($this->warnings()), 'evaluated_at' => $this->evaluatedAt, 'checks' => $this->checks,
        ];
    }

    /** @return list<array<string,mixed>> what the closing history keeps: the checks as the actor saw them */
    public function evidence(): array
    {
        return $this->checks;
    }
}
