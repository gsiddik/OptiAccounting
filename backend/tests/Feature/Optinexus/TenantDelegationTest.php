<?php

namespace Tests\Feature\Optinexus;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Optinexus\Delegation\DelegationFailure;
use App\Domain\Integration\Optinexus\Delegation\TenantDelegation;
use App\Domain\Integration\Optinexus\EntitlementProjector;
use App\Domain\Integration\Optinexus\NexusApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Audit R08/A25 on the OptiEntry side: an Admin Tenant manages users and custom roles from OptiEntry through the OptiNexus
 * delegation API (an in-memory service built from the documented JSON, fed with this repository's own manifest). OptiNexus is the
 * only authority; OptiEntry sends the signed-in person as the acting user, projects the answers, counts seats and audits.
 */
class TenantDelegationTest extends OptinexusTestCase
{
    private const BASE = '/api/v1/app/delegation';

    /** @var array{token:string,tenant:Tenant,user:User,membership:TenantUser} */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $this->signedIn();
        DB::table('tenant_capacity_limits')->where('tenant_id', $this->session['tenant']->id)->where('limit_code', 'USER_LIMIT')->update(['limit_value' => 5]);
    }

    private function api(string $method, string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->as($this->session['token'])->withHeaders($headers)->{$method.'Json'}(self::BASE.$path, $body);
    }

    private function delegationCalls(): array
    {
        return array_values(array_filter($this->nexus['requests'], fn ($r) => str_contains($r['path'], '/delegation/')));
    }

    private function roleId(string $key): string
    {
        return $this->nexusRole($key)['id'];
    }

    private function newPerson(array $over = []): array
    {
        return $over + ['name' => 'Budi Santoso', 'email' => 'budi@example.test', 'role_ids' => []];
    }

    // ------------------------------------------------------------------ availability

    public function test_status_says_whether_delegation_is_possible_without_asking_optinexus(): void
    {
        $before = count($this->nexus['requests']);

        $this->api('get', '/status')->assertOk()->assertJson(['available' => true, 'reason' => null, 'mode' => 'optinexus']);
        $this->assertCount($before, $this->nexus['requests']);

        config(['optientry.optinexus.service.client_secret' => '']);
        $this->api('get', '/status')->assertOk()->assertJson(['available' => false, 'reason' => 'DELEGATION_NOT_CONFIGURED']);
    }

    public function test_in_standalone_mode_the_delegation_routes_refuse_and_the_local_routes_work(): void
    {
        config(['optientry.identity_mode' => 'standalone']);
        $tenant = $this->tenant('plain-co');
        $token = $this->tenantToken($this->member($tenant)[0], $tenant);

        $this->as($token)->getJson(self::BASE.'/users')->assertStatus(409)->assertJsonPath('code', 'DELEGATION_NOT_AVAILABLE');
        $this->as($token)->postJson(self::BASE.'/users', $this->newPerson())->assertStatus(409)->assertJsonPath('code', 'DELEGATION_NOT_AVAILABLE');
        $this->as($token)->getJson('/api/v1/app/users')->assertOk();
        $this->assertSame([], $this->delegationCalls());
    }

    public function test_a_person_without_an_optinexus_identity_cannot_delegate(): void
    {
        // A platform operator who entered the tenant (break-glass) holds no OptiNexus subject, so no Admin Tenant can be named.
        $tenant = $this->session['tenant'];
        $operator = $this->member($tenant)[0];
        $this->assertNull($operator->optinexus_subject);

        // Without a subject OptiNexus grants such a person nothing, so the route gate already refuses ...
        $this->as($this->tenantToken($operator, $tenant))->getJson(self::BASE.'/users')->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->assertSame([], $this->delegationCalls());

        // ... and the service refuses on its own too: no acting user can be named.
        try {
            app(TenantDelegation::class)->users($tenant->id, $operator, null, 1, 25);
            $this->fail('A person without an OptiNexus identity must not be able to act.');
        } catch (DelegationFailure $e) {
            $this->assertSame('ACTOR_NOT_LINKED', $e->errorCode);
        }
        $this->assertSame([], $this->delegationCalls());
    }

    // ------------------------------------------------------------------ users

    public function test_the_list_is_the_answer_of_optinexus_with_the_local_projection_and_the_seats(): void
    {
        $this->api('get', '/users')->assertOk()
            ->assertJsonPath('data.0.id', self::NEXUS_USER)
            ->assertJsonPath('data.0.is_tenant_admin', true)
            ->assertJsonPath('data.0.local.status', 'ACTIVE')
            ->assertJsonPath('data.0.local.member_id', $this->session['membership']->id)
            ->assertJsonPath('meta.seats', ['limit' => 5, 'used' => 1, 'remaining' => 4])
            ->assertJsonPath('meta.pagination.total', 1);

        $call = $this->delegationCalls()[0];
        $this->assertSame('GET', $call['method']);
        $this->assertStringContainsString('/delegation/tenants/'.self::NEXUS_TENANT.'/users', $call['url']);
        $this->assertStringContainsString('acting_user_id='.self::NEXUS_USER, $call['url']);
    }

    public function test_the_tenant_and_the_acting_user_come_from_the_session_never_from_the_request(): void
    {
        $other = '99999999-9999-4999-8999-999999999999';
        $this->api('get', '/users?tenant_id='.$other.'&acting_user_id='.$other)->assertOk();
        $this->api('post', '/users', $this->newPerson(['tenant_id' => $other, 'acting_user_id' => $other, 'application_code' => 'fleet']))->assertCreated();

        foreach ($this->delegationCalls() as $call) {
            $this->assertStringContainsString('/delegation/tenants/'.self::NEXUS_TENANT.'/', $call['url']);
            $this->assertStringNotContainsString($other, $call['url']);
            $this->assertSame(self::NEXUS_USER, $call['body']['acting_user_id'] ?? self::NEXUS_USER);
            $this->assertArrayNotHasKey('tenant_id', $call['body']);
            $this->assertArrayNotHasKey('application_code', $call['body']);
        }
    }

    public function test_creating_a_person_creates_them_in_optinexus_then_projects_them_with_a_seat_and_an_audit_entry(): void
    {
        $accountant = $this->roleId('tpl-accountant');

        $response = $this->api('post', '/users', $this->newPerson(['role_ids' => [$accountant], 'initial_password' => 'Str0ng!Passw0rd#1']))
            ->assertCreated()->assertJsonPath('outcome', 'created')->assertJsonPath('roles.0.name', 'Accountant')->assertJsonPath('local.status', 'INVITED');
        $id = $response->json('id');

        // OptiNexus received exactly what a person typed, on behalf of the signed-in Admin Tenant.
        $post = collect($this->delegationCalls())->firstWhere('method', 'POST');
        $this->assertSame(self::NEXUS_USER, $post['body']['acting_user_id']);
        $this->assertSame(['budi@example.test', [$accountant]], [$post['body']['email'], $post['body']['role_ids']]);
        $this->assertSame('Str0ng!Passw0rd#1', $post['body']['initial_password']);
        $this->assertNotNull($post['correlation']);

        // The local rows are a projection: no password, the OptiNexus subject, an invitation the first sign-in activates, the narrowest scope.
        $user = User::query()->where('optinexus_subject', $id)->firstOrFail();
        $this->assertNull($user->password);
        $membership = TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(TenantUser::INVITED, $membership->status);
        $this->assertSame(['OWN'], $membership->dataScopes()->pluck('scope_type')->all());
        $this->assertSame([], DB::table('tenant_user_roles')->where('tenant_user_id', $membership->id)->pluck('role_id')->all(), 'roles are not copied: OptiNexus decides');

        // A seat is taken, and the Entry audit log has the change, the outcome and no secret.
        $this->api('get', '/users')->assertJsonPath('meta.seats.used', 2);
        $audit = DB::table('audit_logs')->where('action', 'delegation.user_added')->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->session['user']->id, $audit->actor_user_id);
        $this->assertSame('created', json_decode($audit->changes, true)['after']['outcome']);
        $this->assertStringNotContainsString('Passw0rd', (string) json_encode(DB::table('audit_logs')->get()));
        $events = DB::table('outbox_events')->where('event_type', 'optiaccounting.membership.provisioned')->orderBy('id')->get();
        $this->assertCount(2, $events, 'the Admin Tenant at sign-in and the new person');
        $this->assertSame($id, json_decode($events->last()->payload, true)['user_subject']);
    }

    public function test_the_api_reference_ties_the_request_to_the_call_in_optinexus(): void
    {
        $response = $this->api('get', '/users', [], ['X-Request-Id' => 'ref-0123456789'])->assertOk();
        $this->assertSame('ref-0123456789', $response->headers->get('X-Request-Id'));
        $this->assertSame('ref-0123456789', $this->delegationCalls()[0]['correlation']);
    }

    public function test_the_same_person_again_is_not_duplicated_and_takes_no_second_seat(): void
    {
        $this->api('post', '/users', $this->newPerson())->assertCreated();
        $this->api('post', '/users', $this->newPerson(['email' => 'BUDI@example.test']))->assertOk()->assertJsonPath('outcome', 'existing');

        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame(2, DB::table('tenant_users')->where('tenant_id', $this->session['tenant']->id)->count());
        $this->assertSame(2, collect($this->nexus['delegation']['users'])->count());
        $this->api('get', '/users')->assertJsonPath('meta.seats.used', 2);
    }

    public function test_an_identity_that_already_exists_in_optinexus_is_linked_not_duplicated(): void
    {
        $this->nexus['delegation']['identities']['rina@example.test'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $this->api('post', '/users', $this->newPerson(['name' => 'Rina', 'email' => 'rina@example.test']))->assertOk()
            ->assertJsonPath('outcome', 'linked')->assertJsonPath('id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $this->assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', User::query()->where('email', 'rina@example.test')->value('optinexus_subject'));
    }

    public function test_an_email_that_belongs_to_a_local_account_is_never_claimed_by_a_delegated_creation(): void
    {
        // Only a verified sign-in may link accounts (AccountLinker); a delegated creation must not take over a local account.
        $local = User::query()->forceCreate(['name' => 'Old Budi', 'email' => 'budi@example.test', 'password' => bcrypt(self::PASSWORD), 'status' => 'ACTIVE']);

        $this->api('post', '/users', $this->newPerson())->assertCreated()->assertJsonPath('local', null);

        $this->assertNull($local->fresh()->optinexus_subject);
        $this->assertSame(0, TenantUser::withoutGlobalScopes()->where('user_id', $local->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'delegation.projection_deferred')->count());
    }

    public function test_the_seat_limit_refuses_before_optinexus_is_asked(): void
    {
        DB::table('tenant_capacity_limits')->where('limit_code', 'USER_LIMIT')->update(['limit_value' => 1]);

        $this->api('post', '/users', $this->newPerson())->assertStatus(409)->assertJsonPath('code', 'CAPACITY_EXCEEDED')->assertJsonPath('details.limit', 1)->assertJsonPath('details.used', 1);
        $this->assertSame([], array_filter($this->delegationCalls(), fn ($c) => $c['method'] === 'POST'));
        $this->assertSame(1, collect($this->nexus['delegation']['users'])->count());
    }

    public function test_a_person_who_already_holds_a_seat_can_be_linked_when_the_limit_is_reached(): void
    {
        DB::table('tenant_capacity_limits')->where('limit_code', 'USER_LIMIT')->update(['limit_value' => 1]);

        // The signed-in Admin already counts; adding the same address changes nothing.
        $this->api('post', '/users', $this->newPerson(['name' => 'Siti', 'email' => 'siti@example.test']))->assertOk()->assertJsonPath('outcome', 'existing');
    }

    public function test_when_the_last_seat_is_taken_in_between_the_access_just_granted_is_withdrawn(): void
    {
        DB::table('tenant_capacity_limits')->where('limit_code', 'USER_LIMIT')->update(['limit_value' => 2]);
        $tenant = $this->session['tenant'];
        // Another request takes the last seat after the first check and while OptiNexus is still answering.
        $this->nexus['delegation']['after_add_user'] = function () use ($tenant) {
            $this->member($tenant);
        };

        $this->api('post', '/users', $this->newPerson())->assertStatus(409)->assertJsonPath('code', 'CAPACITY_EXCEEDED');

        $created = collect($this->nexus['delegation']['users'])->firstWhere('email', 'budi@example.test');
        $this->assertNull($created['access'], 'access in OptiNexus was taken back');
        $this->assertSame(0, User::query()->where('email', 'budi@example.test')->count(), 'nothing was projected');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'delegation.user_withdrawn')->count());
    }

    public function test_roles_of_a_person_are_replaced_through_optinexus_and_the_permission_cache_is_dropped(): void
    {
        $id = $this->api('post', '/users', $this->newPerson())->json('id');
        $before = app(AccessCache::class)->tenantVersion($this->session['tenant']->id);

        $this->api('put', "/users/{$id}/roles", ['role_ids' => [$this->roleId('tpl-accountant')]])->assertOk()->assertJsonPath('roles.0.code', 'ACCOUNTANT');

        $this->assertSame([$this->roleId('tpl-accountant')], $this->nexus['delegation']['user_roles'][$id]);
        $this->assertGreaterThan($before, app(AccessCache::class)->tenantVersion($this->session['tenant']->id));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'delegation.user_roles_set')->count());

        $this->api('put', "/users/{$id}/roles", ['role_ids' => []])->assertOk()->assertJsonPath('roles', []);
    }

    public function test_a_role_that_is_not_assignable_is_refused_by_optinexus_and_the_refusal_is_audited(): void
    {
        $id = $this->api('post', '/users', $this->newPerson())->json('id');
        $owner = $this->roleId('tpl-owner'); // carries access.user.manage, which is not delegable

        $this->api('put', "/users/{$id}/roles", ['role_ids' => [$owner]])->assertStatus(422)->assertJsonPath('code', 'ROLE_NOT_ASSIGNABLE')->assertJsonPath('details.role_ids', [$owner]);
        $this->api('post', '/users', $this->newPerson(['email' => 'x@example.test', 'role_ids' => [$owner]]))->assertStatus(422)->assertJsonPath('code', 'ROLE_NOT_ASSIGNABLE');

        $this->assertSame([], $this->nexus['delegation']['user_roles'][$id] ?? []);
        $this->assertNull(User::query()->where('email', 'x@example.test')->first());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'delegation.refused')->count());
    }

    public function test_removing_a_person_takes_the_access_away_in_optinexus_ends_the_membership_and_frees_the_seat(): void
    {
        $id = $this->api('post', '/users', $this->newPerson())->json('id');
        $user = User::query()->where('optinexus_subject', $id)->firstOrFail();
        $user->createToken('t', ['tenant:'.$this->session['tenant']->id]);

        $this->api('delete', "/users/{$id}")->assertOk();

        $delete = collect($this->delegationCalls())->firstWhere('method', 'DELETE');
        $this->assertStringContainsString('acting_user_id='.self::NEXUS_USER, $delete['url']);
        $this->assertNull($this->nexus['delegation']['users'][$id]['access']);
        $membership = TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(TenantUser::INACTIVE, $membership->status);
        $this->assertNotNull($membership->optinexus_deactivated_at, 'marked as done by OptiNexus, so adding the person again undoes exactly this');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->api('get', '/users')->assertJsonPath('meta.seats.used', 1);
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'optiaccounting.membership.deactivated')->count());

        // Adding the person again reactivates the same membership (a seat is taken again).
        $this->api('post', '/users', $this->newPerson())->assertOk()->assertJsonPath('outcome', 'linked');
        $this->assertSame(TenantUser::INVITED, $membership->fresh()->status);
        $this->assertNull($membership->fresh()->optinexus_deactivated_at);
        $this->assertSame(1, TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_removing_yourself_or_an_admin_tenant_is_refused(): void
    {
        $this->api('delete', '/users/'.self::NEXUS_USER)->assertStatus(409)->assertJsonPath('code', 'SELF_REVOCATION_DENIED');
        $this->assertSame([], array_filter($this->delegationCalls(), fn ($c) => $c['method'] === 'DELETE'), 'refused here, OptiNexus was not asked');

        $other = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $this->nexus['delegation']['users'][$other] = ['id' => $other, 'name' => 'Another Admin', 'email' => 'admin2@example.test', 'status' => 'ACTIVE', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => true, 'access' => 'ACTIVE', 'role_ids' => []];
        $this->api('delete', "/users/{$other}")->assertStatus(409)->assertJsonPath('code', 'TENANT_ADMIN_PROTECTED');
        $this->api('put', "/users/{$other}/roles", ['role_ids' => []])->assertStatus(409)->assertJsonPath('code', 'TENANT_ADMIN_PROTECTED');
        $this->api('delete', '/users/cccccccc-cccc-4ccc-8ccc-cccccccccccc')->assertStatus(404)->assertJsonPath('code', 'USER_NOT_IN_TENANT');
    }

    // ------------------------------------------------------------------ roles and the delegable set

    public function test_the_catalog_shows_assignable_roles_and_each_permission_with_its_state(): void
    {
        $response = $this->api('get', '/roles')->assertOk();

        $roles = collect($response->json('roles'));
        $this->assertSame(['Accountant'], $roles->pluck('name')->all(), 'a template that carries a non-delegable permission is not offered');
        $this->assertTrue($roles[0]['template']);
        $this->assertFalse($roles[0]['editable']);
        $this->assertContains('accounting.journal.view', $roles[0]['permissions'], "permissions are this application's codes, without the OptiNexus prefix");

        $states = collect($response->json('permissions'))->keyBy('code');
        $this->assertSame('AVAILABLE', $states['accounting.journal.post']['state']);
        $this->assertSame('JOURNAL', $states['accounting.journal.post']['capability']);
        foreach (['access.user.manage', 'access.role.manage', 'accounting.period.close', 'accounting.integration.manage', 'accounting.integration.view'] as $code) {
            $this->assertSame('NOT_DELEGABLE', $states[$code]['state'], $code);
        }
        $this->assertSame(DB::table('permissions')->where('scope', 'tenant')->count(), $states->count());
    }

    public function test_a_permission_whose_module_the_tenant_does_not_hold_is_shown_as_not_entitled(): void
    {
        $this->nexus['delegation']['entitled'] = array_values(array_diff($this->nexus['delegation']['entitled'], ['BUDGET']));

        $states = collect($this->api('get', '/roles')->json('permissions'))->keyBy('code');
        $this->assertSame('NOT_ENTITLED', $states['accounting.budget.view']['state']);
        $this->assertSame('AVAILABLE', $states['accounting.journal.view']['state']);
    }

    public function test_only_an_admin_tenant_of_optinexus_gets_answers_even_with_the_local_permission(): void
    {
        $token = $this->tokenFor(['access.user.view', 'access.user.manage', 'access.role.view']); // holds the permissions, is not an Admin Tenant

        $this->as($token)->getJson(self::BASE.'/users')->assertForbidden()->assertJsonPath('code', 'ACTOR_NOT_TENANT_ADMIN');
        $this->as($token)->postJson(self::BASE.'/users', $this->newPerson())->assertForbidden()->assertJsonPath('code', 'ACTOR_NOT_TENANT_ADMIN');
        $this->assertNull(User::query()->where('email', 'budi@example.test')->first());
        $this->assertSame(1, collect($this->nexus['delegation']['users'])->where('access', 'ACTIVE')->where('is_tenant_admin', true)->count());
    }

    public function test_assignable_roles_need_only_the_user_permission(): void
    {
        $token = $this->tokenFor(['access.user.view'], admin: true);

        $this->as($token)->getJson(self::BASE.'/assignable-roles')->assertOk()->assertJsonPath('data.0.name', 'Accountant')->assertJsonMissingPath('data.0.permissions');
        $this->as($token)->getJson(self::BASE.'/roles')->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_a_custom_role_is_created_edited_and_deleted_through_optinexus(): void
    {
        $created = $this->api('post', '/roles', ['name' => 'AP Clerk', 'description' => 'Vendor invoices', 'permissions' => ['accounting.ap_invoice.view', 'accounting.ap_invoice.create']])
            ->assertCreated()->assertJsonPath('editable', true)->assertJsonPath('template', false)->assertJsonPath('permissions', ['accounting.ap_invoice.create', 'accounting.ap_invoice.view']);
        $id = $created->json('id');

        $post = collect($this->delegationCalls())->where('method', 'POST')->last();
        $this->assertSame(['optiaccounting.accounting.ap_invoice.view', 'optiaccounting.accounting.ap_invoice.create'], $post['body']['permissions'], 'OptiNexus knows the keys with the application prefix');

        $this->api('put', "/roles/{$id}", ['name' => 'AP Senior', 'permissions' => ['accounting.ap_invoice.view', 'accounting.ap_invoice.post']])
            ->assertOk()->assertJsonPath('name', 'AP Senior')->assertJsonPath('permissions', ['accounting.ap_invoice.post', 'accounting.ap_invoice.view']);
        $this->assertSame(['accounting.ap_invoice.view', 'accounting.ap_invoice.post'], array_map(fn ($k) => substr($k, strlen('optiaccounting.')), $this->nexus['delegation']['roles'][$id]['permissions']));

        $this->api('delete', "/roles/{$id}")->assertOk();
        $this->assertArrayNotHasKey($id, $this->nexus['delegation']['roles']);
        $this->assertSame(['delegation.role_created', 'delegation.role_updated', 'delegation.role_deleted'], DB::table('audit_logs')->where('action', 'like', 'delegation.role_%')->orderBy('occurred_at')->pluck('action')->all());
    }

    public function test_a_permission_outside_the_delegable_set_is_refused_and_surfaced_in_this_applications_codes(): void
    {
        $this->api('post', '/roles', ['name' => 'Sneaky', 'permissions' => ['accounting.journal.view', 'access.user.manage', 'accounting.period.close', 'accounting.integration.manage']])
            ->assertStatus(422)->assertJsonPath('code', 'PERMISSION_NOT_DELEGABLE')
            ->assertJsonPath('details.permissions', ['access.user.manage', 'accounting.period.close', 'accounting.integration.manage']);

        $this->assertSame(['TPL' => 2], ['TPL' => count($this->nexus['delegation']['roles'])], 'no role was created');

        // The same on an edit of a role that exists.
        $id = $this->api('post', '/roles', ['name' => 'Viewer', 'permissions' => ['accounting.journal.view']])->json('id');
        $this->api('put', "/roles/{$id}", ['permissions' => ['accounting.journal.view', 'access.role.manage']])->assertStatus(422)->assertJsonPath('code', 'PERMISSION_NOT_DELEGABLE');
        $this->assertSame(['optiaccounting.accounting.journal.view'], $this->nexus['delegation']['roles'][$id]['permissions']);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'delegation.refused')->count());
    }

    public function test_a_permission_that_is_not_in_this_applications_catalog_is_refused_before_optinexus_is_asked(): void
    {
        $calls = count($this->delegationCalls());

        $this->api('post', '/roles', ['name' => 'Odd', 'permissions' => ['accounting.journal.view', 'cgo.ecosystem.access']])
            ->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_PERMISSION')->assertJsonPath('details.permissions', ['cgo.ecosystem.access']);
        $this->assertCount($calls, $this->delegationCalls());
    }

    public function test_a_role_name_is_not_created_twice_and_a_double_submit_is_stopped(): void
    {
        $this->api('post', '/roles', ['name' => 'Cashier', 'permissions' => ['accounting.cash_transaction.view']])->assertCreated();
        $this->api('post', '/roles', ['name' => 'cashier', 'permissions' => ['accounting.cash_transaction.view']])->assertStatus(409)->assertJsonPath('code', 'ROLE_NAME_TAKEN');
        $this->api('post', '/roles', ['name' => 'Accountant', 'permissions' => ['accounting.cash_transaction.view']])->assertStatus(409)->assertJsonPath('code', 'ROLE_NAME_TAKEN');

        $lock = Cache::lock('delegation:role-create:'.$this->session['tenant']->id.':'.hash('sha256', 'ledger'), 15);
        $this->assertTrue($lock->get());
        $this->api('post', '/roles', ['name' => 'Ledger', 'permissions' => ['accounting.gl.view']])->assertStatus(409)->assertJsonPath('code', 'DELEGATION_IN_PROGRESS');
        $lock->release();
        $this->api('post', '/roles', ['name' => 'Ledger', 'permissions' => ['accounting.gl.view']])->assertCreated();
    }

    public function test_a_template_cannot_be_edited_and_a_role_in_use_cannot_be_deleted(): void
    {
        $this->api('put', '/roles/'.$this->roleId('tpl-accountant'), ['name' => 'Hacked'])->assertStatus(404)->assertJsonPath('code', 'ROLE_NOT_EDITABLE');
        $this->api('delete', '/roles/'.$this->roleId('tpl-accountant'))->assertStatus(404)->assertJsonPath('code', 'ROLE_NOT_EDITABLE');

        $roleId = $this->api('post', '/roles', ['name' => 'Temp', 'permissions' => ['accounting.gl.view']])->json('id');
        $personId = $this->api('post', '/users', $this->newPerson(['role_ids' => [$roleId]]))->json('id');
        $this->api('delete', "/roles/{$roleId}")->assertStatus(409)->assertJsonPath('code', 'ROLE_IN_USE');

        $this->api('put', "/users/{$personId}/roles", ['role_ids' => []])->assertOk();
        $this->api('delete', "/roles/{$roleId}")->assertOk();
    }

    // ------------------------------------------------------------------ error translation

    /** @return array<string,array{0:int,1:string,2:int,3:string}> */
    public static function refusals(): array
    {
        return [
            'actor' => [403, 'ACTOR_NOT_TENANT_ADMIN', 403, 'ACTOR_NOT_TENANT_ADMIN'],
            'tenant not active' => [403, 'TENANT_NOT_ACTIVE', 403, 'TENANT_NOT_ACTIVE'],
            'application not active' => [403, 'APPLICATION_NOT_ACTIVE', 403, 'APPLICATION_NOT_ACTIVE'],
            'not subscribed' => [403, 'APPLICATION_NOT_SUBSCRIBED', 403, 'APPLICATION_NOT_SUBSCRIBED'],
            'not delegable' => [422, 'PERMISSION_NOT_DELEGABLE', 422, 'PERMISSION_NOT_DELEGABLE'],
            'role not assignable' => [422, 'ROLE_NOT_ASSIGNABLE', 422, 'ROLE_NOT_ASSIGNABLE'],
            'role not editable' => [404, 'ROLE_NOT_EDITABLE', 404, 'ROLE_NOT_EDITABLE'],
            'role in use' => [409, 'ROLE_IN_USE', 409, 'ROLE_IN_USE'],
            'user not in tenant' => [404, 'USER_NOT_IN_TENANT', 404, 'USER_NOT_IN_TENANT'],
            'admin protected' => [409, 'TENANT_ADMIN_PROTECTED', 409, 'TENANT_ADMIN_PROTECTED'],
            'not linkable' => [409, 'IDENTITY_NOT_LINKABLE', 409, 'IDENTITY_NOT_LINKABLE'],
            'membership not active' => [409, 'MEMBERSHIP_NOT_ACTIVE', 409, 'MEMBERSHIP_NOT_ACTIVE'],
            'self revocation' => [409, 'SELF_REVOCATION_DENIED', 409, 'SELF_REVOCATION_DENIED'],
            'not bound' => [403, 'SERVICE_ACCOUNT_NOT_BOUND', 503, 'DELEGATION_NOT_CONFIGURED'],
            'not delegable application' => [403, 'APPLICATION_NOT_DELEGABLE', 503, 'DELEGATION_NOT_CONFIGURED'],
            'tenant mismatch' => [403, 'TENANT_MISMATCH', 503, 'DELEGATION_NOT_CONFIGURED'],
            'missing scope' => [403, 'UNAUTHORIZED', 503, 'DELEGATION_NOT_CONFIGURED'],
            'unknown tenant' => [404, 'RESOURCE_NOT_FOUND', 409, 'DELEGATION_TENANT_UNKNOWN'],
            'throttled' => [429, 'RATE_LIMITED', 503, 'IDENTITY_PROVIDER_UNAVAILABLE'],
            'server error' => [500, 'INTERNAL_ERROR', 503, 'IDENTITY_PROVIDER_UNAVAILABLE'],
            'something new' => [418, 'TEAPOT', 502, 'DELEGATION_UNEXPECTED_RESPONSE'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_every_error_code_of_optinexus_is_translated(int $upstreamStatus, string $upstreamCode, int $status, string $code): void
    {
        $this->nexus['delegation']['force'] = ['status' => $upstreamStatus, 'code' => $upstreamCode, 'details' => $upstreamCode === 'PERMISSION_NOT_DELEGABLE' ? ['permissions' => ['optiaccounting.access.user.manage']] : null];
        if ($upstreamStatus === 429) {
            config(['optientry.optinexus.service.max_retries' => 0]);
        }

        $response = $this->api('post', '/roles', ['name' => 'Anything', 'permissions' => ['accounting.gl.view']])->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertNotEmpty($response->json('details.reference'), 'every refusal carries the reference a person can quote');
        $this->assertStringNotContainsString('optiaccounting.', (string) $response->getContent(), 'OptiNexus keys are translated to this application\'s codes');
        if ($code === 'PERMISSION_NOT_DELEGABLE') {
            $response->assertJsonPath('details.permissions', ['access.user.manage']);
        }
    }

    public function test_validation_errors_of_optinexus_come_back_as_field_errors(): void
    {
        $this->nexus['delegation']['force'] = ['status' => 422, 'code' => 'VALIDATION_ERROR', 'details' => ['email' => ['The email must be a valid email address.']]];

        $this->api('post', '/users', $this->newPerson())->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    // ------------------------------------------------------------------ outage, configuration, authorization

    public function test_an_outage_is_reported_as_such_and_a_lost_write_says_its_outcome_is_unknown(): void
    {
        $this->nexus['down'] = true;

        $this->api('get', '/users')->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE')->assertJsonMissingPath('details.outcome_unknown');
        $response = $this->api('post', '/users', $this->newPerson())->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE');
        $response->assertJsonPath('details.outcome_unknown', true);

        $this->nexus['down'] = false;
        $this->assertNull(User::query()->where('email', 'budi@example.test')->first(), 'nothing is projected from a call that did not complete');
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'delegation.user_added')->count());
    }

    public function test_a_write_is_never_repeated_by_the_transport(): void
    {
        $this->nexus['delegation']['force'] = ['status' => 502, 'code' => 'BAD_GATEWAY'];
        $this->api('post', '/users', $this->newPerson())->assertStatus(503)->assertJsonPath('details.outcome_unknown', true);

        $this->assertCount(1, array_filter($this->delegationCalls(), fn ($c) => $c['method'] === 'POST'));
        $this->assertNull(User::query()->where('email', 'budi@example.test')->first());
    }

    public function test_a_read_is_repeated_once_after_a_transient_failure(): void
    {
        $this->nexus['delegation']['force'] = ['status' => 502, 'code' => 'BAD_GATEWAY'];

        $this->api('get', '/users')->assertOk();
        $this->assertCount(2, $this->delegationCalls());
    }

    public function test_a_service_account_that_may_not_delegate_is_reported_as_a_configuration_problem(): void
    {
        $this->nexus['delegation']['scope_revoked'] = true;
        $this->api('get', '/users')->assertStatus(503)->assertJsonPath('code', 'DELEGATION_NOT_CONFIGURED');

        // The other calls of the service account are not affected: the permissions still come from OptiNexus.
        $this->nexus['delegation']['scope_revoked'] = false;
        $this->api('get', '/users')->assertOk();
        $this->as($this->session['token'])->getJson('/api/v1/app/capabilities')->assertOk();
    }

    public function test_delegation_asks_for_its_own_token_and_leaves_the_existing_one_alone(): void
    {
        $this->api('get', '/users')->assertOk();

        $scopes = array_values($this->nexus['token_scopes']);
        $this->assertContains(NexusApi::SCOPES, $scopes);
        $this->assertContains(NexusApi::DELEGATION_SCOPES, $scopes);
        $this->assertStringNotContainsString('tenant.delegate', NexusApi::SCOPES);
    }

    public function test_a_person_who_may_not_manage_users_is_refused_before_optinexus_is_asked(): void
    {
        $token = $this->tokenFor(['access.user.view']);

        $this->as($token)->postJson(self::BASE.'/users', $this->newPerson())->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->as($token)->deleteJson(self::BASE.'/users/'.self::NEXUS_USER)->assertForbidden();
        $this->as($token)->postJson(self::BASE.'/roles', ['name' => 'X', 'permissions' => ['accounting.gl.view']])->assertForbidden();
        $this->assertSame([], array_filter($this->delegationCalls(), fn ($c) => $c['method'] !== 'GET'));
    }

    public function test_a_read_only_subscription_refuses_every_change(): void
    {
        $this->nexus['tenants'][self::NEXUS_TENANT]['subscriptions'] = [['id' => 'sub-1', 'status' => 'PAST_DUE']];
        app(EntitlementProjector::class)->sync($this->session['tenant']);

        $this->api('post', '/users', $this->newPerson())->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_READ_ONLY');
        $this->api('post', '/roles', ['name' => 'Xy', 'permissions' => ['accounting.gl.view']])->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_READ_ONLY');
        $this->api('delete', '/users/'.self::NEXUS_USER)->assertStatus(403);
        $this->assertSame([], array_filter($this->delegationCalls(), fn ($c) => $c['method'] !== 'GET'));
        $this->api('get', '/users')->assertOk();
    }

    public function test_a_member_of_another_tenant_never_appears_in_the_list(): void
    {
        $other = $this->tenant('other-co');
        $this->member($other);
        $this->api('post', '/users', $this->newPerson())->assertCreated();

        $rows = collect($this->api('get', '/users')->json('data'));
        $this->assertCount(2, $rows);
        $this->assertSame([$this->session['tenant']->id], array_values(array_unique($rows->pluck('local.member_id')->filter()->map(fn ($id) => TenantUser::withoutGlobalScopes()->find($id)->tenant_id)->all())));
    }

    /** @param list<string> $permissions A token for a new person who signed in with exactly these permissions. */
    private function tokenFor(array $permissions, bool $admin = false): string
    {
        $sub = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $this->nexusGrant($sub, self::NEXUS_TENANT, $permissions);
        $this->nexus['delegation']['users'][$sub] = ['id' => $sub, 'name' => 'Clerk', 'email' => 'clerk@example.test', 'status' => 'ACTIVE', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => $admin, 'access' => 'ACTIVE', 'role_ids' => []];
        $this->nexus['delegation']['admins'][$sub] = $admin;

        $location = (string) $this->nexusCallback(['sub' => $sub, 'email' => 'clerk@example.test', 'name' => 'Clerk'])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $query['ticket']])->assertOk()->json('token');
    }
}
