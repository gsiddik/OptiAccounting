<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Integration\Optinexus\SsoException;
use App\Domain\Integration\Optinexus\SsoLoginService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesOptinexus;
use Tests\TestCase;

class SsoEmailVerificationTest extends TestCase
{
    use FakesOptinexus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nexusUp();
        $this->nexusGrant();
    }

    public static function unverifiedClaims(): array
    {
        return [
            'false' => [false],
            'missing' => ['__unset__'],
            'null' => [null],
            'string true' => ['true'],
            'string false' => ['false'],
            'integer one' => [1],
            'array' => [[true]],
        ];
    }

    #[DataProvider('unverifiedClaims')]
    public function test_only_boolean_true_in_the_signed_token_allows_first_login(mixed $value): void
    {
        // A verified userinfo response or callback parameter cannot repair an unverified signed ID token.
        $this->nexus['userinfo'] = ['sub' => self::NEXUS_USER, 'tenant_id' => self::NEXUS_TENANT, 'email_verified' => true];
        $response = $this->nexusCallback([], ['email_verified' => $value]);

        $response->assertRedirectContains('sso_error=email_not_verified');
        $this->assertStringNotContainsString('ticket=', (string) $response->headers->get('Location'));
        foreach (['users', 'tenants', 'tenant_users', 'personal_access_tokens', 'outbox_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertCount(0, $this->nexusRequests('/api/v1/authorization/effective-permissions'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'auth.sso_refused')->count());
    }

    public function test_a_linked_account_and_its_local_profile_cannot_bypass_the_gate(): void
    {
        $this->nexusSignIn()->assertOk();
        $tables = ['users', 'tenants', 'tenant_users', 'personal_access_tokens', 'outbox_events'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        $this->nexusCallback(['name' => 'Must not update', 'email_verified' => false])
            ->assertRedirectContains('sso_error=email_not_verified');

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_nexus_verification_allows_a_subsequent_login_with_the_same_subject(): void
    {
        $this->nexusCallback(['email_verified' => false])->assertRedirectContains('sso_error=email_not_verified');
        // Invitation acceptance and operator verification both produce this same boolean claim at Nexus.
        $this->nexusSignIn(['email_verified' => true])->assertOk()->assertJsonPath('scope', 'tenant');
        $this->assertSame(self::NEXUS_USER, DB::table('users')->value('optinexus_subject'));
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_a_callback_parameter_cannot_override_the_signed_verification_claim(): void
    {
        $redirect = $this->get('/api/v1/auth/sso/redirect');
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->nexus['codes']['unverified'] = [
            'claims' => $this->nexusClaims(['email_verified' => false]),
            'nonce' => $query['nonce'],
            'challenge' => $query['code_challenge'],
            'tamper' => [],
        ];

        $this->get('/api/v1/auth/sso/callback?'.http_build_query([
            'code' => 'unverified', 'state' => $query['state'], 'email_verified' => 'true',
        ]))->assertRedirectContains('sso_error=email_not_verified');

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_a_verified_token_still_requires_the_same_userinfo_subject(): void
    {
        $this->nexus['userinfo'] = [
            'sub' => 'a-different-subject', 'tenant_id' => self::NEXUS_TENANT, 'email_verified' => true,
        ];

        $this->nexusCallback()->assertRedirectContains('sso_error=access_denied');
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_the_ticket_service_itself_refuses_missing_verification_before_linking(): void
    {
        $claims = $this->nexusClaims();
        unset($claims['email_verified']);

        try {
            app(SsoLoginService::class)->ticketFor($claims);
            $this->fail('An unverified identity must never receive a ticket.');
        } catch (SsoException $e) {
            $this->assertSame('email_not_verified', $e->getMessage());
        }

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }
}
