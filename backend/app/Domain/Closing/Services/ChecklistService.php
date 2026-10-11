<?php

namespace App\Domain\Closing\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Closing\Models\ClosingChecklistItem;
use App\Domain\Closing\Models\ClosingChecklistTemplate;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The closing checklist. Two kinds of entries, deliberately kept apart:
 *
 *   system checks   computed live by CloseReadinessService; read-only here, a person can never mark one PASS
 *   manual items    steps a person performs (review the accruals, sign the pack ...): PENDING -> COMPLETED | WAIVED, a waiver needs a reason
 *
 * Templates (tenant configuration) describe the manual items. A period's items are generated idempotently from the ACTIVE templates the
 * first time someone acts on the checklist; until then the listing shows them as virtual PENDING entries, and the readiness check counts a
 * required template without an item as pending, so skipping the generation never lets a close through. An item row snapshots the title and
 * the required flag, so editing a template never rewrites the checklist of a period that already has it.
 *
 * Once a period is CLOSED its checklist is evidence and cannot change. Item changes lock the period FOR SHARE, so a hard close that is
 * in flight (FOR UPDATE) and a checklist change cannot interleave.
 */
class ChecklistService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantContext $context,
        private readonly CloseReadinessService $readiness,
    ) {}

    // ------------------------------------------------------------------------------------------ templates

    /** @return Collection<int,ClosingChecklistTemplate> */
    public function templates(): Collection
    {
        return ClosingChecklistTemplate::query()->orderBy('sort_order')->orderBy('code')->get();
    }

    /** @param array{code:string,title:string,description?:?string,required?:bool,sort_order?:int,status?:string} $data */
    public function createTemplate(array $data): ClosingChecklistTemplate
    {
        $code = strtoupper($data['code']);
        $this->assertCodeFree($code);

        try {
            return DB::transaction(function () use ($data, $code) {
                $template = new ClosingChecklistTemplate(['code' => $code] + Arr::except($data, ['status']));
                $template->status = $data['status'] ?? ClosingChecklistTemplate::ACTIVE; // the lifecycle column is never mass-assigned
                $template->save();
                $this->audit->record('accounting.closing.policy.updated', 'closing_checklist_template', $template->id, null, ['action' => 'template_created'] + $template->only(['code', 'title', 'required', 'sort_order', 'status']));

                return $template->refresh();
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new DomainException('A checklist template with this code exists.', 'CHECKLIST_CODE_TAKEN', 422, ['code' => $code]);
            }
            throw $e;
        }
    }

    /** The code is the item's identity in every period and never changes. @param array<string,mixed> $data */
    public function updateTemplate(ClosingChecklistTemplate $template, array $data): ClosingChecklistTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $template = ClosingChecklistTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $before = $template->only(['title', 'description', 'required', 'sort_order', 'status']);
            $template->fill(array_diff_key($data, ['code' => 1, 'status' => 1]));
            if (isset($data['status'])) {
                $template->status = $data['status'];
            }
            $template->save();
            $after = $template->only(['title', 'description', 'required', 'sort_order', 'status']);
            if ($before !== $after) {
                $this->audit->record('accounting.closing.policy.updated', 'closing_checklist_template', $template->id, ['action' => 'template_updated'] + $before, ['action' => 'template_updated', 'code' => $template->code] + $after);
            }

            return $template;
        });
    }

    /** A template that produced an item stays as history (deactivate it instead). */
    public function deleteTemplate(ClosingChecklistTemplate $template): void
    {
        DB::transaction(function () use ($template) {
            $template = ClosingChecklistTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if (ClosingChecklistItem::query()->where('template_id', $template->id)->exists()) {
                throw new DomainException('This template already produced checklist items; deactivate it instead.', 'CHECKLIST_TEMPLATE_IN_USE', 409);
            }
            $template->delete();
            $this->audit->record('accounting.closing.policy.updated', 'closing_checklist_template', $template->id, ['action' => 'template_deleted', 'code' => $template->code, 'title' => $template->title], null);
        });
    }

    // ------------------------------------------------------------------------------------------ a period's checklist

    /**
     * @return array{period:array<string,mixed>,system_checks:list<array<string,mixed>>,manual_items:list<array<string,mixed>>,summary:array<string,int>}
     */
    public function view(AccountingPeriod $period): array
    {
        $readiness = $this->readiness->evaluate($period);
        $items = ClosingChecklistItem::query()->where('accounting_period_id', $period->id)->get()->keyBy('code');
        $manual = $items->map(fn (ClosingChecklistItem $i) => $this->present($i))->all();

        if ($period->status !== AccountingPeriod::CLOSED) {
            foreach (ClosingChecklistTemplate::query()->where('status', ClosingChecklistTemplate::ACTIVE)->get() as $template) {
                if (! $items->has($template->code)) {
                    $manual[$template->code] = [
                        'id' => null, 'code' => $template->code, 'title' => $template->title, 'required' => $template->required, 'sort_order' => $template->sort_order,
                        'status' => ClosingChecklistItem::PENDING, 'generated' => false, 'notes' => null, 'completed_by' => null, 'completed_at' => null,
                        'waived_by' => null, 'waived_at' => null, 'waive_reason' => null,
                    ];
                }
            }
        }
        $manual = collect($manual)->sortBy([['sort_order', 'asc'], ['code', 'asc']])->values()->all();

        return [
            'period' => ['id' => $period->id, 'code' => $period->code, 'status' => $period->status],
            'system_checks' => array_map(fn ($c) => $c + ['settable' => false], $readiness->checks),
            'manual_items' => $manual,
            'summary' => [
                'required' => count(array_filter($manual, fn ($i) => $i['required'])),
                'pending_required' => count(array_filter($manual, fn ($i) => $i['required'] && $i['status'] === ClosingChecklistItem::PENDING)),
                'completed' => count(array_filter($manual, fn ($i) => $i['status'] === ClosingChecklistItem::COMPLETED)),
                'waived' => count(array_filter($manual, fn ($i) => $i['status'] === ClosingChecklistItem::WAIVED)),
            ],
        ];
    }

    public function complete(AccountingPeriod $period, string $code, ?string $notes = null): ClosingChecklistItem
    {
        return $this->change($period, $code, 'completed', function (ClosingChecklistItem $item) use ($notes) {
            $this->assertPending($item);
            $item->forceFill([
                'status' => ClosingChecklistItem::COMPLETED, 'notes' => $notes, 'completed_by' => $this->context->user()?->id, 'completed_at' => now(),
                'waived_by' => null, 'waived_at' => null, 'waive_reason' => null,
            ]);
        });
    }

    public function waive(AccountingPeriod $period, string $code, string $reason): ClosingChecklistItem
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A waiver needs a reason.', 'CHECKLIST_REASON_REQUIRED', 422);
        }

        return $this->change($period, $code, 'waived', function (ClosingChecklistItem $item) use ($reason) {
            $this->assertPending($item);
            $item->forceFill([
                'status' => ClosingChecklistItem::WAIVED, 'waived_by' => $this->context->user()?->id, 'waived_at' => now(), 'waive_reason' => $reason,
                'completed_by' => null, 'completed_at' => null,
            ]);
        });
    }

    /** COMPLETED or WAIVED back to PENDING, with the audit trail keeping what it was. */
    public function reset(AccountingPeriod $period, string $code): ClosingChecklistItem
    {
        return $this->change($period, $code, 'reset', function (ClosingChecklistItem $item) {
            if ($item->status === ClosingChecklistItem::PENDING) {
                throw new DomainException('The item is already pending.', 'CHECKLIST_ITEM_PENDING', 409);
            }
            $item->forceFill([
                'status' => ClosingChecklistItem::PENDING, 'completed_by' => null, 'completed_at' => null, 'waived_by' => null, 'waived_at' => null, 'waive_reason' => null,
            ]);
        });
    }

    // ------------------------------------------------------------------------------------------ internals

    private function change(AccountingPeriod $period, string $code, string $action, callable $apply): ClosingChecklistItem
    {
        $this->readiness->assertFullScope();
        $code = strtoupper($code);
        if (isset(ClosingPolicyService::CHECKS[$code])) {
            throw new DomainException('A system check is computed from the ledger and cannot be set by hand.', 'CHECKLIST_SYSTEM_CHECK', 422, ['code' => $code]);
        }

        return DB::transaction(function () use ($period, $code, $action, $apply) {
            $period = AccountingPeriod::query()->sharedLock()->findOrFail($period->id);
            if ($period->status === AccountingPeriod::CLOSED) {
                throw new DomainException('The checklist of a closed period is evidence and cannot change; reopen the period first.', 'CHECKLIST_PERIOD_CLOSED', 409, ['period' => $period->code]);
            }

            $this->generate($period);
            $item = ClosingChecklistItem::query()->where('accounting_period_id', $period->id)->where('code', $code)->lockForUpdate()->first()
                ?? throw new DomainException('This checklist item does not exist.', 'CHECKLIST_ITEM_NOT_FOUND', 404, ['code' => $code]);

            $before = $item->only(['status', 'notes', 'waive_reason']);
            $apply($item);
            $item->save();
            $this->audit->record("accounting.closing.checklist.{$action}", 'closing_checklist_item', $item->id, $before, [
                'period' => $period->code, 'code' => $item->code, 'status' => $item->status, 'notes' => $item->notes, 'waive_reason' => $item->waive_reason,
            ]);

            return $item->refresh();
        });
    }

    /** Insert the items the ACTIVE templates call for and the period lacks (idempotent: the unique key makes a replay a no-op). */
    private function generate(AccountingPeriod $period): void
    {
        $rows = ClosingChecklistTemplate::query()->where('status', ClosingChecklistTemplate::ACTIVE)->get()->map(fn (ClosingChecklistTemplate $t) => [
            'id' => (string) Str::uuid7(), 'tenant_id' => $period->tenant_id, 'accounting_period_id' => $period->id, 'template_id' => $t->id, 'code' => $t->code, 'title' => $t->title,
            'required' => $t->required, 'sort_order' => $t->sort_order, 'status' => ClosingChecklistItem::PENDING, 'created_at' => now(), 'updated_at' => now(),
        ])->all();
        if ($rows !== []) {
            DB::table('closing_checklist_items')->insertOrIgnore($rows);
        }
    }

    private function assertPending(ClosingChecklistItem $item): void
    {
        if ($item->status !== ClosingChecklistItem::PENDING) {
            throw new DomainException("The item is already {$item->status}; reset it first.", 'CHECKLIST_ITEM_NOT_PENDING', 409, ['status' => $item->status]);
        }
    }

    private function assertCodeFree(string $code): void
    {
        if (isset(ClosingPolicyService::CHECKS[$code])) {
            throw new DomainException('This code belongs to a system check.', 'CHECKLIST_CODE_RESERVED', 422, ['code' => $code]);
        }
    }

    /** @return array<string,mixed> */
    private function present(ClosingChecklistItem $item): array
    {
        return [
            'id' => $item->id, 'code' => $item->code, 'title' => $item->title, 'required' => $item->required, 'sort_order' => $item->sort_order, 'status' => $item->status,
            'generated' => true, 'notes' => $item->notes, 'completed_by' => $item->completed_by, 'completed_at' => $item->completed_at?->toIso8601String(),
            'waived_by' => $item->waived_by, 'waived_at' => $item->waived_at?->toIso8601String(), 'waive_reason' => $item->waive_reason,
        ];
    }
}
