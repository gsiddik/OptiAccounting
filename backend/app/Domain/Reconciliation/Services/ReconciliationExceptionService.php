<?php

namespace App\Domain\Reconciliation\Services;

use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Reconciliation\Models\ReconciliationException;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The human side of a reconciliation exception (OA5 batch F). A person can assign an owner, explain the difference and ask for it to be
 * resolved. None of that changes the calculated result: `explain` and `assign` touch only the work item, and `resolve` is accepted only when a
 * fresh recomputation of the same (type, as_of) shows MATCHED (it goes through ReconciliationRunService, which keeps the evidence); otherwise
 * 409 RECON_STILL_MISMATCHED and the exception stays open with the numbers of that recomputation. There is no road to MATCHED by hand.
 * A RESOLVED exception is history; a new mismatch of the same (type, as_of) opens a new one.
 */
class ReconciliationExceptionService
{
    public function __construct(
        private readonly ReconciliationRunService $runs,
        private readonly AccountingScope $scope,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param array<string,mixed> $filter status (OPEN|EXPLAINED|RESOLVED|ACTIVE), calculated_status, type, as_of_from, as_of_to, owner_id, mine */
    public function query(array $filter = []): Builder
    {
        return ReconciliationException::query()->with(['period', 'owner'])
            ->when($filter['status'] ?? null, fn ($q, $v) => $v === 'ACTIVE' ? $q->where('status', '<>', ReconciliationException::RESOLVED) : $q->where('status', $v))
            ->when($filter['calculated_status'] ?? null, fn ($q, $v) => $q->where('calculated_status', $v))
            ->when($filter['type'] ?? null, fn ($q, $v) => $q->where('reconciliation_type', $v))
            ->when($filter['as_of_from'] ?? null, fn ($q, $v) => $q->whereDate('as_of_date', '>=', $v))
            ->when($filter['as_of_to'] ?? null, fn ($q, $v) => $q->whereDate('as_of_date', '<=', $v))
            ->when($filter['owner_id'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($filter['mine'] ?? null, fn ($q) => $q->where('owner_id', $this->context->user()?->id))
            ->orderByRaw("case status when 'OPEN' then 0 when 'EXPLAINED' then 1 else 2 end")->orderByDesc('as_of_date')->orderBy('reconciliation_type');
    }

    /** The exception of this tenant, or 404 (another tenant's exception does not exist for the caller). */
    public function find(string $id, bool $lock = false): ReconciliationException
    {
        $exception = Str::isUuid($id) ? ReconciliationException::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id) : null;

        return $exception ?? throw new DomainException('Reconciliation exception not found.', 'RECONCILIATION_EXCEPTION_NOT_FOUND', 404);
    }

    public function assign(string $id, string $ownerId): ReconciliationException
    {
        return DB::transaction(function () use ($id, $ownerId) {
            $exception = $this->workable($id);
            if (! Str::isUuid($ownerId) || ! TenantUser::query()->where('user_id', $ownerId)->where('status', TenantUser::ACTIVE)->exists()
                || ! DB::table('users')->where('id', $ownerId)->where('status', 'ACTIVE')->exists()) {
                throw new DomainException('The owner must be an active member of this organization.', 'RECON_OWNER_INVALID', 422, ['field' => 'owner_id']);
            }
            $before = ['owner_id' => $exception->owner_id];
            $exception->forceFill(['owner_id' => $ownerId])->save();
            $this->audit->record('accounting.reconciliation.exception.assigned', 'reconciliation_exception', $exception->id, $before, ['owner_id' => $ownerId]);

            return $exception->load(['period', 'owner']);
        });
    }

    /** Record why the difference exists. The calculated status and the numbers are not touched: only a recomputation moves them. */
    public function explain(string $id, string $notes): ReconciliationException
    {
        return DB::transaction(function () use ($id, $notes) {
            $exception = $this->workable($id);
            $before = ['status' => $exception->status, 'notes' => $exception->notes];
            $exception->forceFill([
                'notes' => $notes, 'status' => ReconciliationException::EXPLAINED, 'explained_by' => $this->context->user()?->id, 'explained_at' => now(),
            ])->save();
            $this->audit->record('accounting.reconciliation.exception.explained', 'reconciliation_exception', $exception->id, $before, [
                'status' => $exception->status, 'notes' => $notes, 'calculated_status' => $exception->calculated_status,
            ]);

            return $exception->load(['period', 'owner']);
        });
    }

    /** Resolve only after a fresh recomputation of the same type and cut-off shows MATCHED. */
    public function resolve(string $id, string $note): ReconciliationException
    {
        $exception = $this->find($id);
        if ($exception->status === ReconciliationException::RESOLVED) {
            throw new DomainException('This exception is already resolved.', 'RECON_EXCEPTION_RESOLVED', 409);
        }
        $user = $this->context->user();
        ['result' => $result] = $this->runs->run(
            $exception->reconciliation_type, $exception->as_of_date->toDateString(), periodId: $exception->accounting_period_id, resolution: ['by' => $user?->id, 'note' => $note],
        );
        if ($result->status !== ReconciliationResult::MATCHED) {
            throw new DomainException('The reconciliation still does not match, so the exception cannot be resolved. Correct the cause in the ledger or the subledger; an explanation does not change the result.', 'RECON_STILL_MISMATCHED', 409, [
                'type' => $exception->reconciliation_type, 'as_of' => $exception->as_of_date->toDateString(), 'status' => $result->status, 'difference' => $result->difference,
            ]);
        }

        return $this->find($id)->load(['period', 'owner']);
    }

    /** An exception that is still open for work (a resolved one is history), locked for the change. */
    private function workable(string $id): ReconciliationException
    {
        $exception = $this->find($id, lock: true);
        if ($exception->status === ReconciliationException::RESOLVED) {
            throw new DomainException('This exception is already resolved.', 'RECON_EXCEPTION_RESOLVED', 409);
        }

        return $exception;
    }

    /**
     * The API form: every column plus `type` and `period`. A caller whose data scope is narrower than the tenant gets no amounts (the exception
     * holds tenant-wide figures).
     *
     * @return array<string,mixed>
     */
    public function present(ReconciliationException $exception): array
    {
        $row = $exception->toArray();
        unset($row['owner'], $row['period']);
        if (! $this->scope->isTenantWide()) {
            $row['source_balance'] = $row['gl_balance'] = $row['difference'] = null;
        }

        return $row + [
            'type' => $exception->reconciliation_type,
            'period' => $exception->period === null ? null : ['id' => $exception->period->id, 'code' => $exception->period->code, 'start_date' => $exception->period->start_date->toDateString(), 'end_date' => $exception->period->end_date->toDateString()],
            'owner' => $exception->owner === null ? null : ['id' => $exception->owner->id, 'name' => $exception->owner->name],
        ];
    }
}
