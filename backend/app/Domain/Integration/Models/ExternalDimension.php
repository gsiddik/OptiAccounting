<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A reference to something the source system owns (vehicle, work order, workshop, vendor...): code and name for display and filtering, never
 * a copy of the source record and never a foreign key. Adapters keep it current through `record`.
 */
class ExternalDimension extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** Create or refresh the reference of one source entity of a connection. */
    public static function record(IntegrationConnection $connection, string $entityType, string $externalId, ?string $code = null, ?string $name = null, array $metadata = []): self
    {
        $key = [
            'source_system' => $connection->source_system, 'source_tenant_reference' => (string) $connection->source_tenant_reference,
            'entity_type' => $entityType, 'external_id' => $externalId,
        ];
        $dimension = static::query()->where($key)->first() ?? (new static)->forceFill($key);
        $dimension->forceFill(['code' => $code, 'name' => $name, 'metadata' => $metadata === [] ? null : $metadata])->save();

        return $dimension;
    }
}
