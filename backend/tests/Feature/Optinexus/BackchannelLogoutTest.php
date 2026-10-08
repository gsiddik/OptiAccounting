<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/** OA0-N: OIDC Back-Channel Logout + OptiNexus access-revoked. */
class BackchannelLogoutTest extends OptinexusTestCase
{
    private const URL = '/api/v1/integration/optinexus/backchannel-logout';

    /** Sent as OptiNexus sends it: a form post, no bearer token. */
    private function logout(string $token): TestResponse
    {
        return $this->post(self::URL, ['logout_token' => $token], ['Accept' => 'application/json']);
    }

    public function test_a_logout_ends_every_session_of_the_user_and_nothing_else(): void
    {
        $session = $this->signedIn();
        $second = $session['user']->createToken('another-device', ['tenant:'.$session['tenant']->id])->plainTextToken;
        [$bystander] = $this->member($session['tenant']);
        $bystanderToken = $this->tenantToken($bystander, $session['tenant']);

        $this->assertStringContainsString('no-store', (string) $this->logout($this->nexusLogoutToken())->assertOk()->headers->get('Cache-Control'));

        $this->as($session['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($second)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($bystanderToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(User::ACTIVE, $session['user']->fresh()->status, 'a plain logout does not deactivate');
        $this->assertSame(TenantUser::ACTIVE, $session['membership']->fresh()->status);
    }

    public function test_access_revoked_for_the_user_deactivates_the_account_and_the_next_sign_in_restores_exactly_that(): void
    {
        $session = $this->signedIn();

        $this->logout($this->nexusLogoutToken(accessRevoked: true, revoked: ['reason' => 'user_disabled', 'scope' => 'user']))->assertOk();

        $user = $session['user']->fresh();
        $this->assertSame(User::INACTIVE, $user->status);
        $this->assertNotNull($user->optinexus_deactivated_at);
        $this->as($session['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'auth.sso_access_revoked')->count());
        $this->assertContains('sso.access_revoked', DB::table('outbox_events')->pluck('event_type')->all());

        // OptiNexus lets the person in again: its own sign-in undoes its own deactivation.
        $this->nexusSignIn()->assertOk();
        $this->assertSame(User::ACTIVE, $user->fresh()->status);
        $this->assertNull($user->fresh()->optinexus_deactivated_at);
    }

    public function test_access_revoked_for_one_tenant_deactivates_only_that_membership(): void
    {
        $session = $this->signedIn();
        $other = $this->tenant('other-co', subscribed: false);
        $otherMembership = $this->member($other, null, null, user: $session['user'])[1];

        $this->logout($this->nexusLogoutToken(accessRevoked: true, revoked: ['reason' => 'tenant_membership_removed', 'scope' => 'tenant', 'tenant_id' => self::NEXUS_TENANT]))->assertOk();

        $this->assertSame(TenantUser::INACTIVE, $session['membership']->fresh()->status);
        $this->assertNotNull($session['membership']->fresh()->optinexus_deactivated_at);
        $this->assertSame(TenantUser::ACTIVE, $otherMembership->fresh()->status, 'other organizations are untouched');
        $this->assertSame(User::ACTIVE, $session['user']->fresh()->status);
        $this->assertContains('optiaccounting.membership.deactivated', DB::table('outbox_events')->pluck('event_type')->all());

        $this->nexusCallback()->assertRedirectContains('ticket=');
        $this->assertSame(TenantUser::ACTIVE, $session['membership']->fresh()->status);
    }

    public function test_a_suspension_made_locally_is_not_replaced_by_optinexus_markers(): void
    {
        $session = $this->signedIn();
        $session['user']->forceFill(['status' => User::SUSPENDED])->save();

        $this->logout($this->nexusLogoutToken(accessRevoked: true))->assertOk();

        $this->assertSame(User::SUSPENDED, $session['user']->fresh()->status);
        $this->assertNull($session['user']->fresh()->optinexus_deactivated_at);
    }

    public function test_an_unknown_user_or_tenant_is_accepted_so_a_repeated_call_is_harmless(): void
    {
        $this->logout($this->nexusLogoutToken(['sub' => 'nobody-we-know']))->assertOk();

        $this->signedIn();
        $this->logout($this->nexusLogoutToken(accessRevoked: true, revoked: ['scope' => 'tenant', 'tenant_id' => 'ffffffff-0000-4000-8000-000000000009']))->assertOk();
    }

    /** @return array<string,array{0:string,1:array<string,mixed>}> */
    public static function badTokens(): array
    {
        return [
            'another audience' => ['aud', ['aud' => 'someone-else']],
            'another issuer' => ['iss', ['iss' => 'https://evil.test']],
            'issued long ago' => ['iat', ['iat' => 1000, 'exp' => 1120]],
            'expired' => ['exp', ['exp' => 1000]],
            'no subject' => ['sub', ['sub' => '']],
            'no jti' => ['jti', ['jti' => '']],
            'with a nonce' => ['nonce', ['nonce' => 'abc']],
            'without the logout event' => ['events', ['events' => ['x' => 1]]],
        ];
    }

    #[DataProvider('badTokens')]
    public function test_an_invalid_logout_token_is_refused_with_400_and_changes_nothing(string $label, array $over): void
    {
        $session = $this->signedIn();

        $this->logout($this->nexusLogoutToken($over, accessRevoked: true))->assertStatus(400)->assertJsonPath('error', 'invalid_request');

        $this->assertSame(User::ACTIVE, $session['user']->fresh()->status);
        $this->as($session['token'])->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_forged_or_mistyped_token_is_refused(): void
    {
        $session = $this->signedIn();
        $this->nexus['signing_key'] = 'attacker'; // signed with a key OptiNexus never published

        $this->logout($this->nexusLogoutToken(accessRevoked: true))->assertStatus(400);
        $this->logout('not.a.jwt')->assertStatus(400);
        $this->logout('')->assertStatus(400);

        // An id_token (typ JWT) must not pass for a logout token, even correctly signed.
        $this->nexus['signing_key'] = 'primary';
        $idToken = JWT::encode(['iss' => self::NEXUS_URL, 'aud' => 'oa-client', 'iat' => time(), 'exp' => time() + 60, 'jti' => 'j1', 'sub' => self::NEXUS_USER,
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []]], $this->nexusKeysPem(), 'RS256', 'kid-primary', ['typ' => 'JWT']);
        $this->logout($idToken)->assertStatus(400);

        $this->as($session['token'])->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_replayed_token_is_refused(): void
    {
        $this->signedIn();
        $token = $this->nexusLogoutToken();

        $this->logout($token)->assertOk();
        $this->logout($token)->assertStatus(400)->assertJsonPath('error_description', 'logout_token_replayed');
    }

    public function test_a_rotated_signing_key_is_picked_up(): void
    {
        $this->signedIn();
        Cache::flush();
        $this->nexusGrant();
        $this->logout($this->nexusLogoutToken())->assertOk(); // warms the key cache with the old key set

        $this->nexus['published_keys'] = ['rotated'];
        $this->nexus['signing_key'] = 'rotated';

        $this->logout($this->nexusLogoutToken())->assertOk();
    }

    public function test_when_the_signing_keys_cannot_be_fetched_optinexus_is_asked_to_retry(): void
    {
        $this->signedIn();
        Cache::forget('optinexus.jwks');
        $this->nexus['jwks_down'] = true;

        $this->logout($this->nexusLogoutToken())->assertStatus(503);
    }

    public function test_the_receiver_needs_no_bearer_token_but_a_signed_one(): void
    {
        $this->postJson(self::URL, [])->assertStatus(400);
        $this->postJson(self::URL, ['logout_token' => ['array']])->assertStatus(400);
    }

    private function nexusKeysPem(): string
    {
        return $this->nexusKeys()['pem'];
    }
}
