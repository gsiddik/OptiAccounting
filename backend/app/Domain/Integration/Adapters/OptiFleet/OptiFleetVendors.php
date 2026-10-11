<?php

namespace App\Domain\Integration\Adapters\OptiFleet;

use App\Domain\Integration\Models\ExternalDimension;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Services\VendorService;
use App\Domain\Shared\DomainException;

/**
 * The vendor of a workshop invoice in OptiEntry's own vendor master. The workshop partner of the source is only a reference (an
 * `external_dimensions` row of type VENDOR whose metadata names our vendor); resolution never trusts a name alone:
 *
 *   1. the reference recorded earlier (or by a person) for this source vendor id;
 *   2. a vendor carrying this source vendor id as its external reference, then one with the same tax id (exactly one, else VENDOR_AMBIGUOUS),
 *      then one with the same code unless both sides state different tax ids;
 *   3. otherwise a new vendor (external reference = source system + vendor id), so the next invoice of the same partner finds it at step 1.
 *
 * The reference is refreshed on every resolution. A vendor that is inactive is still the resolved vendor (the AP service then refuses the
 * document and a person reactivates it), never a reason to create a second one.
 */
class OptiFleetVendors
{
    private const ENTITY = 'VENDOR';

    public function __construct(private readonly VendorService $vendors) {}

    /** @param array<string,mixed>|null $source payload.vendor of the event: {id, code, name, tax_id} */
    public function resolve(IntegrationConnection $connection, mixed $source): Vendor
    {
        $id = is_array($source) && is_string($source['id'] ?? null) ? trim($source['id']) : '';
        if ($id === '') {
            throw new DomainException('The workshop invoice names no vendor.', 'VENDOR_MISSING', 422, ['field' => 'vendor.id']);
        }

        $vendor = $this->mapped($connection, $id) ?? $this->matched($id, $source) ?? $this->create($id, $source);
        ExternalDimension::record($connection, self::ENTITY, $id, $this->text($source, 'code', 80), $this->text($source, 'name', 200), ['vendor_id' => $vendor->id]);

        return $vendor;
    }

    private function mapped(IntegrationConnection $connection, string $id): ?Vendor
    {
        $dimension = ExternalDimension::query()->where([
            'source_system' => $connection->source_system, 'source_tenant_reference' => (string) $connection->source_tenant_reference,
            'entity_type' => self::ENTITY, 'external_id' => $id,
        ])->first();
        $vendorId = $dimension?->metadata['vendor_id'] ?? null;

        return is_string($vendorId) ? Vendor::query()->find($vendorId) : null;
    }

    /** @param array<string,mixed> $source */
    private function matched(string $id, array $source): ?Vendor
    {
        $vendor = Vendor::query()->where('external_source', OptiFleetAdapter::SOURCE)->where('external_id', $id)->first();
        $taxId = $this->normalized($this->text($source, 'tax_id', 50));
        if ($vendor === null && $taxId !== '') {
            $byTax = Vendor::query()->whereRaw("lower(regexp_replace(coalesce(tax_id, ''), '[^0-9A-Za-z]', '', 'g')) = ?", [$taxId])->limit(2)->get();
            if ($byTax->count() > 1) {
                throw new DomainException('More than one vendor carries this tax id; map the OptiFleet vendor by hand.', 'VENDOR_AMBIGUOUS', 409, ['tax_id' => $taxId]);
            }
            $vendor = $byTax->first();
        }
        if ($vendor === null && ($code = $this->text($source, 'code', 40)) !== null) {
            $byCode = Vendor::query()->whereRaw('upper(code) = ?', [mb_strtoupper($code)])->first();
            $theirs = $this->normalized($byCode?->tax_id);
            $vendor = $byCode !== null && ($taxId === '' || $theirs === '' || $taxId === $theirs) ? $byCode : null;
        }

        return $vendor;
    }

    /** @param array<string,mixed> $source */
    private function create(string $id, array $source): Vendor
    {
        $code = mb_strtoupper((string) $this->text($source, 'code', 40));
        if ($code === '' || Vendor::query()->where('code', $code)->exists()) {
            $code = 'OF-'.mb_strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $id), 0, 12));
        }

        return $this->vendors->create([
            'code' => $code, 'name' => $this->text($source, 'name', 255) ?? $code, 'tax_id' => $this->text($source, 'tax_id', 50),
            'external_source' => OptiFleetAdapter::SOURCE, 'external_id' => mb_substr($id, 0, 100),
        ]);
    }

    private function text(mixed $source, string $key, int $max): ?string
    {
        $value = is_array($source) && is_string($source[$key] ?? null) ? trim($source[$key]) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function normalized(?string $taxId): string
    {
        return mb_strtolower((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $taxId));
    }
}
