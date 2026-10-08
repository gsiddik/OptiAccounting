<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    /** A valid bcrypt hash of a random string, checked when the e-mail is unknown so timing does not reveal it. */
    private const DUMMY_HASH = '$2y$12$M67emcy74etaGQhHOVgBD.KHGf6vv/B3GQjYw9nJESpzFyc7P14wi';

    public function __construct(private readonly AuditService $audit) {}

    /** Verify credentials. Always performs a hash check so unknown e-mails cost the same time. */
    public function attempt(string $email, string $password): ?User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();

        $hash = $user?->password ?? self::DUMMY_HASH;
        $valid = Hash::check($password, $hash);

        return ($user && $user->password !== null && $valid) ? $user : null;
    }

    /** @return array{token:string,expires_at:?string} */
    public function issueToken(User $user, string $ability): array
    {
        $minutes = (int) config('sanctum.expiration');
        $expiresAt = $minutes > 0 ? now()->addMinutes($minutes) : null;
        $token = $user->createToken($ability === 'identity' ? 'identity' : explode(':', $ability)[0], [$ability], $expiresAt);

        return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt?->toIso8601String()];
    }

    /** Tenants the user may currently enter: active membership in an active tenant. */
    public function enterableTenants(User $user)
    {
        return DB::table('tenant_users as tu')
            ->join('tenants as t', 't.id', '=', 'tu.tenant_id')
            ->where('tu.user_id', $user->id)->where('tu.status', TenantUser::ACTIVE)->where('t.status', Tenant::ACTIVE)
            ->orderBy('t.name')->get(['t.id', 't.code', 't.name']);
    }

    public function hasPlatformAccess(User $user): bool
    {
        return DB::table('platform_role_assignments')->where('user_id', $user->id)->exists();
    }

    /** Replace the current token by a tenant-bound one; membership and tenant state are verified server-side. */
    public function switchTenant(User $user, string $tenantId, ?PersonalAccessToken $current): ?array
    {
        if (! $this->enterableTenants($user)->contains('id', $tenantId)) {
            return null;
        }

        $current?->delete();
        $issued = $this->issueToken($user, "tenant:{$tenantId}");
        $this->audit->record('auth.tenant_switched', 'tenant', $tenantId, tenantId: $tenantId);

        return $issued;
    }

    public function switchPlatform(User $user, ?PersonalAccessToken $current): ?array
    {
        if (! $this->hasPlatformAccess($user)) {
            return null;
        }

        $current?->delete();

        return $this->issueToken($user, 'platform');
    }
}
