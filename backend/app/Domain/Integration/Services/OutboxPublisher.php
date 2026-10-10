<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Optinexus\OptinexusSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional outbox: call inside the DB transaction of the change an event describes, so the event exists
 * if and only if the change committed. Delivery is asynchronous and idempotent (the row id is the event id).
 * A row is written per active channel; OptiNexus is the only channel until OA6 adds integration consumers.
 */
class OutboxPublisher
{
    /** Registered in the OptiNexus event catalog under the original application code; the OptiEntry rename keeps it. */
    public const EVENT_PREFIX = 'optiaccounting.';

    public const CHANNEL_OPTINEXUS = 'OPTINEXUS';

    /** @return list<string> */
    public function channels(): array
    {
        return OptinexusSettings::active() ? [self::CHANNEL_OPTINEXUS] : [];
    }

    /** A business event, e.g. event('tenant.linked', $tenantId, [...]) => `optiaccounting.tenant.linked`. */
    public function event(string $type, ?string $tenantId, array $data): void
    {
        $this->write('EVENT', self::EVENT_PREFIX.$type, $tenantId, $data);
    }

    /**
     * A security-relevant record for the receiver's audit trail (the action keeps its own namespace, e.g. `sso.login`).
     *
     * @param  array{resource_type?:?string,resource_id?:?string,actor_identity?:?string,old_value?:?array,new_value?:?array,metadata?:?array}  $details
     */
    public function audit(string $action, ?string $tenantId, array $details = []): void
    {
        $this->write('AUDIT', $action, $tenantId, ['action' => $action] + $details);
    }

    private function write(string $kind, string $type, ?string $tenantId, array $payload): void
    {
        foreach ($this->channels() as $channel) {
            DB::table('outbox_events')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'channel' => $channel,
                'kind' => $kind,
                'event_type' => $type,
                'schema_version' => '1',
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => now(),
                'status' => 'PENDING',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
