<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesOptinexus;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Shared setup for the OptiNexus adapter tests: an installation in optinexus mode against an in-memory OptiNexus. */
abstract class OptinexusTestCase extends TestCase
{
    use FakesOptinexus, Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusUp();
    }

    /**
     * Signs the default OptiNexus user in for real (OIDC round trip) and returns the local rows and the session token.
     *
     * @return array{token:string,tenant:Tenant,user:User,membership:TenantUser}
     */
    protected function signedIn(string|array $permissions = '*'): array
    {
        $this->nexusGrant(permissions: $permissions);
        $token = $this->nexusSignIn()->assertOk()->json('token');

        $tenant = Tenant::query()->where('optinexus_tenant_id', self::NEXUS_TENANT)->firstOrFail();
        $user = User::query()->where('optinexus_subject', self::NEXUS_USER)->firstOrFail();

        return [
            'token' => $token, 'tenant' => $tenant, 'user' => $user,
            'membership' => TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail(),
        ];
    }

    /** A local tenant already linked to the fake OptiNexus tenant (no sign-in needed). */
    protected function linkedTenant(string $code = 'acme-id'): Tenant
    {
        $tenant = $this->tenant($code, subscribed: false);
        DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_tenant_id' => self::NEXUS_TENANT]);

        return $tenant->refresh();
    }
}
