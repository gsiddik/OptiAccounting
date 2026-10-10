<?php

namespace App\Domain\Expense\Services;

use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Expense categories: a tenant's own classification of costs. A category names where its cost lands, either a specific account or a
 * semantic role resolved through the tenant's mapping; with neither, the posting rule's own expense role decides. It carries no
 * debit/credit logic, and a category used by a document is deactivated, never deleted.
 */
class ExpenseCategoryService
{
    /** code => name — a neutral starting set a tenant may apply, rename or ignore. Accounts are the tenant's to choose afterwards. */
    public const DEFAULTS = [
        'TRANSPORT' => 'Transportasi dan perjalanan dinas', 'UTILITIES' => 'Listrik, air dan telekomunikasi', 'OFFICE' => 'Perlengkapan kantor',
        'MEALS' => 'Konsumsi dan jamuan', 'REPAIRS' => 'Perbaikan dan pemeliharaan', 'PROFESSIONAL' => 'Jasa profesional', 'OTHER' => 'Beban lain-lain',
    ];

    public function __construct(
        private readonly AccountGuard $accounts,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(array $data): ExpenseCategory
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data) {
            $category = new ExpenseCategory($this->descriptive($data));
            $category->status = 'ACTIVE';
            $category->forceFill($this->destination($data, null));
            $category->created_by = $this->context->user()?->id;
            $category->save();
            $this->audit->record('expense.category.created', 'expense_category', $category->id, null, $this->summary($category));

            return $category->load('account');
        }));
    }

    public function update(ExpenseCategory $category, array $data): ExpenseCategory
    {
        return $this->guarded(fn () => DB::transaction(function () use ($category, $data) {
            $category = ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id);
            $before = $this->summary($category);
            $category->fill($this->descriptive($data, true));
            $category->forceFill($this->destination($data, $category));
            $category->updated_by = $this->context->user()?->id;
            $category->save();
            $this->audit->record('expense.category.updated', 'expense_category', $category->id, $before, $this->summary($category->refresh()));

            return $category->load('account');
        }));
    }

    public function setStatus(ExpenseCategory $category, string $status): ExpenseCategory
    {
        return DB::transaction(function () use ($category, $status) {
            $category = ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id);
            if ($category->status !== $status) {
                if ($status === 'ACTIVE' && $category->account_id) {
                    $this->accounts->usable($category->account_id, ['EXPENSE', 'ASSET'], false, 'account_id'); // reactivation needs a usable account
                }
                $before = ['status' => $category->status];
                $category->status = $status;
                $category->updated_by = $this->context->user()?->id;
                $category->save();
                $this->audit->record('expense.category.status_changed', 'expense_category', $category->id, $before, ['status' => $status, 'code' => $category->code]);
            }

            return $category->load('account');
        });
    }

    /** A category nobody used can go; a used one is deactivated (the foreign keys refuse the delete). */
    public function delete(ExpenseCategory $category): void
    {
        try {
            DB::transaction(function () use ($category) {
                $category = ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id);
                $category->delete();
                $this->audit->record('expense.category.deleted', 'expense_category', $category->id, $this->summary($category), null);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23503') {
                throw new DomainException('This category is used by expenses or invoice lines; deactivate it instead.', 'EXPENSE_CATEGORY_IN_USE', 409);
            }
            throw $e;
        }
    }

    /** Create the standard set for codes the tenant does not have yet. @return list<ExpenseCategory> the ones created */
    public function applyDefaults(): array
    {
        return DB::transaction(function () {
            $created = [];
            foreach (self::DEFAULTS as $code => $name) {
                if (! ExpenseCategory::query()->where('code', $code)->exists()) {
                    $created[] = $this->create(['code' => $code, 'name' => $name]);
                }
            }

            return $created;
        });
    }

    /** The category an expense may use: it exists for the tenant and is active. */
    public function usable(?string $id, string $field = 'expense_category_id'): ExpenseCategory
    {
        $category = $id === null ? null : ExpenseCategory::query()->find($id);
        if (! $category) {
            throw new DomainException('The expense category does not exist.', 'EXPENSE_CATEGORY_INVALID', 422, ['field' => $field]);
        }
        if ($category->status !== 'ACTIVE') {
            throw new DomainException("Expense category {$category->code} is inactive.", 'EXPENSE_CATEGORY_INACTIVE', 422, ['field' => $field]);
        }

        return $category;
    }

    /** @return array<string,mixed> */
    private function descriptive(array $data, bool $partial = false): array
    {
        $out = collect($data)->only(['code', 'name', 'description'])->all();
        if (isset($out['code'])) {
            $out['code'] = mb_strtoupper(trim($out['code']));
        }
        if (! $partial && (empty($out['code']) || empty($out['name']))) {
            throw new DomainException('The code and name are required.', 'EXPENSE_CATEGORY_INVALID', 422);
        }

        return $out;
    }

    /**
     * The category's destination: an account (an expense or asset account that is not a control account) or a semantic role, never both.
     * Keys the request leaves out keep what the category has.
     *
     * @return array<string,mixed>
     */
    private function destination(array $data, ?ExpenseCategory $existing): array
    {
        $accountId = array_key_exists('account_id', $data) ? $data['account_id'] : $existing?->account_id;
        $role = array_key_exists('account_role', $data) ? $data['account_role'] : $existing?->account_role;
        if (($data['account_id'] ?? null) !== null && ($data['account_role'] ?? null) !== null) {
            throw new DomainException('A category names an account or a role, not both.', 'EXPENSE_CATEGORY_DESTINATION_AMBIGUOUS', 422, ['field' => 'account_id']);
        }
        if (($data['account_id'] ?? null) !== null) {
            $role = null; // naming an account replaces the role the category had
        } elseif (($data['account_role'] ?? null) !== null) {
            $accountId = null;
        }
        if ($accountId !== null && ($existing === null || $accountId !== $existing->account_id)) {
            $accountId = $this->accounts->usable($accountId, ['EXPENSE', 'ASSET'], false, 'account_id')->id;
        }
        if ($role !== null) {
            $this->accounts->destinationRole($role);
        }

        return ['account_id' => $accountId, 'account_role' => $role];
    }

    private function summary(ExpenseCategory $category): array
    {
        return $category->only(['code', 'name', 'account_role', 'account_id', 'status']);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new DomainException('An expense category with this code already exists.', 'EXPENSE_CATEGORY_CODE_TAKEN', 422);
            }
            throw $e;
        }
    }
}
