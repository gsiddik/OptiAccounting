<?php

namespace App\Domain\Audit\Services;

use App\Support\Database\Micros;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends audit records. Call it inside the same DB transaction as the change it
 * describes. Secrets are stripped from before/after snapshots.
 */
class AuditService
{
    private const SECRET_KEY = '/pass|secret|token|authorization|api_key|credential/i';

    public function __construct(private readonly TenantContext $context) {}

    public function record(
        string $action,
        string $resourceType,
        ?string $resourceId,
        ?array $before = null,
        ?array $after = null,
        ?string $tenantId = null,
    ): void {
        $user = $this->context->user();
        $tenantId ??= $this->context->tenantId();
        $request = request();

        $changes = ($before !== null || $after !== null)
            ? ['before' => $this->clean($before), 'after' => $this->clean($after)]
            : null;

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'actor_user_id' => $user?->id,
            'actor_scope' => $user === null ? 'system' : ($this->context->isPlatform() ? 'platform' : 'tenant'),
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'context' => json_encode(array_filter([
                'request_id' => $request?->attributes->get('request_id') ?? $request?->header('X-Request-Id'),
                'ip' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 200, ''),
            ])),
            'occurred_at' => Micros::now(),
        ]);
    }

    private function clean(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->clean($value) : $value;
        }

        return $clean;
    }
}
