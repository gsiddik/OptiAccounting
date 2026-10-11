<?php

namespace App\Domain\Reconciliation\Services;

use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Reconciliation\Models\ReconciliationException;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The persistent side of a reconciliation (OA5 batch F). `run` computes live through the engine, keeps what came out as an append-only
 * `reconciliation_runs` row (evidence of what a person saw, never read back as truth) and keeps the work item in step:
 *
 *   MISMATCH / INCOMPLETE   the live `reconciliation_exceptions` row of (type, as_of) is opened or refreshed with the NEW numbers every time;
 *   MATCHED                 a live exception is RESOLVED, automatically with a system note (or by the person whose `resolve` caused this run);
 *   narrower data scope     evidence only: the run has no figures, so the exception keeps the numbers of the last full run;
 *   NOT_APPLICABLE          evidence only (a type whose module is not entitled is refused: 403 MODULE_NOT_ENTITLED, nothing is stored).
 *
 * The only road to RESOLVED is a recomputation that shows MATCHED; no caller can pass a status. Runs of the same (type, as_of) serialize on an
 * advisory lock so two simultaneous runs cannot open two exceptions (the partial unique index is the second line of defence).
 */
class ReconciliationRunService
{
    public function __construct(
        private readonly ReconciliationEngine $engine,
        private readonly AccountingScope $scope,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{by:?string,note:string}|null  $resolution  who resolves the live exception and why, when this run is the verification of a person's `resolve`
     * @return array{run:ReconciliationRun,exception:?ReconciliationException,result:ReconciliationResult}
     */
    public function run(string $type, string $asOf, string $purpose = ReconciliationRun::MANUAL, ?string $periodId = null, ?array $resolution = null): array
    {
        if (! in_array($purpose, ReconciliationRun::PURPOSES, true)) {
            throw new DomainException('The purpose of a run must be MANUAL or CLOSE_CHECK.', 'RECONCILIATION_PURPOSE_INVALID', 422, ['field' => 'purpose']);
        }
        $result = $this->engine->compute($type, $asOf);
        if ($result->status === ReconciliationResult::NOT_APPLICABLE && ! $this->engine->available($type)) {
            throw new DomainException('This reconciliation is not available to the organization.', 'MODULE_NOT_ENTITLED', 403, ['type' => $type]);
        }

        return DB::transaction(function () use ($type, $asOf, $purpose, $periodId, $result, $resolution) {
            $tenant = (string) $this->context->tenantId();
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["oa5:reconciliation:{$tenant}:{$type}:{$asOf}"]);

            $run = new ReconciliationRun;
            $run->forceFill([
                'reconciliation_type' => $type, 'as_of_date' => $asOf, 'accounting_period_id' => $periodId, 'status' => $result->status,
                'source_balance' => $result->sourceBalance, 'gl_balance' => $result->glBalance, 'difference' => $result->difference, 'tolerance' => $result->tolerance,
                'scope_complete' => $result->complete, 'purpose' => $purpose, 'components' => $result->components, 'requested_by' => $this->context->user()?->id, 'run_at' => now(),
            ])->save();
            $this->audit->record('accounting.reconciliation.run', 'reconciliation_run', $run->id, null, [
                'type' => $type, 'as_of' => $asOf, 'status' => $result->status, 'source_balance' => $result->sourceBalance, 'gl_balance' => $result->glBalance,
                'difference' => $result->difference, 'tolerance' => $result->tolerance, 'purpose' => $purpose, 'complete' => $result->complete,
            ]);

            return ['run' => $run, 'exception' => $this->syncException($run, $result, $periodId, $resolution), 'result' => $result];
        });
    }

