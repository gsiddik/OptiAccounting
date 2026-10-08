<?php

namespace Tests\Feature\Optinexus;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesOptinexus;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA0-N: sign-in with OptiNexus (OIDC code + PKCE), linking and the one-time ticket. */
class SsoSignInTest extends TestCase
{
    use FakesOptinexus, Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusUp();
        $this->nexusGrant();
    }

    public function test_first_sign_in_provisions_tenant_user_and_membership_and_the_session_works(): void
    {
        $response = $this->nexusSignIn()->assertOk()
            ->assertJsonPath('scope', 'tenant')->assertJsonPath('platform_access', false)
            ->assertJsonPath('user.email', 'siti@example.test');

        $tenant = Tenant::query()->where('optinexus_tenant_id', self::NEXUS_TENANT)->firstOrFail();
        $this->assertSame('acme-id', $tenant->code);
        $this->assertSame('PT Acme Indonesia', $tenant->name);
        $this->assertSame(Tenant::ACTIVE, $tenant->status);
        $response->assertJsonPath('tenant_id', $tenant->id);

        $user = User::query()->where('optinexus_subject', self::NEXUS_USER)->firstOrFail();
        $this->assertNull($user->password, 'an OptiNexus identity has no local password');
        $this->assertSame(TenantUser::ACTIVE, TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('status'));
        $this->assertSame(1, DB::table('data_scopes')->where('tenant_id', $tenant->id)->where('scope_type', DataScope::TENANT)->count());
        $this->assertNotNull($user->fresh()->last_login_at);

        // The commercial state was projected before the session started.
        $this->assertSame('OPTINEXUS', DB::table('subscriptions')->where('tenant_id', $tenant->id)->value('source'));
        $this->assertGreaterThan(0, DB::table('tenant_module_entitlements')->where('tenant_id', $tenant->id)->where('source', 'OPTINEXUS')->count());

        // Trail: local audit, and the outbox carries the events and the security record for OptiNexus.
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'auth.sso_login')->count());
        $this->assertEqualsCanonicalizing(
            ['optiaccounting.tenant.linked', 'optiaccounting.membership.provisioned', 'sso.login'],
            DB::table('outbox_events')->pluck('event_type')->all(),
        );

        $capabilities = $this->as($response->json('token'))->getJson('/api/v1/app/capabilities')->assertOk();
        $this->assertEqualsCanonicalizing(DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all(), $capabilities->json('permissions'));
        $capabilities->assertJsonPath('modules.ACCOUNTING_CORE', 'FULL')->assertJsonPath('identity.mode', 'optinexus')->assertJsonPath('identity.managed_externally', true);
    }

    public function test_the_pkce_and_state_parameters_are_sent_to_optinexus(): void
    {
        $location = $this->get('/api/v1/auth/sso/redirect?tenant_hint=ACME-ID')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $this->assertSame(self::NEXUS_URL.'/oidc/authorize', strtok($location, '?'));
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('oa-client', $q['client_id']);
        $this->assertSame('https://api.test/api/v1/auth/sso/callback', $q['redirect_uri']);
        $this->assertSame('openid profile email', $q['scope']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame('ACME-ID', $q['tenant_hint']);
        $this->assertGreaterThanOrEqual(32, strlen($q['state']));
        $this->assertGreaterThanOrEqual(32, strlen($q['nonce']));
        $this->assertSame(43, strlen($q['code_challenge']));
    }

    public function test_repeat_sign_in_reuses_the_rows_and_keeps_a_locally_narrowed_data_scope(): void
    {
        $this->nexusSignIn()->assertOk();
        $tenant = Tenant::query()->where('optinexus_tenant_id', self::NEXUS_TENANT)->firstOrFail();
        $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->update(['scope_type' => DataScope::OWN]);

        $this->nexusSignIn(['name' => 'Siti A.'])->assertOk();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame(1, TenantUser::withoutGlobalScopes()->count());
        $this->assertSame(DataScope::OWN, DB::table('data_scopes')->where('tenant_user_id', $membership->id)->value('scope_type'));
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'optiaccounting.tenant.linked')->count());
        $this->assertSame(2, DB::table('outbox_events')->where('event_type', 'sso.login')->count());
    }

    public function test_a_member_whose_best_scope_is_own_starts_with_own_data_scope(): void
    {
        $this->nexusGrant(scope: 'OWN');
        $this->nexusSignIn()->assertOk();

        $this->assertSame(DataScope::OWN, DB::table('data_scopes')->value('scope_type'));
    }

    // ------------------------------------------------------------------ token verification

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function tamperedIdTokens(): array
    {
        return [
            'wrong nonce' => [['nonce' => 'not-the-nonce']],
            'missing nonce' => [['nonce' => '__unset__']],
            'wrong audience' => [['aud' => 'another-client']],
            'wrong issuer' => [['iss' => 'https://evil.test']],
            'expired' => [['exp' => 1000, 'iat' => 900]],
            'issued in the future' => [['iat' => 4102444800, 'exp' => 4102448400]],
            'missing subject' => [['sub' => '__unset__']],
            'missing tenant' => [['tenant_id' => '__unset__']],
            'signed with another key' => [['__key' => 'attacker']],
        ];
    }

    #[DataProvider('tamperedIdTokens')]
    public function test_an_id_token_that_fails_verification_never_signs_anyone_in(array $tamper): void
    {
        $location = $this->nexusCallback([], $tamper)->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::FRONTEND.'/login?sso_error=', $location);
        $this->assertStringNotContainsString('ticket=', $location);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_a_symmetric_token_signed_with_the_public_key_is_rejected(): void
    {
        // Algorithm confusion: HS256 with the published RSA modulus as the secret.
        $forged = JWT::encode(
            ['iss' => self::NEXUS_URL, 'aud' => 'oa-client', 'sub' => self::NEXUS_USER, 'tenant_id' => self::NEXUS_TENANT, 'iat' => time(), 'exp' => time() + 300],
            $this->nexusKeys()['jwk']['n'], 'HS256', 'kid-primary',
        );

        $redirect = $this->get('/api/v1/auth/sso/redirect');
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->nexus['codes']['forged'] = ['claims' => $this->nexusClaims(), 'nonce' => $q['nonce'], 'challenge' => $q['code_challenge'], 'tamper' => ['__raw' => $forged]];

        $this->get('/api/v1/auth/sso/callback?code=forged&state='.$q['state'])->assertRedirectContains('sso_error=id_token_invalid');
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_state_is_single_use(): void
    {
        $redirect = $this->get('/api/v1/auth/sso/redirect');
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->nexus['codes']['c1'] = ['claims' => $this->nexusClaims(), 'nonce' => $q['nonce'], 'challenge' => $q['code_challenge'], 'tamper' => []];

        $first = $this->get('/api/v1/auth/sso/callback?code=c1&state='.$q['state'])->headers->get('Location');
        $replay = $this->get('/api/v1/auth/sso/callback?code=c1&state='.$q['state'])->headers->get('Location');

        $this->assertStringContainsString('/sso/callback?ticket=', $first);
        $this->assertStringContainsString('sso_error=state_invalid', $replay);
    }

    public function test_an_unknown_state_and_a_missing_code_are_refused(): void
    {
        $this->get('/api/v1/auth/sso/callback?code=x&state=nope')->assertRedirectContains('sso_error=state_invalid');
        $this->get('/api/v1/auth/sso/callback')->assertRedirectContains('sso_error=sso_failed');
        $this->get('/api/v1/auth/sso/callback?error=access_denied')->assertRedirectContains('sso_error=access_denied');
        $this->get('/api/v1/auth/sso/callback?error=server_error')->assertRedirectContains('sso_error=sso_failed');
    }

    public function test_a_callback_from_another_issuer_is_refused(): void
    {
        $redirect = $this->get('/api/v1/auth/sso/redirect');
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->nexus['codes']['c1'] = ['claims' => $this->nexusClaims(), 'nonce' => $q['nonce'], 'challenge' => $q['code_challenge'], 'tamper' => []];

        $this->get('/api/v1/auth/sso/callback?code=c1&state='.$q['state'].'&iss='.urlencode('https://evil.test'))->assertRedirectContains('sso_error=id_token_invalid');
    }

    public function test_userinfo_that_disagrees_with_the_id_token_denies_access(): void
    {
        $this->nexus['userinfo'] = ['sub' => self::NEXUS_USER, 'tenant_id' => '99999999-0000-4000-8000-000000000000'];

        $this->nexusCallback()->assertRedirectContains('sso_error=access_denied');
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_token_request_without_a_valid_code_is_a_failed_exchange(): void
    {
        $redirect = $this->get('/api/v1/auth/sso/redirect');
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $q);

        $this->get('/api/v1/auth/sso/callback?code=forged&state='.$q['state'])->assertRedirectContains('sso_error=token_exchange_failed');
    }

    // ------------------------------------------------------------------ who may enter

    public function test_a_user_without_the_application_in_the_apps_claim_is_denied(): void
    {
        $this->nexusCallback(['apps' => [['code' => 'optifleet']], 'groups' => ['optifleet']])->assertRedirectContains('sso_error=access_denied');
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_a_tenant_that_the_service_account_cannot_confirm_is_not_provisioned(): void
    {
        foreach ([
            'application not enabled' => ['applications' => []],
            'tenant suspended in OptiNexus' => ['status' => 'SUSPENDED'],
        ] as $label => $over) {
            $this->nexus['tenants'][self::NEXUS_TENANT] = $this->nexusTenantState($over);
            $this->nexusCallback()->assertRedirectContains('sso_error=tenant_not_entitled');
        }

        unset($this->nexus['tenants'][self::NEXUS_TENANT]); // unknown to OptiNexus (a claim alone proves nothing)
        $this->nexusCallback()->assertRedirectContains('sso_error=tenant_not_entitled');

        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_provisioning_can_be_switched_off_so_only_linked_tenants_enter(): void
    {
        config(['optiaccounting.optinexus.provision_tenants' => false]);

        $this->nexusCallback()->assertRedirectContains('sso_error=tenant_not_linked');
        $this->assertSame(0, Tenant::query()->count());

        // An administrator-linked tenant signs in.
        $tenant = $this->tenant('acme-id', subscribed: false);
        DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_tenant_id' => self::NEXUS_TENANT]);
        $this->nexusSignIn()->assertOk()->assertJsonPath('tenant_id', $tenant->id);
    }

    public function test_a_person_without_permissions_has_nothing_to_do_and_is_not_provisioned(): void
    {
        $this->nexus['grants'] = [];

        $this->nexusCallback()->assertRedirectContains('sso_error=no_permissions');
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, TenantUser::withoutGlobalScopes()->count());
    }

    public function test_a_full_tenant_refuses_a_new_member_but_not_an_existing_one(): void
    {
        $this->nexus['tenants'][self::NEXUS_TENANT]['entitlements'] = array_values(array_filter(
            $this->nexus['tenants'][self::NEXUS_TENANT]['entitlements'], fn ($e) => $e['entitlement_key'] !== 'user_limit',
        ));
        $this->nexus['tenants'][self::NEXUS_TENANT]['entitlements'][] = ['entitlement_type' => 'LIMIT', 'entitlement_key' => 'user_limit', 'value' => 1.0, 'source' => 'PLAN'];

        $this->nexusSignIn()->assertOk(); // seat 1 of 1

        $other = '8c2d6e1f-3a66-4a4f-9b69-1e4e3b7d3333';
        $this->nexusGrant($other);
        $this->nexusCallback(['sub' => $other, 'email' => 'budi@example.test'])->assertRedirectContains('sso_error=capacity_exceeded');
        $this->assertNull(User::query()->where('optinexus_subject', $other)->first(), 'a refused member leaves nothing behind');

        $this->nexusSignIn()->assertOk(); // the existing member still enters
    }

    public function test_nexus_being_down_fails_a_new_sign_in_closed(): void
    {
        $this->nexus['down'] = true;

        $this->get('/api/v1/auth/sso/redirect')->assertRedirectContains('sso_error=sso_unavailable');
        $this->assertSame(0, User::query()->count());
    }

    public function test_service_account_failure_at_sign_in_fails_closed_and_creates_nothing(): void
    {
        config(['optiaccounting.optinexus.service.client_secret' => 'wrong']);

        $this->nexusCallback()->assertRedirectContains('sso_error=sso_unavailable');
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Tenant::query()->count());
    }

    // ------------------------------------------------------------------ account linking

    public function test_a_verified_email_links_an_existing_local_account_once(): void
    {
        $tenant = $this->tenant('acme-id', subscribed: false);
        DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_tenant_id' => self::NEXUS_TENANT]);
        [$local] = $this->member($tenant, [], 'siti@example.test');

        $this->nexusSignIn()->assertOk();

        $this->assertSame(self::NEXUS_USER, $local->fresh()->optinexus_subject);
        $this->assertSame(1, User::query()->count());
    }

    public function test_an_unverified_email_never_links_a_local_account(): void
    {
        $this->tenant('other');
        $this->member(Tenant::query()->firstOrFail(), [], 'siti@example.test');

        $this->nexusCallback(['email_verified' => false])->assertRedirectContains('sso_error=email_not_verified');
        $this->assertNull(User::query()->where('email', 'siti@example.test')->value('optinexus_subject'));
    }

    public function test_an_account_bound_to_another_identity_is_never_rebound(): void
    {
        $tenant = $this->tenant('other');
        [$local] = $this->member($tenant, [], 'siti@example.test');
        $local->forceFill(['optinexus_subject' => 'someone-else'])->save();

        $this->nexusCallback()->assertRedirectContains('sso_error=account_conflict');
        $this->assertSame('someone-else', $local->fresh()->optinexus_subject);
    }

    public function test_a_platform_operator_account_is_never_linked_by_email(): void
    {
        $operator = $this->platformUser();
        $operator->forceFill(['email' => 'siti@example.test'])->save();

        $this->nexusCallback()->assertRedirectContains('sso_error=account_conflict');
        $this->assertNull($operator->fresh()->optinexus_subject);
    }

    public function test_an_inactive_local_account_cannot_sign_in(): void
    {
        $this->nexusSignIn()->assertOk();
        User::query()->where('optinexus_subject', self::NEXUS_USER)->update(['status' => User::SUSPENDED]);

        $this->nexusCallback()->assertRedirectContains('sso_error=user_inactive');
    }

    public function test_a_membership_switched_off_locally_stays_off_but_one_switched_off_by_optinexus_is_restored(): void
    {
        $this->nexusSignIn()->assertOk();
        $tenant = Tenant::query()->firstOrFail();
        $membership = TenantUser::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        // An operator's own suspension (no marker) is not undone by signing in.
        DB::table('tenant_users')->where('id', $membership->id)->update(['status' => TenantUser::SUSPENDED]);
        $this->nexusCallback()->assertRedirectContains('sso_error=membership_inactive');

        // What OptiNexus switched off (marker) is switched on again by its own sign-in.
        DB::table('tenant_users')->where('id', $membership->id)->update(['status' => TenantUser::INACTIVE, 'optinexus_deactivated_at' => now()]);
        $this->nexusSignIn()->assertOk();
        $this->assertSame(TenantUser::ACTIVE, $membership->fresh()->status);
        $this->assertNull($membership->fresh()->optinexus_deactivated_at);
    }

    public function test_a_tenant_an_operator_suspended_is_not_entered(): void
    {
        $this->nexusSignIn()->assertOk();
        DB::table('tenants')->update(['status' => Tenant::SUSPENDED]);

        $this->nexusCallback()->assertRedirectContains('sso_error=tenant_inactive');
    }

    // ------------------------------------------------------------------ ticket

    public function test_a_ticket_is_single_use_and_short_lived(): void
    {
        $callback = $this->nexusCallback();
        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $q);

        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $q['ticket']])->assertOk();
        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $q['ticket']])->assertStatus(422)->assertJsonPath('code', 'INVALID_TICKET');

        $callback = $this->nexusCallback();
        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $q['ticket']])->assertStatus(422);
        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => 'made-up'])->assertStatus(422);
        $this->postJson('/api/v1/auth/sso/exchange', [])->assertStatus(422);
    }

    public function test_access_lost_between_callback_and_exchange_issues_no_session(): void
    {
        $callback = $this->nexusCallback();
        parse_str((string) parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $q);
        DB::table('tenant_users')->update(['status' => TenantUser::SUSPENDED]);

        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $q['ticket']])->assertStatus(422);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    // ------------------------------------------------------------------ doors

    public function test_status_tells_the_login_page_which_doors_exist(): void
    {
        $this->getJson('/api/v1/auth/sso/status')->assertOk()
            ->assertExactJson(['identity_mode' => 'optinexus', 'sso_enabled' => true, 'password_login' => true]);

        config(['optiaccounting.optinexus.break_glass_login' => false]);
        $this->getJson('/api/v1/auth/sso/status')->assertJsonPath('password_login', false);

        config(['optiaccounting.optinexus.sso.client_secret' => null]);
        $this->getJson('/api/v1/auth/sso/status')->assertJsonPath('sso_enabled', false);
    }

    public function test_in_standalone_mode_every_sso_door_is_closed(): void
    {
        config(['optiaccounting.identity_mode' => 'standalone']);

        $this->getJson('/api/v1/auth/sso/status')->assertExactJson(['identity_mode' => 'standalone', 'sso_enabled' => false, 'password_login' => true]);
        $this->get('/api/v1/auth/sso/redirect')->assertRedirectContains('sso_error=sso_disabled');
        $this->get('/api/v1/auth/sso/callback?code=x&state=y')->assertRedirectContains('sso_error=sso_disabled');
        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/integration/optinexus/backchannel-logout', ['logout_token' => 'x'])->assertStatus(400);
    }

    public function test_local_password_login_is_only_the_operators_break_glass(): void
    {
        $tenant = $this->tenant('local-co');
        [$member] = $this->member($tenant, null, 'member@example.test');
        $operator = $this->platformUser();

        $this->postJson('/api/v1/auth/login', ['email' => 'member@example.test', 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJsonPath('code', 'LOCAL_LOGIN_DISABLED');
        $this->postJson('/api/v1/auth/login', ['email' => $operator->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('scope', 'platform');

        config(['optiaccounting.optinexus.break_glass_login' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $operator->email, 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJsonPath('code', 'LOCAL_LOGIN_DISABLED');
    }

    public function test_the_login_throttle_also_covers_the_sso_doors(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get('/api/v1/auth/sso/callback')->assertRedirect();
        }

        $this->get('/api/v1/auth/sso/callback')->assertStatus(429);
    }
}
