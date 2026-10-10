<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Receivables\Models\Customer;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Customer master. Identity (who the customer is) and financial profile (how we bill and book them) are written separately: the profile
 * fields are explicit parameters, validated against the tenant's own terms and accounts, and audited as a financial change.
 * A customer that has documents is deactivated, never deleted (foreign keys refuse the delete).
 */
class CustomerService
{
    /** Financial profile attributes: changing them is audited as `receivables.customer.financial_profile_changed`. */
    private const PROFILE = ['payment_term_id', 'default_currency', 'receivable_account_id', 'default_revenue_account_id', 'credit_limit'];

    public function __construct(private readonly AuditService $audit, private readonly AccountGuard $accounts, private readonly TenantContext $context) {}

    public function query(array $filter = []): Builder
    {
        return Customer::query()->with(['paymentTerm:id,code,name', 'receivableAccount', 'defaultRevenueAccount'])
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['payment_term_id'] ?? null, fn ($q, $v) => $q->where('payment_term_id', $v))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(legal_name, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(tax_id, \'\')) like ?', [$like]));
            })
            ->orderBy('code');
    }

    public function create(array $data): Customer
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data) {
            $customer = new Customer($this->identity($data));
            $customer->status = Customer::ACTIVE;
            $this->applyProfile($customer, $data);
            $customer->created_by = $this->context->user()?->id;
            $customer->save();
            $this->audit->record('receivables.customer.created', 'customer', $customer->id, null, $this->summary($customer));

            return $customer->load(['paymentTerm:id,code,name', 'receivableAccount', 'defaultRevenueAccount']);
        }));
    }

    public function update(Customer $customer, array $data): Customer
    {
        return $this->guarded(fn () => DB::transaction(function () use ($customer, $data) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $before = $this->summary($customer);
            $beforeProfile = $customer->only(self::PROFILE);

            $customer->fill($this->identity($data, partial: true));
            $this->applyProfile($customer, $data);
            $customer->updated_by = $this->context->user()?->id;
            $customer->save();

            $this->audit->record('receivables.customer.updated', 'customer', $customer->id, $before, $this->summary($customer));
            if ($customer->only(self::PROFILE) !== $beforeProfile) {
                $this->audit->record('receivables.customer.financial_profile_changed', 'customer', $customer->id, $beforeProfile, $customer->only(self::PROFILE) + ['code' => $customer->code]);
            }

            return $customer->load(['paymentTerm:id,code,name', 'receivableAccount', 'defaultRevenueAccount']);
        }));
    }

    public function setStatus(Customer $customer, string $status): Customer
    {
        return DB::transaction(function () use ($customer, $status) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status !== $status) {
                $customer->status = $status;
                $customer->updated_by = $this->context->user()?->id;
                $customer->save();
                $this->audit->record('receivables.customer.status_changed', 'customer', $customer->id, ['status' => $status === Customer::ACTIVE ? Customer::INACTIVE : Customer::ACTIVE], ['status' => $status, 'code' => $customer->code]);
            }

            return $customer;
        });
    }

    public function delete(Customer $customer): void
    {
        try {
            DB::transaction(function () use ($customer) {
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                $customer->delete();
                $this->audit->record('receivables.customer.deleted', 'customer', $customer->id, $this->summary($customer), null);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23503') {
                throw new DomainException('This customer has documents; deactivate it instead of deleting it.', 'CUSTOMER_IN_USE', 409);
            }
            throw $e;
        }
    }

    /** A customer can receive new documents only while ACTIVE. */
    public function assertUsable(Customer $customer): void
    {
        if ($customer->status !== Customer::ACTIVE) {
            throw new DomainException("Customer {$customer->code} is inactive.", 'CUSTOMER_INACTIVE', 422, ['customer' => $customer->code]);
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
                throw new DomainException('An external reference needs both the source system and the id.', 'CUSTOMER_EXTERNAL_REFERENCE_INVALID', 422);
            }
        }

        return collect($data)->only((new Customer)->getFillable())->all();
    }

    /** Validate and assign the financial profile fields that are present in $data (absent = unchanged, null = cleared). */
    private function applyProfile(Customer $customer, array $data): void
    {
        if (array_key_exists('payment_term_id', $data)) {
            $term = $data['payment_term_id'] === null ? null : (PaymentTerm::query()->find($data['payment_term_id'])
                ?? throw new DomainException('The payment term does not exist.', 'PAYMENT_TERM_NOT_FOUND', 422, ['field' => 'payment_term_id']));
            if ($term && $term->status !== 'ACTIVE' && $customer->payment_term_id !== $term->id) {
                throw new DomainException('The payment term is inactive.', 'PAYMENT_TERM_INACTIVE', 422, ['field' => 'payment_term_id']);
            }
            $customer->payment_term_id = $term?->id;
        }
        if (array_key_exists('default_currency', $data)) {
            $currency = $data['default_currency'];
            $functional = AccountingProfile::query()->value('functional_currency');
            if ($currency !== null && $functional !== null && $currency !== $functional) {
                throw new DomainException("OA3 books in the functional currency ({$functional}) only.", 'CURRENCY_NOT_SUPPORTED', 422, ['field' => 'default_currency']);
            }
            $customer->default_currency = $currency;
        }
        if (array_key_exists('credit_limit', $data)) {
            // Metadata only: it is shown next to the receivable, never enforced by OA3.
            $customer->credit_limit = $data['credit_limit'] === null ? null : Money::str(Money::parse($data['credit_limit'], 4, 'credit_limit'));
        }
        if (array_key_exists('receivable_account_id', $data)) {
            $customer->receivable_account_id = $data['receivable_account_id'] === null ? null
                : $this->accounts->usable($data['receivable_account_id'], ['ASSET'], true, 'receivable_account_id')->id;
        }
        if (array_key_exists('default_revenue_account_id', $data)) {
            $customer->default_revenue_account_id = $data['default_revenue_account_id'] === null ? null
                : $this->accounts->usable($data['default_revenue_account_id'], ['REVENUE'], false, 'default_revenue_account_id')->id;
        }
    }

    private function summary(Customer $customer): array
    {
        return $customer->only(['code', 'name', 'legal_name', 'status', 'tax_id', 'tax_registered']);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                $external = str_contains($e->getMessage(), 'customers_external_unique');

                throw new DomainException($external ? 'Another customer already carries this external reference.' : 'A customer with this code already exists.', $external ? 'CUSTOMER_EXTERNAL_REFERENCE_TAKEN' : 'CUSTOMER_CODE_TAKEN', 422);
            }
            throw $e;
        }
    }
}
