<?php

namespace App\Domain\Integration\Adapters\OptiDeli;

use App\Domain\Integration\Models\ExternalDimension;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Receivables\Models\Customer;
use App\Domain\Receivables\Services\CustomerService;
use App\Domain\Shared\DomainException;

/**
 * The customer of an OptiDeli invoice in OptiEntry's own customer master. OptiDeli has no customer master: an order carries a customer
 * name, so the name (trimmed, case-folded) is the reference. Resolution:
 *   1. the mapping recorded earlier (or by a person, who can re-point it) for this name: an `external_dimensions` row of type CUSTOMER;
 *   2. a customer carrying this reference as its external id, then the one customer whose name is the same (several = CUSTOMER_AMBIGUOUS);
 *   3. otherwise a new customer, so the next invoice for the same name finds it at step 1.
 * An inactive customer is still the resolved one (the AR service then refuses the invoice and a person reactivates it).
 */
class OptiDeliCustomers
{
    private const ENTITY = 'CUSTOMER';

    public function __construct(private readonly CustomerService $customers) {}

    public function resolve(IntegrationConnection $connection, mixed $name): Customer
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            throw new DomainException('The invoice names no customer.', 'CUSTOMER_MISSING', 422, ['field' => 'customer_name']);
        }
        $reference = 'name:'.substr(hash('sha256', mb_strtolower($name)), 0, 32);

        $customer = $this->mapped($connection, $reference) ?? $this->matched($reference, $name) ?? $this->create($reference, $name);
        ExternalDimension::record($connection, self::ENTITY, $reference, null, mb_substr($name, 0, 200), ['customer_id' => $customer->id]);

        return $customer;
    }

    private function mapped(IntegrationConnection $connection, string $reference): ?Customer
    {
        $dimension = ExternalDimension::query()->where([
            'source_system' => $connection->source_system, 'source_tenant_reference' => (string) $connection->source_tenant_reference,
            'entity_type' => self::ENTITY, 'external_id' => $reference,
        ])->first();
        $id = $dimension?->metadata['customer_id'] ?? null;

        return is_string($id) ? Customer::query()->find($id) : null;
    }

    private function matched(string $reference, string $name): ?Customer
    {
        $byReference = Customer::query()->where('external_source', OptiDeliAdapter::SOURCE)->where('external_id', $reference)->first();
        if ($byReference !== null) {
            return $byReference;
        }
        $byName = Customer::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->limit(2)->get();
        if ($byName->count() > 1) {
            throw new DomainException('More than one customer carries this name; map the OptiDeli customer by hand.', 'CUSTOMER_AMBIGUOUS', 409, ['name' => $name]);
        }

        return $byName->first();
    }

    private function create(string $reference, string $name): Customer
    {
        $code = 'OD-'.mb_strtoupper(substr($reference, 5, 10));
        if (Customer::query()->where('code', $code)->exists()) {
            $code = 'OD-'.mb_strtoupper(substr($reference, 5, 16));
        }

        return $this->customers->create([
            'code' => $code, 'name' => mb_substr($name, 0, 255), 'external_source' => OptiDeliAdapter::SOURCE, 'external_id' => $reference,
        ]);
    }
}
