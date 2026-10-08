<?php

namespace App\Domain\Payables\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Vendor master. Identity (who the vendor is) and financial profile (how we pay and book them) are written separately: the profile
 * fields are explicit parameters, validated against the tenant's own terms and accounts, and audited as a financial change.
 * A vendor that has documents is deactivated, never deleted (foreign keys refuse the delete).
 */
class VendorService
{
    /** Financial profile attributes: changing them is audited as `payables.vendor.financial_profile_changed`. */
    private const PROFILE = ['payment_term_id', 'default_currency', 'payable_account_id', 'default_expense_account_id'];

    public function __construct(private readonly AuditService $audit, private readonly AccountGuard $accounts, private readonly TenantContext $context) {}

    public function query(array $filter = []): Builder
    {
        return Vendor::query()->with(['paymentTerm:id,code,name', 'payableAccount', 'defaultExpenseAccount'])
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['payment_term_id'] ?? null, fn ($q, $v) => $q->where('payment_term_id', $v))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(legal_name, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(tax_id, \'\')) like ?', [$like]));
            })
            ->orderBy('code');
    }

    public function create(array $data): Vendor
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data) {
            $vendor = new Vendor($this->identity($data));
            $vendor->status = Vendor::ACTIVE;
            $this->applyProfile($vendor, $data);
            $vendor->created_by = $this->context->user()?->id;
            $vendor->save();
            $this->audit->record('payables.vendor.created', 'vendor', $vendor->id, null, $this->summary($vendor));

            return $vendor->load(['paymentTerm:id,code,name', 'payableAccount', 'defaultExpenseAccount']);
        }));
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        return $this->guarded(fn () => DB::transaction(function () use ($vendor, $data) {
            $vendor = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            $before = $this->summary($vendor);
            $beforeProfile = $vendor->only(self::PROFILE);

            $vendor->fill($this->identity($data, partial: true));
            $this->applyProfile($vendor, $data);
            $vendor->updated_by = $this->context->user()?->id;
            $vendor->save();

            $this->audit->record('payables.vendor.updated', 'vendor', $vendor->id, $before, $this->summary($vendor));
            if ($vendor->only(self::PROFILE) !== $beforeProfile) {
                $this->audit->record('payables.vendor.financial_profile_changed', 'vendor', $vendor->id, $beforeProfile, $vendor->only(self::PROFILE) + ['code' => $vendor->code]);
            }

            return $vendor->load(['paymentTerm:id,code,name', 'payableAccount', 'defaultExpenseAccount']);
        }));
    }

    public function setStatus(Vendor $vendor, string $status): Vendor
    {
        return DB::transaction(function () use ($vendor, $status) {
            $vendor = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            if ($vendor->status !== $status) {
                $vendor->status = $status;
                $vendor->updated_by = $this->context->user()?->id;
                $vendor->save();
                $this->audit->record('payables.vendor.status_changed', 'vendor', $vendor->id, ['status' => $status === Vendor::ACTIVE ? Vendor::INACTIVE : Vendor::ACTIVE], ['status' => $status, 'code' => $vendor->code]);
            }

            return $vendor;
        });
    }

    public function delete(Vendor $vendor): void
    {
        try {
            DB::transaction(function () use ($vendor) {
                $vendor = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
                $vendor->delete();
                $this->audit->record('payables.vendor.deleted', 'vendor', $vendor->id, $this->summary($vendor), null);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23503') {
                throw new DomainException('This vendor has documents; deactivate it instead of deleting it.', 'VENDOR_IN_USE', 409);
            }
            throw $e;
        }
    }

    /** A vendor can receive new documents only while ACTIVE. */
    public function assertUsable(Vendor $vendor): void
    {
        if ($vendor->status !== Vendor::ACTIVE) {
            throw new DomainException("Vendor {$vendor->code} is inactive.", 'VENDOR_INACTIVE', 422, ['vendor' => $vendor->code]);
        }
    }

    /** @return array<string,mixed> */
    private function identity(array $data, bool $partial = false): array
    {
        if (isset($data['code'])) {
            $data['code'] = mb_strtoupper(trim($data['code']));
        }
        foreach (['email'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = mb_strtolower(trim($data[$key]));
            }
        }
        if (! $partial || array_key_exists('external_id', $data) || array_key_exists('external_source', $data)) {
            if (($data['external_source'] ?? null) !== null xor ($data['external_id'] ?? null) !== null) {
                throw new DomainException('An external reference needs both the source system and the id.', 'VENDOR_EXTERNAL_REFERENCE_INVALID', 422);
            }
        }

        return collect($data)->only((new Vendor)->getFillable())->all();
    }

    /** Validate and assign the financial profile fields that are present in $data (absent = unchanged, null = cleared). */
    private function applyProfile(Vendor $vendor, array $data): void
    {
        if (array_key_exists('payment_term_id', $data)) {
            $term = $data['payment_term_id'] === null ? null : (PaymentTerm::query()->find($data['payment_term_id'])
                ?? throw new DomainException('The payment term does not exist.', 'PAYMENT_TERM_NOT_FOUND', 422, ['field' => 'payment_term_id']));
            if ($term && $term->status !== 'ACTIVE' && $vendor->payment_term_id !== $term->id) {
                throw new DomainException('The payment term is inactive.', 'PAYMENT_TERM_INACTIVE', 422, ['field' => 'payment_term_id']);
            }
            $vendor->payment_term_id = $term?->id;
        }
        if (array_key_exists('default_currency', $data)) {
            $currency = $data['default_currency'];
            $functional = AccountingProfile::query()->value('functional_currency');
            if ($currency !== null && $functional !== null && $currency !== $functional) {
                throw new DomainException("OA2 books in the functional currency ({$functional}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'default_currency']);
            }
            $vendor->default_currency = $currency;
        }
        if (array_key_exists('payable_account_id', $data)) {
            $vendor->payable_account_id = $data['payable_account_id'] === null ? null
                : $this->accounts->usable($data['payable_account_id'], ['LIABILITY'], true, 'payable_account_id')->id;
        }
        if (array_key_exists('default_expense_account_id', $data)) {
            $vendor->default_expense_account_id = $data['default_expense_account_id'] === null ? null
                : $this->accounts->usable($data['default_expense_account_id'], ['EXPENSE', 'ASSET'], false, 'default_expense_account_id')->id;
        }
    }

    private function summary(Vendor $vendor): array
    {
        return $vendor->only(['code', 'name', 'legal_name', 'status', 'tax_id', 'tax_registered']);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                $external = str_contains($e->getMessage(), 'vendors_external_unique');

                throw new DomainException($external ? 'Another vendor already carries this external reference.' : 'A vendor with this code already exists.', $external ? 'VENDOR_EXTERNAL_REFERENCE_TAKEN' : 'VENDOR_CODE_TAKEN', 422);
            }
            throw $e;
        }
    }
}
