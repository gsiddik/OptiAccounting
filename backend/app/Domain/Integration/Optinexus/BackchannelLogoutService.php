<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Services\OutboxPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Applies a verified OptiNexus logout token (docs/architecture/OPTINEXUS_ADAPTER.md §6): ends every session of the
 * user, and for access-revoked also deactivates the account (scope user) or the membership of the named tenant
 * (scope tenant), marked as done by OptiNexus so that the next successful sign-in undoes exactly that and nothing else.
 * An unknown user is not an error (the caller answers 200 so a repeated call is harmless).
 */
class BackchannelLogoutService
{
    public function __construct(
        private readonly AccessCache $cache,
        private readonly AuditService $audit,
        private readonly OutboxPublisher $outbox,
    ) {}

    /** @param  array<string,mixed>  $claims  verified logout token claims */
    public function apply(array $claims): void
    {
        $user = User::query()->where('optinexus_subject', (string) $claims['sub'])->first();
        if (! $user) {
            return;
        }

        $revoked = $claims['events'][OidcClient::EVENT_ACCESS_REVOKED] ?? null;

        DB::transaction(function () use ($user, $revoked) {
            DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->delete();

            if (! is_array($revoked)) {
                $this->audit->record('auth.sso_logout', 'user', $user->id);

                return;
            }

            $reason = (string) ($revoked['reason'] ?? 'access_revoked');
            $tenant = null;

            if (($revoked['scope'] ?? 'user') === 'tenant') {
                $tenant = Tenant::query()->where('optinexus_tenant_id', (string) ($revoked['tenant_id'] ?? ''))->first();
                if ($tenant) {
                    $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', TenantUser::ACTIVE)->first();
                    $membership?->forceFill(['status' => TenantUser::INACTIVE, 'optinexus_deactivated_at' => now()])->save();
                    $this->cache->touchTenant($tenant->id);
                    $this->outbox->event('membership.deactivated', $tenant->id, ['user_subject' => $user->optinexus_subject, 'reason' => $reason]);
                }
            } elseif ($user->status === User::ACTIVE) {
                $user->forceFill(['status' => User::INACTIVE, 'optinexus_deactivated_at' => now()])->save();
                foreach (TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->pluck('tenant_id') as $tenantId) {
                    $this->cache->touchTenant($tenantId);
                }
            }

            $this->audit->record('auth.sso_access_revoked', 'user', $user->id, null, ['reason' => $reason, 'scope' => $revoked['scope'] ?? 'user'], $tenant?->id);
            $this->outbox->audit('sso.access_revoked', $tenant?->id, ['resource_type' => 'user', 'resource_id' => (string) $user->optinexus_subject, 'metadata' => ['reason' => $reason]]);
        });
    }
}
