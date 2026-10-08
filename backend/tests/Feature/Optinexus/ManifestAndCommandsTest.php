<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Integration\Optinexus\EventCatalog;
use App\Domain\Integration\Optinexus\OptinexusManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

/** OA0-N: what an OptiNexus administrator registers, and the operator's health check. */
class ManifestAndCommandsTest extends OptinexusTestCase
{
    public function test_the_manifest_is_generated_from_this_repositorys_own_catalogs(): void
    {
        $manifest = app(OptinexusManifest::class)->build();

        $this->assertSame('optiaccounting', $manifest['application']['application_code']);

        $codes = DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all();
        $this->assertEqualsCanonicalizing(array_map(fn ($c) => "optiaccounting.{$c}", $codes), array_column($manifest['permissions'], 'permission_key'));
        $this->assertNotContains('optiaccounting.platform.tenant.view', array_column($manifest['permissions'], 'permission_key'), 'platform permissions are not registered in OptiNexus');
        foreach ($manifest['permissions'] as $permission) {
            $this->assertMatchesRegularExpression('/^optiaccounting\.[a-z0-9_]+(\.[a-z0-9_]+)+$/', $permission['permission_key']);
        }

        $modules = collect($manifest['capabilities'])->where('type', 'MODULE');
        $this->assertEqualsCanonicalizing(DB::table('modules')->pluck('code')->all(), $modules->pluck('code')->all());
        $this->assertEqualsCanonicalizing(DB::table('features')->pluck('code')->all(), collect($manifest['capabilities'])->where('type', 'FEATURE')->pluck('code')->all());
        $this->assertSame('ACCOUNTING_CORE', collect($manifest['capabilities'])->firstWhere('code', 'JOURNAL')['parent_code']);

        $this->assertSame(EventCatalog::all(), $manifest['events']);
        foreach ($manifest['events'] as $event) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $event['event_key']);
            $this->assertNotEmpty($event['payload_schema']['required']);
        }

        $this->assertSame(['user_limit', 'branch_limit', 'business_unit_limit'], $manifest['entitlement_limit_keys']);
        $this->assertEqualsCanonicalizing(['authorization.check', 'commercial.read', 'event.write', 'audit.write'], $manifest['service_account']['scopes']);
        $this->assertSame(['https://api.test/api/v1/auth/sso/callback'], $manifest['oidc_client']['redirect_uris']);
        $this->assertStringEndsWith('/api/v1/integration/optinexus/backchannel-logout', $manifest['oidc_client']['backchannel_logout_uri']);
        $this->assertSame(self::FRONTEND, $manifest['oidc_client']['launch_url']);
        $this->assertSame([self::FRONTEND.'/login'], $manifest['oidc_client']['post_logout_redirect_uris']);
    }

    public function test_the_manifest_command_prints_json_and_never_a_secret(): void
    {
        $buffer = new BufferedOutput;
        $this->assertSame(0, Artisan::call('optiaccounting:nexus:manifest', [], $buffer));

        $output = $buffer->fetch();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'not JSON: '.substr($output, 0, 300));
        $this->assertSame('optiaccounting', $decoded['application']['application_code']);
        $this->assertStringNotContainsString('oa-secret', $output);
        $this->assertStringNotContainsString('svc-secret', $output);
    }

    public function test_the_check_command_passes_against_a_healthy_optinexus(): void
    {
        $this->artisan('optiaccounting:nexus:check')->assertSuccessful()
            ->expectsOutputToContain('Discovery document')->expectsOutputToContain('Service account token and scopes');
    }

    public function test_the_check_command_names_what_is_wrong(): void
    {
        $this->nexus['service_scopes_ok'] = false;
        $this->artisan('optiaccounting:nexus:check')->assertFailed()->expectsOutputToContain('lacks a required scope');

        $this->nexus['service_scopes_ok'] = true;
        config(['optiaccounting.optinexus.service.client_secret' => '']);
        $this->artisan('optiaccounting:nexus:check')->assertFailed()->expectsOutputToContain('OPTINEXUS_SERVICE_CLIENT_SECRET');

        config(['optiaccounting.optinexus.service.client_secret' => 'svc-secret', 'optiaccounting.optinexus.sso.client_id' => '']);
        $this->artisan('optiaccounting:nexus:check')->assertFailed()->expectsOutputToContain('OPTINEXUS_SSO_CLIENT_ID');

        config(['optiaccounting.optinexus.sso.client_id' => 'oa-client']);
        $this->nexus['down'] = true;
        $this->artisan('optiaccounting:nexus:check')->assertFailed();
    }

    public function test_the_check_command_reports_an_unregistered_application(): void
    {
        config(['optiaccounting.optinexus.application_code' => 'not-registered']);

        $this->artisan('optiaccounting:nexus:check')->assertFailed()->expectsOutputToContain('is not registered in OptiNexus');
    }

    public function test_the_check_command_is_a_no_op_in_standalone_mode(): void
    {
        config(['optiaccounting.identity_mode' => 'standalone']);

        $this->artisan('optiaccounting:nexus:check')->assertSuccessful()->expectsOutputToContain('nothing to check');
    }
}