    private function syncException(ReconciliationRun $run, ReconciliationResult $result, ?string $periodId, ?array $resolution): ?ReconciliationException
    {
        $live = ReconciliationException::query()->where('reconciliation_type', $run->reconciliation_type)->whereDate('as_of_date', $run->as_of_date->toDateString())
            ->where('status', '<>', ReconciliationException::RESOLVED)->lockForUpdate()->first();
        if (! $this->scope->isTenantWide() || $result->status === ReconciliationResult::NOT_APPLICABLE) {
            return $live; // a run without figures cannot say anything about the work item
        }

        if ($result->status === ReconciliationResult::MATCHED) {
            return $live === null ? null : $this->resolveLive($live, $run, $resolution);
        }

        $numbers = [
            'source_balance' => $result->sourceBalance, 'gl_balance' => $result->glBalance, 'difference' => $result->difference,
            'calculated_status' => $result->status, 'last_run_id' => $run->id,
        ];
        if ($live !== null) {
            $live->forceFill($numbers + ['accounting_period_id' => $periodId ?? $live->accounting_period_id])->save();

            return $live;
        }
        $exception = new ReconciliationException;
        $exception->forceFill($numbers + [
            'reconciliation_type' => $run->reconciliation_type, 'as_of_date' => $run->as_of_date, 'accounting_period_id' => $periodId,
            'status' => ReconciliationException::OPEN, 'opened_by' => $this->context->user()?->id,
        ])->save();
        $this->audit->record('accounting.reconciliation.exception.opened', 'reconciliation_exception', $exception->id, null, [
            'type' => $exception->reconciliation_type, 'as_of' => $run->as_of_date->toDateString(), 'calculated_status' => $result->status, 'difference' => $result->difference, 'run_id' => $run->id,
        ]);

        return $exception;
    }

    /** The recomputation shows MATCHED: the work item is done, by the person who asked for the verification or by the system. */
    private function resolveLive(ReconciliationException $live, ReconciliationRun $run, ?array $resolution): ReconciliationException
    {
        $before = ['status' => $live->status, 'calculated_status' => $live->calculated_status, 'difference' => $live->difference];
        $live->forceFill([
            'source_balance' => $run->source_balance, 'gl_balance' => $run->gl_balance, 'difference' => $run->difference, 'calculated_status' => ReconciliationResult::MATCHED, 'last_run_id' => $run->id,
            'status' => ReconciliationException::RESOLVED, 'resolved_at' => now(), 'resolved_by' => $resolution['by'] ?? null,
            'resolution_note' => $resolution['note'] ?? "Resolved automatically: a later run recomputed the reconciliation as MATCHED (run {$run->id}).",
        ])->save();
        $this->audit->record('accounting.reconciliation.exception.resolved', 'reconciliation_exception', $live->id, $before, [
            'status' => ReconciliationException::RESOLVED, 'calculated_status' => ReconciliationResult::MATCHED, 'automatic' => $resolution === null, 'run_id' => $run->id, 'resolution_note' => $live->resolution_note,
        ]);

        return $live;
    }

    /** @param array<string,mixed> $filter type, status, purpose, as_of_from, as_of_to */
    public function query(array $filter = []): Builder
    {
        return ReconciliationRun::query()->with('period')
            ->when($filter['type'] ?? null, fn ($q, $v) => $q->where('reconciliation_type', $v))
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))
            ->when($filter['as_of_from'] ?? null, fn ($q, $v) => $q->whereDate('as_of_date', '>=', $v))
            ->when($filter['as_of_to'] ?? null, fn ($q, $v) => $q->whereDate('as_of_date', '<=', $v))
            ->orderByDesc('run_at')->orderByDesc('id');
    }

    /**
     * The API form of a run. A run kept by a tenant-wide user holds tenant-wide figures, so a caller whose data scope is narrower sees the
     * fact of the run (type, date, status, who) without amounts or components.
     *
     * @return array<string,mixed>
     */
    public function present(ReconciliationRun $run): array
    {
        $row = $run->toArray();
        unset($row['period']);
        if (! $this->scope->isTenantWide()) {
            $row['source_balance'] = $row['gl_balance'] = $row['difference'] = null;
            $row['components'] = null;
        }

        return $row + [
            'type' => $run->reconciliation_type,
            'period' => $run->period === null ? null : ['id' => $run->period->id, 'code' => $run->period->code, 'start_date' => $run->period->start_date->toDateString(), 'end_date' => $run->period->end_date->toDateString()],
        ];
    }
}
