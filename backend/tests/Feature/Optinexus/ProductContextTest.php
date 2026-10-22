<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Optinexus\ProductContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Audit A29: the product switcher and the revalidation of the session come from one answer of OptiNexus, kept per tenant + user for
 * at most `ttl_seconds` (5 minutes), never used after it expires, and never used to end a session on a guess.
 */
class ProductContextTest extends OptinexusTestCase
{
    private const PATH = '/api/v1/app/product-context';

    /** @var array{token:string,tenant:Tenant,user:User,membership:TenantUser} */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $this->signedIn();
    }

    private function ask(?string $token = null): TestResponse
    {
        return $this->as($token ?? $this->session['token'])->getJson(self::PATH);
    }

    private function calls(): int
    {
        return count($this->nexusRequests('/authorization/product-context'));
    }

    public function test_it_lists_what_optinexus_returns_and_builds_the_launch_links_on_the_server(): void
    {
        $answer = $this->ask()->assertOk()->json();

        $this->assertTrue($answer['available']);
        $this->assertTrue($answer['allowed']);
        $this->assertFalse($answer['session_ended']);
        $this->assertSame('optiaccounting', $answer['current']);
        $this->assertSame(['optiaccounting', 'optifleet', 'optiradar'], array_column($answer['products'], 'code'));
        $this->assertSame('nexus', $answer['source']);
        $this->assertSame(300, $answer['ttl_seconds']);
        $this->assertCount(0, $this->nexusRequests('/authorization/effective-permissions'), 'session revalidation uses its own central verdict');

        [$entry, $fleet, $radar] = $answer['products'];
        $this->assertTrue($entry['current']);
        $this->assertNull($entry['switch_url'], 'the product you are in needs no link');
        $this->assertSame('https://fleet.test/app?tenant_hint='.self::NEXUS_TENANT, $fleet['switch_url']);
        $this->assertSame('https://radar.test/?lang=id&tenant_hint='.self::NEXUS_TENANT, $radar['switch_url'], 'an existing query is kept');
    }

    public function test_the_question_is_about_the_session_user_and_tenant_never_about_the_request(): void
    {
        $other = '99999999-9999-4999-8999-999999999999';
        $this->as($this->session['token'])->getJson(self::PATH.'?user_id='.$other.'&tenant_id='.$other.'&application_code=optifleet')->assertOk();

        $call = $this->nexusRequests('/authorization/product-context')[0];
        $this->assertSame(['user_id' => self::NEXUS_USER, 'tenant_id' => self::NEXUS_TENANT], $call['body']);
        $this->assertStringStartsWith('service-token-', substr((string) $call['auth'], 7));
        $this->assertStringContainsString('authorization.check', $this->nexus['token_scopes'][substr((string) $call['auth'], 7)]);
    }

    public function test_the_governance_portal_and_unsafe_links_never_reach_the_switcher(): void
    {
        $this->nexus['pc']['products'] = [
            ['code' => 'optiaccounting', 'name' => 'OptiEntry', 'launch_url' => self::FRONTEND],
            ['code' => 'cgo', 'name' => 'Governance', 'launch_url' => 'https://portal.test'],
            ['code' => 'evil', 'name' => 'Evil', 'launch_url' => 'javascript:alert(1)'],
            ['code' => 'creds', 'name' => 'Creds', 'launch_url' => 'https://user:pass@fleet.test/'],
            ['code' => 'nolink', 'name' => 'No link', 'launch_url' => null],
            ['code' => 'optifleet', 'name' => 'OptiFleet', 'launch_url' => 'https://fleet.test/'],
        ];

        $products = collect($this->ask()->assertOk()->json('products'))->keyBy('code');

        $this->assertArrayNotHasKey('cgo', $products->all());
        foreach (['evil', 'creds', 'nolink'] as $code) {
            $this->assertNull($products[$code]['launch_url'], $code);
            $this->assertNull($products[$code]['switch_url'], $code);
        }
        $this->assertNotNull($products['optifleet']['switch_url']);
    }

    // ------------------------------------------------------------------ cache

    public function test_an_answer_is_kept_for_the_ttl_and_asked_again_after_it(): void
    {
        $this->ask();
        $this->assertSame(1, $this->calls());
        $this->assertTrue(Cache::has(ProductContext::cacheKey($this->session['tenant']->id, $this->session['user']->id)), 'keyed by tenant and user');

        $second = $this->ask()->assertOk()->json();
        $this->assertSame(1, $this->calls(), 'a fresh answer is served from the cache');
        $this->assertSame('cache', $second['source']);

        $this->travel(299)->seconds();
        $this->ask();
        $this->assertSame(1, $this->calls(), 'still inside the 5 minutes');

        $this->travel(2)->seconds();
        $this->assertSame('nexus', $this->ask()->json('source'));
        $this->assertSame(2, $this->calls(), 'expired: asked again');
    }

    public function test_the_ttl_of_optinexus_is_respected_but_never_exceeds_five_minutes(): void
    {
        $this->nexus['pc']['ttl'] = 60;
        $this->assertSame(60, $this->ask()->json('ttl_seconds'));
        $this->travel(61)->seconds();
        $this->ask();
        $this->assertSame(2, $this->calls());

        $this->nexus['pc']['ttl'] = 3600;
        $this->travel(61)->seconds();
        $answer = $this->ask()->json();
        $this->assertSame(300, $answer['ttl_seconds']);
        $this->assertLessThanOrEqual(300, strtotime($answer['expires_at']) - strtotime($answer['evaluated_at']));
        $this->travel(301)->seconds();
        $this->ask();
        $this->assertSame(4, $this->calls());
    }

    public function test_the_cache_is_per_user_and_per_tenant(): void
    {
        $this->ask();

        $otherUser = $this->member($this->session['tenant'], null, 'rina@example.test')[0];
        DB::table('users')->where('id', $otherUser->id)->update(['optinexus_subject' => '6b0e2a5f-8d4c-4c9b-9a71-3f2e0d1b3333']);
        $this->nexus['grants']['6b0e2a5f-8d4c-4c9b-9a71-3f2e0d1b3333|'.self::NEXUS_TENANT] = ['accounting.journal.view' => 'TENANT'];
        $token = $this->tenantToken($otherUser->refresh(), $this->session['tenant']);

        $this->ask($token)->assertOk();
        $this->assertSame(2, $this->calls(), 'another person is never served this person\'s answer');
        $this->assertSame('6b0e2a5f-8d4c-4c9b-9a71-3f2e0d1b3333', $this->nexusRequests('/authorization/product-context')[1]['body']['user_id']);
        $this->assertNotSame(ProductContext::cacheKey('t1', 'u1'), ProductContext::cacheKey('t2', 'u1'));
        $this->assertNotSame(ProductContext::cacheKey('t1', 'u1'), ProductContext::cacheKey('t1', 'u2'));
    }

    // ------------------------------------------------------------------ outage

    public function test_during_an_outage_a_fresh_answer_still_serves_and_an_expired_one_is_never_used(): void
    {
        $this->ask();
        $this->nexus['down'] = true;

        $this->ask()->assertOk()->assertJsonPath('source', 'cache');
        $this->travel(301)->seconds();

        $this->ask()->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE')->assertJsonMissingPath('products');

        // Nothing was invented: when OptiNexus is back the next answer is a real one.
        $this->nexus['down'] = false;
        $this->ask()->assertOk()->assertJsonPath('source', 'nexus');
    }

    public function test_an_outage_does_not_end_the_session_it_only_withholds_the_list(): void
    {
        $this->nexus['down'] = true;
        $this->ask()->assertStatus(503);
        $this->nexus['down'] = false;

        $this->as($this->session['token'])->getJson('/api/v1/app/capabilities')->assertOk();
    }

    public function test_an_answer_about_someone_else_is_not_an_answer(): void
    {
        $bad = [
            'another user' => ['user_id' => '99999999-9999-4999-8999-999999999999'],
            'another application' => ['application_code' => 'optifleet'],
            'unknown schema' => ['schema_version' => '2.0'],
            'no verdict' => ['allowed' => 'yes'],
            'another tenant' => ['tenant' => ['id' => '99999999-9999-4999-8999-999999999999', 'code' => 'X', 'name' => 'X']],
        ];

        foreach ($bad as $label => $override) {
            $this->nexus['pc']['override'] = $override;
            $this->ask()->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE');
            $this->assertFalse(Cache::has(ProductContext::cacheKey($this->session['tenant']->id, $this->session['user']->id)), $label.' must not be cached');
        }
        $this->as($this->session['token'])->getJson('/api/v1/app/capabilities')->assertOk();
    }

    // ------------------------------------------------------------------ revalidation

    public function test_when_optinexus_says_the_person_may_no_longer_be_here_the_session_ends_with_the_reason(): void
    {
        $this->nexus['pc']['deny'][self::NEXUS_USER.'|'.self::NEXUS_TENANT] = 'application_access_revoked';

        $this->ask()->assertOk()
            ->assertJsonPath('allowed', false)->assertJsonPath('deny_reason', 'application_access_revoked')
            ->assertJsonPath('session_ended', true)->assertJsonPath('products', []);

        $this->as($this->session['token'])->getJson('/api/v1/app/capabilities')->assertUnauthorized();
        $audit = DB::table('audit_logs')->where('action', 'auth.session_ended_by_optinexus')->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->session['tenant']->id, $audit->tenant_id);
        $this->assertStringContainsString('application_access_revoked', (string) $audit->changes);
    }

    public function test_a_refusal_is_not_cached_so_a_person_let_back_in_is_judged_afresh(): void
    {
        $this->nexus['pc']['deny'][self::NEXUS_USER.'|'.self::NEXUS_TENANT] = 'user_suspended';
        $this->ask()->assertJsonPath('session_ended', true);
        $this->assertFalse(Cache::has(ProductContext::cacheKey($this->session['tenant']->id, $this->session['user']->id)));

        unset($this->nexus['pc']['deny'][self::NEXUS_USER.'|'.self::NEXUS_TENANT]);
        $token = $this->nexusSignIn()->assertOk()->json('token');
        $this->ask($token)->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('source', 'nexus');
    }

    public function test_an_unsubscribed_product_is_a_refusal_not_a_list(): void
    {
        $this->nexus['tenants'][self::NEXUS_TENANT]['applications'] = [];

        $this->ask()->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('deny_reason', 'application_not_assigned')->assertJsonPath('session_ended', true);
    }

    // ------------------------------------------------------------------ not available

    public function test_an_optinexus_without_the_endpoint_or_scope_shows_nothing_and_ends_nothing(): void
    {
        $this->nexus['pc']['missing'] = true;
        $this->ask()->assertOk()->assertJson(['available' => false, 'reason' => 'NOT_SUPPORTED', 'session_ended' => false]);
        $this->as($this->session['token'])->getJson('/api/v1/app/capabilities')->assertOk();

        $this->nexus['pc']['missing'] = false;
        $this->nexus['service_scopes_ok'] = false;
        Cache::flush();
        $this->ask()->assertOk()->assertJson(['available' => false, 'reason' => 'NOT_PERMITTED', 'session_ended' => false]);
    }

    public function test_a_person_without_an_optinexus_identity_has_no_switcher(): void
    {
        $tenant = $this->session['tenant'];
        $operator = $this->member($tenant)[0];
        $before = $this->calls();

        $this->ask($this->tenantToken($operator, $tenant))->assertOk()->assertJson(['available' => false, 'reason' => 'NOT_LINKED']);
        $this->assertSame($before, $this->calls());
    }

    public function test_a_standalone_installation_has_no_switcher_and_never_calls_optinexus(): void
    {
        config(['optientry.identity_mode' => 'standalone']);
        $tenant = $this->tenant('plain-co');
        $token = $this->tenantToken($this->member($tenant)[0], $tenant);
        $before = count($this->nexus['requests']);

        $this->ask($token)->assertOk()->assertJson(['available' => false, 'reason' => 'NOT_OPTINEXUS']);
        $this->assertCount($before, $this->nexus['requests']);
    }

    public function test_it_needs_a_tenant_session(): void
    {
        $this->getJson(self::PATH)->assertUnauthorized();
        $this->as($this->platformToken($this->platformUser()))->getJson(self::PATH)->assertForbidden();
    }
}
