<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Fixtures;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use Fixtures;

    private function login(string $email, string $password = self::PASSWORD)
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_single_tenant_user_is_signed_into_that_tenant(): void
    {
        $tenant = $this->tenant('alpha');
        [$user] = $this->member($tenant, [], 'one@example.test');

        $this->login('one@example.test')->assertOk()
            ->assertJsonPath('scope', 'tenant')->assertJsonPath('tenant_id', $tenant->id)
            ->assertJsonMissingPath('user.password')->assertJsonStructure(['token', 'expires_at', 'tenants']);
    }

    public function test_user_in_two_tenants_gets_an_identity_token_and_switches_server_side(): void
    {
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');
        [$user] = $this->member($alpha, [], 'multi@example.test');
        $this->member($beta, [], user: $user);

        $response = $this->login('multi@example.test')->assertOk()->assertJsonPath('scope', 'identity');
        $this->assertCount(2, $response->json('tenants'));

        // An identity token cannot reach tenant resources.
        $this->as($response->json('token'))->getJson('/api/v1/app/capabilities')->assertForbidden()->assertJsonPath('code', 'TOKEN_SCOPE');

        $switched = $this->as($response->json('token'))->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $beta->id])
            ->assertOk()->assertJsonPath('tenant_id', $beta->id);

        // The identity token was consumed; the new one is bound to beta.
        $this->as($response->json('token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($switched->json('token'))->getJson('/api/v1/app/capabilities')->assertOk()->assertJsonPath('tenant.id', $beta->id);
    }

    public function test_cannot_switch_to_a_tenant_without_active_membership(): void
    {
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');
        [$user] = $this->member($alpha, [], 'a@example.test');
        $token = $this->tenantToken($user, $alpha);

        $this->as($token)->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $beta->id])
            ->assertForbidden()->assertJsonPath('code', 'TENANT_NOT_ENTERABLE');
        // The caller's session is untouched by the failed attempt.
        $this->as($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_bad_credentials_and_unknown_email_look_the_same(): void
    {
        $tenant = $this->tenant('alpha');
        $this->member($tenant, [], 'real@example.test');

        $wrong = $this->login('real@example.test', 'wrong-password-123')->assertStatus(422)->json();
        $unknown = $this->login('nobody@example.test')->assertStatus(422)->json();
        $this->assertSame($wrong, $unknown);
    }

    public function test_inactive_user_cannot_sign_in_and_existing_tokens_stop_working(): void
    {
        $tenant = $this->tenant('alpha');
        [$user] = $this->member($tenant, [], 'gone@example.test');
        $token = $this->tenantToken($user, $tenant);
        $this->as($token)->getJson('/api/v1/app/capabilities')->assertOk();

        $user->forceFill(['status' => 'INACTIVE'])->save();

        $this->login('gone@example.test')->assertForbidden()->assertJsonPath('code', 'USER_INACTIVE');
        $this->as($token)->getJson('/api/v1/app/capabilities')->assertForbidden()->assertJsonPath('code', 'USER_INACTIVE');
    }

    public function test_inactive_membership_and_inactive_tenant_are_enforced_on_every_request(): void
    {
        $tenant = $this->tenant('alpha');
        [$user, $membership] = $this->member($tenant, [], 'm@example.test');
        $token = $this->tenantToken($user, $tenant);

        $membership->forceFill(['status' => TenantUser::SUSPENDED])->save();
        $this->as($token)->getJson('/api/v1/app/capabilities')->assertForbidden()->assertJsonPath('code', 'MEMBERSHIP_INACTIVE');
        $this->login('m@example.test')->assertOk()->assertJsonPath('scope', 'identity')->assertJsonCount(0, 'tenants');

        $membership->forceFill(['status' => TenantUser::ACTIVE])->save();
        $tenant->forceFill(['status' => Tenant::SUSPENDED])->save();
        $this->as($token)->getJson('/api/v1/app/capabilities')->assertForbidden()->assertJsonPath('code', 'TENANT_INACTIVE');
    }

    public function test_login_is_rate_limited_per_account_and_ip(): void
    {
        RateLimiter::clear('x');
        for ($i = 0; $i < 5; $i++) {
            $this->login('victim@example.test', 'bad-password-123')->assertStatus(422);
        }
        $this->login('victim@example.test', 'bad-password-123')->assertStatus(429);
    }

    public function test_logout_revokes_the_token(): void
    {
        $tenant = $this->tenant('alpha');
        [$user] = $this->member($tenant, [], 'out@example.test');
        $token = $this->tenantToken($user, $tenant);

        $this->as($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->as($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    public function test_platform_token_and_tenant_token_are_not_interchangeable(): void
    {
        $tenant = $this->tenant('alpha');
        [$user] = $this->member($tenant);
        $operator = $this->platformUser();

        $this->as($this->platformToken($operator))->getJson('/api/v1/app/capabilities')->assertForbidden()->assertJsonPath('code', 'TOKEN_SCOPE');
        $this->as($this->tenantToken($user, $tenant))->getJson('/api/v1/platform/tenants')->assertForbidden()->assertJsonPath('code', 'TOKEN_SCOPE');
    }

    public function test_tenant_cannot_be_chosen_through_request_input(): void
    {
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');
        $this->member($beta, null, 'b-admin@example.test');
        [$user] = $this->member($alpha);
        $this->member($beta, [], user: $user);

        // Even for a user that belongs to both, the token's tenant wins over header/query/body hints.
        $response = $this->as($this->tenantToken($user, $alpha))
            ->withHeader('X-Tenant-Id', $beta->id)
            ->getJson('/api/v1/app/capabilities?tenant_id='.$beta->id)
            ->assertOk();
        $this->assertSame($alpha->id, $response->json('tenant.id'));
    }

    public function test_user_without_any_access_is_told_so(): void
    {
        User::query()->forceCreate(['name' => 'Nobody', 'email' => 'nobody@example.test', 'password' => bcrypt(self::PASSWORD), 'status' => 'ACTIVE']);

        $this->login('nobody@example.test')->assertOk()->assertJsonPath('scope', 'identity')
            ->assertJsonPath('platform_access', false)->assertJsonCount(0, 'tenants');
    }
}
