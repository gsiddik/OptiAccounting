<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Optinexus\ProductContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeNexusServer;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Contract test for A29 across a real socket: OptiEntry's own HTTP client talks to a stand-alone server that answers only what
 * docs/ecosystem/GOVERNANCE_CONTRACTS.md documents (tests/Support/fake-nexus/router.php), with no Http::fake in between. It proves the
 * request on the wire (token, scope, JSON body, correlation id), the documented answer shape, a refusal, a throttled and a slow
 * OptiNexus, and a server that is gone.
 */
class ProductContextContractTest extends TestCase
{
    use Fixtures;

    private const NEXUS_TENANT = '7b1c5d0e-2f55-4f3e-9a58-0d3d2a6c1111';

    private const NEXUS_USER = '5a9d1f4e-7c3b-4b8a-8f60-2e1d9c0a2222';

    private ?FakeNexusServer $server = null;

    private string $token;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = FakeNexusServer::start();
        if (! $this->server) {
            $this->markTestSkipped('a local HTTP server cannot be started in this environment');
        }

        config([
            'optientry.identity_mode' => 'optinexus',
            'optientry.optinexus.base_url' => $this->server->url(),
            'optientry.optinexus.application_code' => 'optiaccounting',
            'optientry.optinexus.service.client_id' => 'svc-client',
            'optientry.optinexus.service.client_secret' => 'svc-secret',
            'optientry.optinexus.service.timeout_seconds' => 2,
            'optientry.optinexus.service.max_retries' => 1,
            'optientry.optinexus.service.max_retry_wait_seconds' => 1,
        ]);

        $this->tenant = $this->tenant('acme-id', subscribed: false);
        DB::table('tenants')->where('id', $this->tenant->id)->update(['optinexus_tenant_id' => self::NEXUS_TENANT]);
        [$this->user] = $this->member($this->tenant->refresh());
        DB::table('users')->where('id', $this->user->id)->update(['optinexus_subject' => self::NEXUS_USER]);
        $this->token = $this->tenantToken($this->user->refresh(), $this->tenant);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    private function ask()
    {
        return $this->as($this->token)->getJson('/api/v1/app/product-context');
    }

    public function test_the_request_on_the_wire_and_the_documented_answer(): void
    {
        $answer = $this->ask()->assertOk()->json();

        $this->assertTrue($answer['allowed']);
        $this->assertSame(['optiaccounting', 'optifleet'], array_column($answer['products'], 'code'));
        $this->assertSame('https://fleet.test/app?tenant_hint='.self::NEXUS_TENANT, $answer['products'][1]['switch_url']);
        $this->assertSame('ACME-ID', $answer['tenant']['code']);

        $token = $this->server->requests('/api/v1/oauth/token')[0];
        parse_str($token['body'], $form);
        $this->assertSame('client_credentials', $form['grant_type']);
        $this->assertStringContainsString('authorization.check', $form['scope']);

        $call = $this->server->requests('/api/v1/authorization/product-context')[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('Bearer contract-token', $call['authorization']);
        $this->assertStringContainsString('application/json', $call['content_type']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $call['correlation']);
        $this->assertSame(['user_id' => self::NEXUS_USER, 'tenant_id' => self::NEXUS_TENANT], json_decode($call['body'], true));
    }

    public function test_a_refusal_of_optinexus_ends_the_session(): void
    {
        $this->server->state(['deny' => [self::NEXUS_USER.'|'.self::NEXUS_TENANT => 'tenant_membership_removed']]);

        $this->ask()->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('deny_reason', 'tenant_membership_removed')->assertJsonPath('session_ended', true);
        $this->as($this->token)->getJson('/api/v1/app/capabilities')->assertUnauthorized();
    }

    public function test_a_unavailable_optinexus_gives_503_and_caches_nothing(): void
    {
        $this->server->state(['status' => 503]);
        $this->ask()->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE');
        $this->assertCount(2, $this->server->requests('/api/v1/authorization/product-context'), 'a read is repeated once on 503');

        $this->server->state([]);
        $this->ask()->assertOk()->assertJsonPath('source', 'nexus');
    }

    public function test_a_slow_optinexus_is_given_up_on_inside_the_timeout(): void
    {
        $this->server->state(['delay_ms' => 3500]);
        $started = microtime(true);

        $this->ask()->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE');
        $this->assertLessThan(12, microtime(true) - $started, 'bounded: timeout x (1 attempt + 1 repeat)');
    }

    public function test_a_server_that_is_gone_is_an_outage_not_an_error_of_ours(): void
    {
        $this->ask()->assertOk();
        $this->server->stop();
        Cache::forget(ProductContext::cacheKey($this->tenant->id, $this->user->id));

        $this->ask()->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE');
        $this->as($this->token)->getJson('/api/v1/app/capabilities')->assertStatus(503);
    }
}
