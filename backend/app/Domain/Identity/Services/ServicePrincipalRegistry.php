<?php

namespace App\Domain\Identity\Services;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\ServicePrincipal;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is a machine, not a person (ecosystem audit A23). The registry is the only source of that decision: a user takes no seat of the
 * tenant's user limit if and only if a LIVE row of service_principals names it. Rows come from exactly three places, none of them a tenant:
 * the platform operator's `optientry:service-principal:issue` command, the platform API (permission platform.service_principal.manage) and,
 * once, the migration that mapped the per-tenant OptiFleet users of earlier releases. A person who could create an account with a
 * reserved address gets neither a seat exemption nor a principal: addresses of the range are refused for people (ReservedAddress).
 *
 * What a principal is: a user without password (it can never sign in or obtain a token), an ACTIVE membership of its one tenant, a TENANT
 * data scope and no role. Revoking it is final and switches the identity off (membership and user INACTIVE); posted history that names
 * the user is untouched.
 */
class ServicePrincipalRegistry
{
    public const VIA = ['CLI', 'PLATFORM_API', 'SEEDER'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditService $audit,
        private readonly AccessCache $cache,
    ) {}

    /** The live principal of a kind in a tenant, if the operator has issued one. */
    public function live(string $tenantId, string $kind): ?ServicePrincipal
    {
        return ServicePrincipal::query()->live()->where('tenant_id', $tenantId)->where('kind', $kind)->first();
    }

    public function hasLive(string $tenantId, string $kind): bool
    {
        return ServicePrincipal::query()->live()->where('tenant_id', $tenantId)->where('kind', $kind)->exists();
    }

    /** Any principal (live or revoked) behind this user: such a user is never a person. */
    public function forUser(string $userId): ?ServicePrincipal
    {
        return ServicePrincipal::query()->where('user_id', $userId)->first();
    }

    /** Sub-query of the user ids that take no seat in a tenant. For CapacityService. */
    public function seatExemptUserIds(string $tenantId): Builder
    {
        return DB::table('service_principals')->select('user_id')->where('tenant_id', $tenantId)->whereNull('revoked_at');
    }

    /** @return Collection<int,ServicePrincipal> */
    public function list(?string $tenantId = null, ?bool $live = null): Collection
    {
        return ServicePrincipal::query()->with(['user:id,email,status', 'tenant:id,code,name'])
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($live === true, fn ($q) => $q->whereNull('revoked_at'))
            ->when($live === false, fn ($q) => $q->whereNotNull('revoked_at'))
            ->orderByDesc('issued_at')->get();
    }

    /** People who hold an address of the reserved range without a registry row (left over from before the registry): they take a seat. @return Collection<int,object> */
    public function unregisteredReservedUsers(): Collection
    {
        return DB::table('users')->whereRaw('optientry_reserved_address(email)')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('service_principals')->whereColumn('service_principals.user_id', 'users.id'))
            ->orderBy('email')->get(['id', 'email', 'status']);
    }

    /**
     * Issues the identity (idempotent: a tenant has at most one live principal per kind; asking again returns it).
     *
     * @param  string  $via  CLI | PLATFORM_API | SEEDER
     * @return array{principal:ServicePrincipal,created:bool}
     */
    public function issue(Tenant $tenant, string $kind, string $reason, string $via, ?User $by = null, ?string $byLabel = null): array
    {
        $definition = ServicePrincipalKinds::definition($kind);
        $reason = $this->reason($reason);
        if (! in_array($via, self::VIA, true)) {
            throw new DomainException('A service principal is issued from the operator command or the platform API only.', 'SERVICE_PRINCIPAL_VIA_INVALID', 422);
        }

        return DB::transaction(function () use ($tenant, $kind, $definition, $reason, $via, $by, $byLabel) {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->value('id'); // two operators issuing at once are serialised here

            if ($live = $this->live($tenant->id, $kind)) {
                return ['principal' => $live, 'created' => false];
            }

            $ordinal = ServicePrincipal::query()->where('tenant_id', $tenant->id)->where('kind', $kind)->count() + 1;
            do { // the address of an earlier (revoked) principal, or of a person of the range, is never reused
                $email = ServicePrincipalKinds::address($kind, $tenant->code, $ordinal);
                $taken = User::query()->whereRaw('lower(email) = ?', [$email])->exists();
                $ordinal += $taken ? 1 : 0;
            } while ($taken);

            $user = ReservedAddress::whileIssuing(function () use ($definition, $email) {
                $user = new User(['name' => $definition['name'], 'email' => $email]);
                $user->status = User::ACTIVE; // no password: a principal can never sign in

                return tap($user)->save();
            });

            $this->context->runAs($tenant->id, function () use ($tenant, $user) {
                $membership = new TenantUser;
                $membership->tenant_id = $tenant->id;
                $membership->user_id = $user->id;
                $membership->status = TenantUser::ACTIVE;
                $membership->joined_at = now();
                $membership->save();

                $scope = new DataScope;
                $scope->tenant_id = $tenant->id;
                $scope->tenant_user_id = $membership->id;
                $scope->scope_type = DataScope::TENANT; // it sees the documents it prepares and uses the tenant's cash/bank account
                $scope->save();
            });

            $principal = new ServicePrincipal;
            $principal->forceFill([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'kind' => $kind, 'source_system' => $definition['source_system'], 'issued_via' => $via,
                'issued_by' => $by?->id, 'issued_by_label' => $byLabel ?? $by?->email, 'reason' => $reason, 'issued_at' => now(),
            ])->save();

            $this->cache->touchTenant($tenant->id);
            $this->audit->record('service_principal.issued', 'service_principal', $principal->id, null, [
                'kind' => $kind, 'user_id' => $user->id, 'email' => $email, 'via' => $via, 'issued_by' => $principal->issued_by_label, 'reason' => $reason,
            ], $tenant->id);

            return ['principal' => $principal->refresh(), 'created' => true];
        });
    }

    /**
     * Revokes the identity for good: the principal stops being usable, its membership and user are switched off, and a new principal needs a
     * new issue (with a new user). Idempotent.
     *
     * @return array{principal:ServicePrincipal,changed:bool}
     */
    public function revoke(ServicePrincipal $principal, string $reason, ?User $by = null, ?string $byLabel = null): array
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($principal, $reason, $by, $byLabel) {
            $locked = ServicePrincipal::query()->lockForUpdate()->findOrFail($principal->id);
            if ($locked->isRevoked()) {
                return ['principal' => $locked, 'changed' => false];
            }

            $locked->forceFill([
                'revoked_at' => now(), 'revoked_by' => $by?->id, 'revoked_by_label' => $byLabel ?? $by?->email, 'revoke_reason' => $reason,
            ])->save();

            TenantUser::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->where('user_id', $locked->user_id)->update(['status' => TenantUser::INACTIVE]);
            User::query()->whereKey($locked->user_id)->update(['status' => User::INACTIVE]);
            DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->where('tokenable_id', $locked->user_id)->delete();

            $this->cache->touchTenant($locked->tenant_id);
            $this->audit->record('service_principal.revoked', 'service_principal', $locked->id, ['revoked' => false], [
                'revoked' => true, 'kind' => $locked->kind, 'user_id' => $locked->user_id, 'revoked_by' => $locked->revoked_by_label, 'reason' => $reason,
            ], $locked->tenant_id);

            return ['principal' => $locked, 'changed' => true];
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new DomainException('A reason of 1 to 500 characters is required.', 'SERVICE_PRINCIPAL_REASON_REQUIRED', 422, ['field' => 'reason']);
        }

        return $reason;
    }
}
