<?php

namespace Tests\Feature;

use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Identity\Models\ServicePrincipal;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Identity\Services\ReservedAddress;
use App\Domain\Identity\Services\ServicePrincipalKinds;
use App\Domain\Identity\Services\ServicePrincipalRegistry;
use App\Domain\Integration\Adapters\OptiFleet\OptiFleetActor;
use App\Domain\Integration\Adapters\OptiFleet\OptiFleetAdapter;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The service-principal registry (ecosystem audit A23): who is a machine, not a person, no longer depends on an e-mail suffix. Covers who may
 * create a row (the platform operator only), what a row can become (nothing but revoked), what the identity can never be (promoted), and what
 * the registry changes for seats, the OptiFleet actor and the reserved address range.
 */
class ServicePrincipalTest extends TestCase
{
    use Fixtures;

    private const KIND = ServicePrincipalKinds::OPTIFLEET_INTEGRATION;

    private Tenant $tenant;

    private string $admin;

    private ServicePrincipalRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->tenant('alpha');
        $this->admin = $this->tenantToken($this->member($this->tenant, null, 'admin@alpha.test')[0], $this->tenant);
        $this->registry = app(ServicePrincipalRegistry::class);
    }

    private function issue(?Tenant $tenant = null): ServicePrincipal
    {
        return $this->registry->issue($tenant ?? $this->tenant, self::KIND, 'test', 'CLI', null, 'phpunit')['principal'];
    }

    private function limit(string $code, ?int $value, ?Tenant $tenant = null): void
    {
        $tenant ??= $this->tenant;
        $this->asPlatform()->putJson("/api/v1/platform/tenants/{$tenant->id}/capacity/{$code}", ['limit_value' => $value, 'source' => 'MANUAL_OVERRIDE'])->assertOk();
    }

    private function seats(): int
    {
        return app(CapacityService::class)->used($this->tenant->id, CapacityService::USER_LIMIT);
    }

    /** The database refuses the statement with a check violation (23514) whose message names the rule. */
    private function assertRefused(callable $statement, string $rule): void
    {
        try {
            DB::transaction($statement);
            $this->fail("the database accepted: {$rule}");
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0], $e->getMessage());
            $this->assertStringContainsString($rule, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------------------------------------ issuing

    public function test_issuing_creates_a_machine_identity_that_cannot_sign_in_holds_no_role_and_is_audited(): void
    {
        $principal = $this->issue();

        $user = User::query()->findOrFail($principal->user_id);
        $this->assertSame('optifleet.integration@alpha.optientry.invalid', $user->email);
        $this->assertNull($user->password);
        $this->assertNull($user->optinexus_subject);
        $this->assertSame('ACTIVE', $user->status);
        $this->assertSame([$this->tenant->id, self::KIND, 'optifleet', 'CLI', 'phpunit', 'test'], [$principal->tenant_id, $principal->kind, $principal->source_system, $principal->issued_via, $principal->issued_by_label, $principal->reason]);

        $membership = DB::table('tenant_users')->where('user_id', $user->id)->get();
        $this->assertSame([1, 'ACTIVE', $this->tenant->id], [$membership->count(), $membership[0]->status, $membership[0]->tenant_id]);
        $this->assertSame(0, DB::table('tenant_user_roles')->where('tenant_user_id', $membership[0]->id)->count());
        $this->assertSame(['TENANT'], DB::table('data_scopes')->where('tenant_user_id', $membership[0]->id)->pluck('scope_type')->all());
        $this->assertNull(app(AuthService::class)->attempt($user->email, self::PASSWORD));

        $audit = DB::table('audit_logs')->where('action', 'service_principal.issued')->get();
        $this->assertCount(1, $audit);
        $this->assertSame([$this->tenant->id, $principal->id], [$audit[0]->tenant_id, $audit[0]->resource_id]);
        $after = json_decode($audit[0]->changes, true)['after'];
        $this->assertSame([self::KIND, 'CLI', 'phpunit', 'test', $user->email], [$after['kind'], $after['via'], $after['issued_by'], $after['reason'], $after['email']]);
    }

    public function test_issuing_twice_returns_the_live_identity_and_creates_nothing_more(): void
    {
        $first = $this->registry->issue($this->tenant, self::KIND, 'one', 'CLI');
        $again = $this->registry->issue($this->tenant, self::KIND, 'two', 'CLI');

        $this->assertTrue($first['created']);
        $this->assertFalse($again['created']);
        $this->assertSame($first['principal']->id, $again['principal']->id);
        $this->assertSame(1, DB::table('service_principals')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'service_principal.issued')->count());
        $this->assertSame(1, DB::table('users')->whereRaw('optientry_reserved_address(email)')->count());
    }

    public function test_a_kind_reason_and_origin_are_validated(): void
    {
        foreach ([['NOPE', 'why', 'CLI', 'SERVICE_PRINCIPAL_KIND_UNKNOWN'], [self::KIND, '   ', 'CLI', 'SERVICE_PRINCIPAL_REASON_REQUIRED'], [self::KIND, str_repeat('x', 501), 'CLI', 'SERVICE_PRINCIPAL_REASON_REQUIRED'],
            [self::KIND, 'why', 'TENANT', 'SERVICE_PRINCIPAL_VIA_INVALID'], [self::KIND, 'why', 'BACKFILL', 'SERVICE_PRINCIPAL_VIA_INVALID']] as [$kind, $reason, $via, $code]) {
            try {
                $this->registry->issue($this->tenant, $kind, $reason, $via);
                $this->fail("issued with {$code}");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
        $this->assertSame(0, DB::table('service_principals')->count());
    }

    public function test_the_principal_of_one_tenant_is_never_the_principal_of_another(): void
    {
        $bravo = $this->tenant('bravo');
        $alpha = $this->issue();

        $this->assertNull($this->registry->live($bravo->id, self::KIND));
        $this->assertFalse(app(OptiFleetActor::class)->issued($bravo->id));
        $context = app(TenantContext::class);
        $context->runAs($bravo->id, function () {
            try {
                app(OptiFleetActor::class)->resolve();
                $this->fail("bravo borrowed alpha's identity");
            } catch (DomainException $e) {
                $this->assertSame('INTEGRATION_ACTOR_MISSING', $e->errorCode);
            }
        });
        $context->runAs($this->tenant->id, fn () => $this->assertSame($alpha->user_id, app(OptiFleetActor::class)->resolve()->id));
    }

    // ------------------------------------------------------------------------------------------------ seats

    public function test_a_registered_identity_takes_no_seat_and_a_person_on_the_reserved_range_does(): void
    {
        $this->limit('USER_LIMIT', 2); // the administrator is one
        $this->assertSame(1, $this->seats());

        $this->issue();
        $this->assertSame(1, $this->seats()); // the principal is a membership of the tenant, but not a seat
        $this->assertSame(2, DB::table('tenant_users')->where('tenant_id', $this->tenant->id)->count());
        $this->as($this->admin)->postJson('/api/v1/app/users', ['name' => 'Siti', 'email' => 'siti@alpha.test', 'password' => self::PASSWORD, 'role_ids' => []])->assertCreated();
        $this->as($this->admin)->postJson('/api/v1/app/users', ['name' => 'Budi', 'email' => 'budi@alpha.test', 'password' => self::PASSWORD, 'role_ids' => []])
            ->assertStatus(409)->assertJsonPath('code', 'CAPACITY_EXCEEDED');
    }

    public function test_a_legacy_person_on_the_reserved_range_counts_as_a_seat_and_is_reported(): void
    {
        $before = $this->seats();
        $strayId = (string) Str::uuid();
        DB::table('users')->insert(['id' => $strayId, 'name' => 'Legacy', 'email' => 'optifleet.integration@alpha.optientry.invalid', 'password' => bcrypt(self::PASSWORD), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_users')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $strayId, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame($before + 1, $this->seats());
        $this->assertSame(['optifleet.integration@alpha.optientry.invalid'], $this->registry->unregisteredReservedUsers()->pluck('email')->all());

        // ... and a registered identity next to it takes the next address, never the person's one.
        $principal = $this->issue();
        $this->assertSame('optifleet.integration.2@alpha.optientry.invalid', User::query()->findOrFail($principal->user_id)->email);
        $this->assertSame($before + 1, $this->seats());
    }

    public function test_a_body_field_cannot_make_a_user_a_machine(): void
    {
        $before = $this->seats();
        $this->as($this->admin)->postJson('/api/v1/app/users', [
            'name' => 'Siti', 'email' => 'siti@alpha.test', 'password' => self::PASSWORD, 'role_ids' => [],
            'is_service_principal' => true, 'service_principal' => true, 'kind' => self::KIND,
        ])->assertCreated();

        $this->assertSame($before + 1, $this->seats());
        $this->assertSame(0, DB::table('service_principals')->count());
    }

    // ------------------------------------------------------------------------------------------------ the reserved address range

    public static function reservedAddresses(): array
    {
        return [
            'the machine address itself' => ['optifleet.integration@alpha.optientry.invalid'],
            'any tenant code' => ['anyone@zeta.optientry.invalid'],
            'upper case' => ['Optifleet.Integration@ALPHA.Optientry.INVALID'],
            'a trailing dot' => ['anyone@alpha.optientry.invalid.'],
            'a deeper subdomain' => ['anyone@a.b.optientry.invalid'],
            'the bare domain' => ['anyone@optientry.invalid'],
        ];
    }

    /** @dataProvider reservedAddresses */
    #[DataProvider('reservedAddresses')]
    public function test_a_person_cannot_be_created_on_the_reserved_range_by_any_path(string $email): void
    {
        // tenant administrator: invite / create a user. (A malformed address such as one with a trailing dot is refused earlier by plain validation.)
        $refused = fn ($response) => $response->assertStatus(422)->assertJson(fn ($json) => $json->etc()) && ($response->json('code') === 'EMAIL_RESERVED' || isset($response->json('errors')['email']));
        $tenantResponse = $this->as($this->admin)->postJson('/api/v1/app/users', ['name' => 'X', 'email' => $email, 'password' => self::PASSWORD, 'role_ids' => []]);
        $this->assertTrue($refused($tenantResponse), 'tenant API: '.$tenantResponse->getContent());
        // platform operator: create a platform user
        $platformRole = DB::table('roles')->where('scope', 'platform')->value('id');
        $platformResponse = $this->asPlatform()->postJson('/api/v1/platform/users', ['name' => 'X', 'email' => $email, 'password' => self::PASSWORD, 'role_ids' => [$platformRole]]);
        $this->assertTrue($refused($platformResponse), 'platform API: '.$platformResponse->getContent());
        if (! str_ends_with($email, '.')) {
            $tenantResponse->assertJsonPath('code', 'EMAIL_RESERVED')->assertJsonPath('details.field', 'email');
            $platformResponse->assertJsonPath('code', 'EMAIL_RESERVED');
        }
        // anything that saves a User, including seeders and commands
        try {
            User::query()->forceCreate(['name' => 'X', 'email' => $email, 'status' => 'ACTIVE']);
            $this->fail('a person was saved on the reserved range');
        } catch (DomainException $e) {
            $this->assertSame('EMAIL_RESERVED', $e->errorCode);
        }
        // moving an existing person onto it
        $person = User::query()->where('email', 'admin@alpha.test')->firstOrFail();
        try {
            $person->forceFill(['email' => $email])->save();
            $this->fail('a person was moved onto the reserved range');
        } catch (DomainException $e) {
            $this->assertSame('EMAIL_RESERVED', $e->errorCode);
        }

        $this->assertSame(0, DB::table('users')->whereRaw('optientry_reserved_address(email)')->count());
        $this->assertSame('admin@alpha.test', $person->fresh()->email);
    }

    public function test_a_new_organization_cannot_get_an_administrator_on_the_reserved_range(): void
    {
        $this->asPlatform()->postJson('/api/v1/platform/tenants', [
            'code' => 'omega', 'name' => 'Omega', 'timezone' => 'Asia/Jakarta',
            'admin' => ['name' => 'Boss', 'email' => 'boss@omega.optientry.invalid', 'password' => self::PASSWORD],
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('tenants')->where('code', 'omega')->count());
    }

    public function test_the_database_keeps_the_reserved_range_for_registered_identities(): void
    {
        // Raw SQL, as a tool or a bug would do it: checked at commit, forced here to apply inside the test transaction.
        $this->assertRefused(function () {
            DB::table('users')->insert(['id' => (string) Str::uuid(), 'name' => 'Raw', 'email' => 'raw@alpha.optientry.invalid', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
            DB::statement('SET CONSTRAINTS users_reserved_address_check IMMEDIATE');
        }, 'reserved for service principals');

        $this->assertRefused(function () {
            DB::table('users')->where('email', 'admin@alpha.test')->update(['email' => 'admin@alpha.optientry.invalid']);
            DB::statement('SET CONSTRAINTS users_reserved_address_check IMMEDIATE');
        }, 'reserved for service principals');

        // The registry's way (user and registry row in one transaction) passes the same check.
        $principal = $this->issue();
        DB::statement('SET CONSTRAINTS users_reserved_address_check IMMEDIATE');
        $this->assertNotNull($principal->id);
    }

    public function test_the_reserved_range_is_recognised_exactly(): void
    {
        foreach (['optifleet.integration@alpha.optientry.invalid', ' X@Y.OPTIENTRY.INVALID ', 'a@optientry.invalid', 'a@b.c.optientry.invalid.'] as $reserved) {
            $this->assertTrue(ReservedAddress::matches($reserved), $reserved);
            $this->assertTrue((bool) DB::selectOne('select optientry_reserved_address(?) as r', [$reserved])->r || $reserved === ' X@Y.OPTIENTRY.INVALID ', "sql: {$reserved}"); // SQL does not trim; validation does
        }
        foreach (['wati@optientry.example', 'x@notoptientry.invalid', 'x@optientry.invalid.example', 'optientry.invalid@example.test', '', null] as $person) {
            $this->assertFalse(ReservedAddress::matches($person), (string) $person);
            $this->assertFalse((bool) DB::selectOne('select optientry_reserved_address(?) as r', [(string) $person])->r, "sql: {$person}");
        }
    }

    // ------------------------------------------------------------------------------------------------ no promotion

    public function test_the_identity_cannot_be_promoted_to_a_person(): void
    {
        $principal = $this->issue();
        $user = User::query()->findOrFail($principal->user_id);
        $membership = TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $role = DB::table('roles')->where('tenant_id', $this->tenant->id)->where('is_system', true)->value('id');

        // by the API: a tenant administrator cannot give it a role
        $this->as($this->admin)->putJson("/api/v1/app/users/{$membership->id}/roles", ['role_ids' => [$role]])
            ->assertStatus(409)->assertJsonPath('code', 'SERVICE_PRINCIPAL_PROTECTED');
        // ... nor invite its address into another organization
        $bravo = $this->tenant('bravo');
        $bravoAdmin = $this->tenantToken($this->member($bravo, null, 'admin@bravo.test')[0], $bravo);
        $this->as($bravoAdmin)->postJson('/api/v1/app/users', ['name' => 'X', 'email' => $user->email, 'role_ids' => []])->assertStatus(422)->assertJsonPath('code', 'EMAIL_RESERVED');

        // by the database, for everything that does not go through the API
        $this->assertRefused(fn () => $user->forceFill(['password' => bcrypt(self::PASSWORD)])->saveQuietly(), 'can never sign in');
        $this->assertRefused(fn () => DB::table('users')->where('id', $user->id)->update(['optinexus_subject' => 'nx-1']), 'can never sign in');
        $this->assertRefused(fn () => DB::table('users')->where('id', $user->id)->update(['email' => 'someone@elsewhere.test']), 'never changes');
        $this->assertRefused(fn () => DB::table('tenant_user_roles')->insert(['tenant_id' => $this->tenant->id, 'tenant_user_id' => $membership->id, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]), 'holds no role');
        $operatorRole = DB::table('roles')->where('scope', 'platform')->value('id') ?? Str::uuid();
        $this->assertRefused(fn () => DB::table('platform_role_assignments')->insert(['user_id' => $user->id, 'role_id' => $operatorRole, 'scope' => 'platform', 'created_at' => now(), 'updated_at' => now()]), 'holds no role');
        $this->assertRefused(fn () => DB::table('tenant_users')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $bravo->id, 'user_id' => $user->id, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]), 'one tenant only');

        $this->assertNull($user->fresh()->password);
        $this->assertNull(app(AuthService::class)->attempt($user->email, self::PASSWORD));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    public function test_a_person_cannot_be_registered_as_a_service_principal(): void
    {
        [$withPassword] = $this->member($this->tenant, [], 'wati@alpha.test');
        $row = fn (string $userId) => ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'user_id' => $userId, 'kind' => self::KIND, 'source_system' => 'optifleet',
            'issued_via' => 'CLI', 'reason' => 'x', 'issued_at' => now()];

        // a person with a password, and a passwordless account (an invitee) on an ordinary address
        $this->assertRefused(fn () => DB::table('service_principals')->insert($row($withPassword->id)), 'cannot become a service principal');
        $invitee = User::query()->forceCreate(['name' => 'Invitee', 'email' => 'invitee@alpha.test', 'status' => 'ACTIVE']);
        $this->assertRefused(fn () => DB::table('service_principals')->insert($row($invitee->id)), 'not on the reserved address range');

        // a person who holds a reserved address (legacy data) with a password, roles or a membership elsewhere
        $legacy = (string) Str::uuid();
        DB::table('users')->insert(['id' => $legacy, 'name' => 'Legacy', 'email' => 'legacy@alpha.optientry.invalid', 'password' => bcrypt(self::PASSWORD), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertRefused(fn () => DB::table('service_principals')->insert($row($legacy)), 'cannot become a service principal');
        DB::table('users')->where('id', $legacy)->update(['password' => null]);
        $bravo = $this->tenant('bravo');
        DB::table('tenant_users')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $bravo->id, 'user_id' => $legacy, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertRefused(fn () => DB::table('service_principals')->insert($row($legacy)), 'belongs to another tenant');
        $this->assertSame(0, DB::table('service_principals')->count());
    }

    // ------------------------------------------------------------------------------------------------ immutability and revocation

    public function test_a_row_never_changes_and_is_never_removed_except_for_its_revocation(): void
    {
        $principal = $this->issue();

        foreach (['kind' => 'OTHER_KIND', 'reason' => 'edited', 'issued_via' => 'PLATFORM_API', 'source_system' => 'x', 'tenant_id' => $this->tenant('bravo')->id] as $column => $value) {
            try {
                $principal->forceFill([$column => $value])->save();
                $this->fail("the model accepted a change of {$column}");
            } catch (DomainException $e) {
                $this->assertSame('SERVICE_PRINCIPAL_IMMUTABLE', $e->errorCode);
            }
            $principal->refresh();
            $this->assertRefused(fn () => DB::table('service_principals')->where('id', $principal->id)->update([$column => $value]), 'cannot be edited');
        }
        $this->assertRefused(fn () => DB::table('service_principals')->where('id', $principal->id)->update(['issued_by_label' => 'someone else']), 'cannot be edited');
        try {
            $principal->delete();
            $this->fail('the model accepted a delete');
        } catch (DomainException $e) {
            $this->assertSame('SERVICE_PRINCIPAL_IMMUTABLE', $e->errorCode);
        }
        $this->assertRefused(fn () => DB::table('service_principals')->where('id', $principal->id)->delete(), 'never removed');
        // "revoked" cannot be set without its reason, and "unrevoking" is a change like any other
        $this->assertRefused(fn () => DB::table('service_principals')->where('id', $principal->id)->update(['revoked_at' => now()]), 'service_principals_revoked_check');
        $this->assertSame(1, DB::table('service_principals')->count());
        $this->assertSame('test', $principal->fresh()->reason);
    }

    public function test_revoking_is_final_switches_the_identity_off_and_frees_the_connector_for_a_new_one(): void
    {
        $principal = $this->issue();
        $userId = $principal->user_id;

        $result = $this->registry->revoke($principal, 'compromised', null, 'phpunit');
        $this->assertTrue($result['changed']);
        $revoked = $result['principal'];
        $this->assertSame(['phpunit', 'compromised'], [$revoked->revoked_by_label, $revoked->revoke_reason]);
        $this->assertTrue($revoked->isRevoked());
        $this->assertSame(['INACTIVE', 'INACTIVE'], [User::query()->find($userId)->status, DB::table('tenant_users')->where('user_id', $userId)->value('status')]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'service_principal.revoked')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->seats());

        // final: no second revocation, no edit, and the connector has no identity any more
        $this->assertFalse($this->registry->revoke($revoked, 'again', null, 'phpunit')['changed']);
        $this->assertSame('compromised', $revoked->fresh()->revoke_reason);
        $this->assertRefused(fn () => DB::table('service_principals')->where('id', $revoked->id)->update(['revoke_reason' => 'rewritten']), 'revoked principal is final');
        $this->assertNull($this->registry->live($this->tenant->id, self::KIND));
        $this->assertContains('the platform operator has not issued the integration service identity of this organization', app(TenantContext::class)->runAs($this->tenant->id, fn () => app(OptiFleetAdapter::class)->entitlementProblems()));

        // its user stays a machine for good: it is not reactivated for a replacement, and nobody can make it a person
        $new = $this->issue();
        $this->assertNotSame($userId, $new->user_id);
        $this->assertSame('optifleet.integration.2@alpha.optientry.invalid', User::query()->find($new->user_id)->email);
        $this->assertSame(1, $this->seats());
        $this->assertRefused(fn () => DB::table('users')->where('id', $userId)->update(['password' => bcrypt(self::PASSWORD)]), 'can never sign in');
        $this->assertSame(2, DB::table('service_principals')->count());
        $this->assertSame(1, DB::table('service_principals')->whereNull('revoked_at')->count());
    }

    public function test_a_tenant_can_switch_its_identity_off_and_on_without_a_seat_but_cannot_give_it_a_role(): void
    {
        $this->limit('USER_LIMIT', 1);
        $principal = $this->issue();
        $membership = TenantUser::withoutGlobalScopes()->where('user_id', $principal->user_id)->firstOrFail();

        $this->as($this->admin)->postJson("/api/v1/app/users/{$membership->id}/status", ['status' => 'SUSPENDED'])->assertOk();
        $this->as($this->admin)->postJson("/api/v1/app/users/{$membership->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->as($this->admin)->postJson("/api/v1/app/users/{$membership->id}/status", ['status' => 'ACTIVE'])->assertOk(); // the limit is full, a machine needs no seat
        $this->assertSame('ACTIVE', $membership->fresh()->status);
        $this->assertSame(1, $this->seats());
    }

    public function test_the_member_list_tells_a_machine_from_a_person(): void
    {
        $principal = $this->issue();

        $rows = collect($this->as($this->admin)->getJson('/api/v1/app/users')->assertOk()->json('data'));
        $machine = $rows->firstWhere('user.email', 'optifleet.integration@alpha.optientry.invalid');
        $person = $rows->firstWhere('user.email', 'admin@alpha.test');

        $this->assertSame([$principal->id, self::KIND, null], [$machine['service_principal']['id'], $machine['service_principal']['kind'], $machine['service_principal']['revoked_at']]);
        $this->assertNull($person['service_principal']);
    }

    // ------------------------------------------------------------------------------------------------ the OptiFleet connector

    public function test_without_a_live_identity_the_connector_is_not_available_and_nothing_resolves(): void
    {
        $context = app(TenantContext::class);
        $problems = $context->runAs($this->tenant->id, fn () => app(OptiFleetAdapter::class)->entitlementProblems());
        $this->assertContains('the platform operator has not issued the integration service identity of this organization', $problems);
        $context->runAs($this->tenant->id, function () {
            try {
                app(OptiFleetActor::class)->resolve();
                $this->fail('resolved without an issued identity');
            } catch (DomainException $e) {
                $this->assertSame('INTEGRATION_ACTOR_MISSING', $e->errorCode);
            }
        });
        $this->assertSame(0, DB::table('users')->where('email', 'like', '%optientry.invalid')->count()); // resolving never creates one

        $this->issue();
        $problems = $context->runAs($this->tenant->id, fn () => app(OptiFleetAdapter::class)->entitlementProblems());
        $this->assertNotContains('the platform operator has not issued the integration service identity of this organization', $problems);
    }

    // ------------------------------------------------------------------------------------------------ platform API

    public function test_the_platform_operator_issues_lists_and_revokes_and_tenants_cannot(): void
    {
        $api = '/api/v1/platform';

        // a tenant user, even the administrator, has no way in
        $this->as($this->admin)->postJson("{$api}/tenants/{$this->tenant->id}/service-principals", ['kind' => self::KIND, 'reason' => 'me'])->assertStatus(403);
        $this->as($this->admin)->getJson("{$api}/service-principals")->assertStatus(403);
        $this->assertSame(0, DB::table('service_principals')->count());

        $operator = $this->platformUser();
        $created = $this->as($this->platformToken($operator))->postJson("{$api}/tenants/{$this->tenant->id}/service-principals", ['kind' => self::KIND, 'reason' => 'Go live of the OptiFleet connection'])
            ->assertCreated()->assertJsonPath('status', 'LIVE')->assertJsonPath('tenant.code', 'alpha')->assertJsonPath('issued_via', 'PLATFORM_API')
            ->assertJsonPath('issued_by', $operator->email)->assertJsonPath('user.email', 'optifleet.integration@alpha.optientry.invalid');
        $id = $created->json('id');
        $this->assertArrayNotHasKey('password', $created->json('user'));

        $this->as($this->platformToken($operator))->postJson("{$api}/tenants/{$this->tenant->id}/service-principals", ['kind' => self::KIND, 'reason' => 'again'])
            ->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, DB::table('service_principals')->count());

        $list = $this->as($this->platformToken($operator))->getJson("{$api}/service-principals?tenant_id={$this->tenant->id}&status=live")->assertOk();
        $list->assertJsonPath('data.0.id', $id)->assertJsonPath('kinds.0', self::KIND)->assertJsonPath('unregistered_reserved_addresses', []);

        $this->as($this->platformToken($operator))->postJson("{$api}/service-principals/{$id}/revoke", ['reason' => 'Connection retired'])
            ->assertOk()->assertJsonPath('status', 'REVOKED')->assertJsonPath('revoked_by', $operator->email)->assertJsonPath('revoke_reason', 'Connection retired');
        $this->as($this->platformToken($operator))->postJson("{$api}/service-principals/{$id}/revoke", ['reason' => 'twice'])->assertOk()->assertJsonPath('revoke_reason', 'Connection retired');
        $this->as($this->platformToken($operator))->getJson("{$api}/service-principals?status=live")->assertOk()->assertJsonCount(0, 'data');

        $actors = DB::table('audit_logs')->whereIn('action', ['service_principal.issued', 'service_principal.revoked'])->pluck('actor_user_id', 'action')->all();
        $this->assertSame([$operator->id, $operator->id], [$actors['service_principal.issued'], $actors['service_principal.revoked']]);
    }

    public function test_the_platform_api_validates_and_separates_viewing_from_managing(): void
    {
        $api = '/api/v1/platform';
        $viewer = $this->platformToken($this->platformUser(['platform.service_principal.view']));
        $manager = $this->platformToken($this->platformUser(['platform.service_principal.manage']));

        $this->as($viewer)->getJson("{$api}/service-principals")->assertOk();
        $this->as($viewer)->postJson("{$api}/tenants/{$this->tenant->id}/service-principals", ['kind' => self::KIND, 'reason' => 'x'])->assertStatus(403);
        $this->as($manager)->getJson("{$api}/service-principals")->assertStatus(403);

        $this->as($manager)->postJson("{$api}/tenants/{$this->tenant->id}/service-principals", ['kind' => 'NOPE', 'reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->as($manager)->postJson("{$api}/tenants/{$this->tenant->id}/service-principals", ['kind' => self::KIND])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($manager)->postJson("{$api}/tenants/{$this->tenant->id}/service-principals", ['kind' => self::KIND, 'reason' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($manager)->postJson("{$api}/tenants/".Str::uuid().'/service-principals', ['kind' => self::KIND, 'reason' => 'x'])->assertStatus(404);
        $this->as($manager)->postJson("{$api}/service-principals/".Str::uuid().'/revoke', ['reason' => 'x'])->assertStatus(404);
        $this->assertSame(0, DB::table('service_principals')->count());
    }

    public function test_the_read_only_platform_role_can_view_and_cannot_manage(): void
    {
        $codes = DB::table('permissions')->where('scope', 'platform')->where('code', 'like', 'platform.service_principal.%')->pluck('code')->sort()->values()->all();
        $this->assertSame(['platform.service_principal.manage', 'platform.service_principal.view'], $codes);

        $support = DB::table('roles')->where('scope', 'platform')->where('name', 'Platform Support (read-only)')->value('id');
        $admin = DB::table('roles')->where('scope', 'platform')->where('name', 'Platform Administrator')->value('id');
        $held = fn (string $role) => DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $role)->where('permissions.code', 'like', 'platform.service_principal.%')->pluck('permissions.code')->sort()->values()->all();
        $this->assertSame(['platform.service_principal.view'], $held($support));
        $this->assertSame($codes, $held($admin));
    }

    // ------------------------------------------------------------------------------------------------ the operator command

    public function test_the_operator_command_issues_lists_and_revokes_and_needs_a_reason(): void
    {
        $this->artisan('optientry:service-principal:issue', ['tenant' => 'alpha'])->assertExitCode(1); // no reason
        $this->artisan('optientry:service-principal:issue', ['tenant' => 'nope', '--reason' => 'x'])->assertExitCode(1);
        $this->artisan('optientry:service-principal:issue', ['tenant' => 'alpha', 'kind' => 'WHAT', '--reason' => 'x'])->assertExitCode(1);
        $this->assertSame(0, DB::table('service_principals')->count());

        $this->artisan('optientry:service-principal:issue', ['tenant' => 'alpha', '--reason' => 'Go live', '--operator' => 'dewi'])->assertExitCode(0);
        $this->artisan('optientry:service-principal:issue', ['tenant' => $this->tenant->id, '--reason' => 'again'])->assertExitCode(0);
        $principal = ServicePrincipal::query()->firstOrFail();
        $this->assertSame(['CLI', 'dewi', 'Go live'], [$principal->issued_via, $principal->issued_by_label, $principal->reason]);
        $this->assertSame(1, DB::table('service_principals')->count());

        $this->artisan('optientry:service-principal:list', ['--tenant' => 'alpha'])->expectsOutputToContain('optifleet.integration@alpha.optientry.invalid')->assertExitCode(0);
        $this->artisan('optientry:service-principal:revoke', ['principal' => $principal->id])->assertExitCode(1);
        $this->artisan('optientry:service-principal:revoke', ['principal' => $principal->id, '--reason' => 'retired', '--operator' => 'dewi'])->assertExitCode(0);
        $this->assertSame('retired', $principal->fresh()->revoke_reason);
    }

    // ------------------------------------------------------------------------------------------------ mapping of the identities of earlier releases

    private function backfill(): array
    {
        return (require base_path('database/migrations/2026_10_19_100001_create_service_principals.php'))->backfill();
    }

    /** A user the way releases before the registry created it: no registry row, address by convention. */
    private function legacyIdentity(Tenant $tenant, string $email, array $user = [], bool $member = true): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert($user + ['id' => $id, 'name' => 'Integrasi OptiFleet', 'email' => $email, 'password' => null, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        if ($member) {
            DB::table('tenant_users')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $id, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    public function test_the_migration_maps_the_existing_integration_users_once_and_leaves_everybody_else_alone(): void
    {
        $bravo = $this->tenant('bravo');
        $legacy = $this->legacyIdentity($this->tenant, 'optifleet.integration@alpha.optientry.invalid');
        $seatsWith = $this->seats();
        $this->assertSame(2, $seatsWith); // admin + the unregistered legacy user, which counted nothing under the old suffix rule but is now unknown

        // People and look-alikes that must not be mapped: a password, a role, another tenant's code, two tenants, a made-up address.
        $withPassword = $this->legacyIdentity($bravo, 'optifleet.integration@bravo.optientry.invalid', ['password' => bcrypt(self::PASSWORD)]);
        $charlie = $this->tenant('charlie');
        $withRole = $this->legacyIdentity($charlie, 'optifleet.integration@charlie.optientry.invalid');
        $membership = DB::table('tenant_users')->where('user_id', $withRole)->value('id');
        DB::table('tenant_user_roles')->insert(['tenant_id' => $charlie->id, 'tenant_user_id' => $membership, 'role_id' => DB::table('roles')->where('tenant_id', $charlie->id)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        $delta = $this->tenant('delta');
        $wrongTenant = $this->legacyIdentity($delta, 'optifleet.integration@echo.optientry.invalid');
        $other = $this->legacyIdentity($delta, 'someone.else@delta.optientry.invalid');

        $first = $this->backfill();
        $this->assertSame(['mapped' => 1, 'skipped' => 4], $first);
        $row = ServicePrincipal::query()->firstOrFail();
        $this->assertSame([$legacy, $this->tenant->id, self::KIND, 'optifleet', 'BACKFILL', 'migration'], [$row->user_id, $row->tenant_id, $row->kind, $row->source_system, $row->issued_via, $row->issued_by_label]);
        $this->assertSame($this->seats(), $seatsWith - 1); // now it is a registered machine
        $this->assertSame($this->tenant->id, DB::table('audit_logs')->where('action', 'service_principal.issued')->value('tenant_id'));
        $skipped = DB::table('audit_logs')->where('action', 'service_principal.backfill_skipped')->pluck('resource_id')->all();
        $this->assertEqualsCanonicalizing([$withPassword, $withRole, $wrongTenant, $other], $skipped);
        foreach ([$withPassword, $withRole, $wrongTenant, $other] as $untouched) {
            $this->assertSame(0, DB::table('service_principals')->where('user_id', $untouched)->count());
        }

        // idempotent: nothing more is mapped, and the audit trail does not grow with the mapped one
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'service_principal.issued')->count());
        $this->assertSame(['mapped' => 0, 'skipped' => 4], $this->backfill());
        $this->assertSame(1, DB::table('service_principals')->count());

        // the mapped identity is now exactly what the registry issues
        $this->assertSame($legacy, $this->registry->live($this->tenant->id, self::KIND)->user_id);
        $this->assertSame($legacy, app(TenantContext::class)->runAs($this->tenant->id, fn () => app(OptiFleetActor::class)->resolve()->id));
        $this->assertFalse($this->registry->issue($this->tenant, self::KIND, 'x', 'CLI')['created']);
    }

    public function test_the_migration_touches_no_posted_data_and_only_the_identity_tables(): void
    {
        $this->legacyIdentity($this->tenant, 'optifleet.integration@alpha.optientry.invalid');
        $tables = ['journal_entries', 'journal_lines', 'ap_invoices', 'vendor_payments', 'external_events', 'tenant_users', 'users', 'tenant_user_roles', 'data_scopes'];
        $before = array_map(fn ($table) => DB::table($table)->count(), $tables);
        $userRows = DB::table('users')->orderBy('id')->get()->map(fn ($u) => (array) $u)->all();

        $this->backfill();

        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->count(), $tables));
        $this->assertEquals($userRows, DB::table('users')->orderBy('id')->get()->map(fn ($u) => (array) $u)->all());
    }
}
