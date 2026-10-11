<?php

namespace App\Domain\FixedAsset\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\FixedAsset\Models\AssetCategory;
use App\Domain\FixedAsset\Models\FixedAsset;
use App\Domain\FixedAsset\Services\Depreciation\DepreciationMethods;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Asset categories: the defaults a new asset starts from and the accounts it books to. A category carries no debit/credit logic. Its
 * accounts are optional overrides of the role mapping; changing them (or anything else) never touches a capitalized asset, because the
 * asset froze its own accounts and terms when it was capitalized. A category that assets use is deactivated, never deleted.
 */
class AssetCategoryService
{
    private const ACCOUNT_FIELDS = [
        'asset_account_id' => [['ASSET'], 'asset account'],
        'accumulated_account_id' => [['ASSET'], 'accumulated depreciation account'],
        'expense_account_id' => [['EXPENSE'], 'depreciation expense account'],
        'gain_loss_account_id' => [['REVENUE', 'EXPENSE'], 'disposal gain or loss account'],
    ];

    public function __construct(private readonly AccountGuard $accounts, private readonly AuditService $audit) {}

    public function query(array $filter = []): Builder
    {
        return AssetCategory::query()->with(['assetAccount', 'accumulatedAccount', 'expenseAccount', 'gainLossAccount'])
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]));
            })
            ->orderBy('code');
    }

    public function load(AssetCategory $category): AssetCategory
    {
        return $category->load(['assetAccount', 'accumulatedAccount', 'expenseAccount', 'gainLossAccount']);
    }

    public function create(array $data, User $actor): AssetCategory
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data, $actor) {
            $category = new AssetCategory;
            $category->forceFill($this->prepare($data, null));
            $category->status = AssetCategory::ACTIVE;
            $category->created_by = $actor->id;
            $category->save();
            $this->audit->record('fixed_asset.category.created', 'asset_category', $category->id, null, $this->summary($category));

            return $this->load($category->refresh());
        }));
    }

    public function update(AssetCategory $category, array $data): AssetCategory
    {
        return $this->guarded(fn () => DB::transaction(function () use ($category, $data) {
            $category = AssetCategory::query()->lockForUpdate()->findOrFail($category->id);
            $before = $this->summary($category);
            $category->forceFill($this->prepare($data, $category))->save();
            $this->audit->record('fixed_asset.category.updated', 'asset_category', $category->id, $before, $this->summary($category->refresh()));

            return $this->load($category);
        }));
    }

    public function setStatus(AssetCategory $category, string $status): AssetCategory
    {
        return DB::transaction(function () use ($category, $status) {
            $category = AssetCategory::query()->lockForUpdate()->findOrFail($category->id);
            if ($category->status !== $status) {
                if ($status === AssetCategory::ACTIVE) {
                    $this->validateAccounts($category->only(array_keys(self::ACCOUNT_FIELDS))); // reactivation needs usable accounts
                }
                $before = ['status' => $category->status];
                $category->status = $status;
                $category->save();
                $this->audit->record('fixed_asset.category.status_changed', 'asset_category', $category->id, $before, ['status' => $status, 'code' => $category->code]);
            }

            return $this->load($category);
        });
    }

    public function delete(AssetCategory $category): void
    {
        try {
            DB::transaction(function () use ($category) {
                $category = AssetCategory::query()->lockForUpdate()->findOrFail($category->id);
                if (FixedAsset::query()->where('asset_category_id', $category->id)->exists()) {
                    throw new DomainException('This category is used by assets; deactivate it instead.', 'ASSET_CATEGORY_IN_USE', 409);
                }
                $category->delete();
                $this->audit->record('fixed_asset.category.deleted', 'asset_category', $category->id, $this->summary($category), null);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23503') {
                throw new DomainException('This category is used by assets; deactivate it instead.', 'ASSET_CATEGORY_IN_USE', 409);
            }
            throw $e;
        }
    }

    /** The category a new asset may use: it exists for the tenant and is active. */
    public function usable(?string $id): AssetCategory
    {
        $category = $id === null ? null : AssetCategory::query()->find($id);
        if (! $category) {
            throw new DomainException('The asset category does not exist.', 'ASSET_CATEGORY_NOT_FOUND', 422, ['field' => 'asset_category_id']);
        }
        if ($category->status !== AssetCategory::ACTIVE) {
            throw new DomainException("Asset category {$category->code} is inactive.", 'ASSET_CATEGORY_INACTIVE', 422, ['field' => 'asset_category_id']);
        }

        return $category;
    }

    /** @return array<string,mixed> the columns to write; keys the request leaves out keep what the category has */
    private function prepare(array $data, ?AssetCategory $existing): array
    {
        $out = [];
        foreach (['code', 'name', 'description'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $key === 'code' ? mb_strtoupper(trim((string) $data[$key])) : $data[$key];
            }
        }
        if ($existing === null && (empty($out['code']) || empty($out['name']))) {
            throw new DomainException('The code and name are required.', 'ASSET_CATEGORY_INVALID', 422);
        }
        if (isset($out['code']) && ! preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/', $out['code'])) {
            throw new DomainException('The code may contain letters, digits, hyphen and underscore only.', 'ASSET_CATEGORY_CODE_INVALID', 422, ['field' => 'code']);
        }

        $method = $data['default_method'] ?? $existing?->default_method ?? 'STRAIGHT_LINE';
        $strategy = DepreciationMethods::for($method);
        $life = array_key_exists('default_useful_life_months', $data) ? $data['default_useful_life_months'] : $existing?->default_useful_life_months;
        if (! $strategy->needsLife()) {
            $life = null;
        } elseif ($life !== null && ((int) $life < 1 || (int) $life > 1200)) {
            throw new DomainException('The useful life must be between 1 and 1200 months.', 'ASSET_LIFE_INVALID', 422, ['field' => 'default_useful_life_months']);
        }
        $policy = $data['default_start_policy'] ?? $existing?->default_start_policy ?? 'CAPITALIZATION_MONTH';
        if (! in_array($policy, ['CAPITALIZATION_MONTH', 'NEXT_MONTH'], true)) {
            throw new DomainException('The start policy is not supported.', 'ASSET_START_POLICY_INVALID', 422, ['field' => 'default_start_policy']);
        }
        $out += ['default_method' => $method, 'default_useful_life_months' => $life === null ? null : (int) $life, 'default_start_policy' => $policy] + $this->residual($data, $existing);

        $accounts = [];
        foreach (array_keys(self::ACCOUNT_FIELDS) as $field) {
            $accounts[$field] = array_key_exists($field, $data) ? $data[$field] : $existing?->{$field};
        }
        $changed = array_filter($accounts, fn ($id, $field) => $id !== null && ($existing === null || $id !== $existing->{$field}), ARRAY_FILTER_USE_BOTH);
        $this->validateAccounts($changed);

        return $out + $accounts;
    }

    /** @return array{default_residual_type:string,default_residual_value:string} */
    private function residual(array $data, ?AssetCategory $existing): array
    {
        $type = $data['default_residual_type'] ?? $existing?->default_residual_type ?? 'NONE';
        if (! in_array($type, ['NONE', 'AMOUNT', 'PERCENT'], true)) {
            throw new DomainException('The residual policy is not supported.', 'ASSET_RESIDUAL_POLICY_INVALID', 422, ['field' => 'default_residual_type']);
        }
        $scale = (int) (AccountingProfile::query()->value('currency_scale') ?? 2);
        $value = Money::parse($data['default_residual_value'] ?? ($type === 'NONE' ? '0' : $existing?->default_residual_value), $type === 'PERCENT' ? 4 : $scale, 'default_residual_value');
        if ($type === 'NONE' && ! $value->isZero()) {
            throw new DomainException('A category without a residual policy has no residual value.', 'ASSET_RESIDUAL_POLICY_INVALID', 422, ['field' => 'default_residual_value']);
        }
        if ($type === 'PERCENT' && $value->isGreaterThan(BigDecimal::of(100))) {
            throw new DomainException('A residual percentage cannot exceed 100.', 'ASSET_RESIDUAL_POLICY_INVALID', 422, ['field' => 'default_residual_value']);
        }

        return ['default_residual_type' => $type, 'default_residual_value' => Money::str($value)];
    }

    private function validateAccounts(array $accountIds): void
    {
        foreach ($accountIds as $field => $id) {
            if ($id !== null) {
                $this->accounts->usable($id, self::ACCOUNT_FIELDS[$field][0], false, $field);
            }
        }
    }

    private function summary(AssetCategory $category): array
    {
        return $category->only(['code', 'name', 'status', 'default_method', 'default_useful_life_months', 'default_residual_type', 'default_residual_value', 'default_start_policy',
            'asset_account_id', 'accumulated_account_id', 'expense_account_id', 'gain_loss_account_id']);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new DomainException('An asset category with this code already exists.', 'ASSET_CATEGORY_CODE_TAKEN', 422);
            }
            throw $e;
        }
    }
}
