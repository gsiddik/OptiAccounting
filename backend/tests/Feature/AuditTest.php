<?php

namespace Tests\Feature;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Audit trail (OA0 §40): complete, attributable, secret-free, transactional and append-only. */
class AuditTest extends TestCase
{
    use Fixtures;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->tenant('alpha');
        [$user] = $this->member($this->tenant, null, 'admin@alpha.test');
        $this->token = $this->tenantToken($user, $this->tenant);
    }

    private function logs(string $action): array
    {
        return DB::table('audit_logs')->where('action', $action)->orderBy('occurred_at')->get()->all();
    }

    public function test_a_change_is_recorded_with_actor_tenant_request_and_before_after(): void
    {
        $id = $this->as($this->token)->withHeader('X-Request-Id', 'req-12345')
            ->postJson('/api/v1/app/branches', ['code' => 'HQ', 'name' => 'Head office'])->assertCreated()->json('id');
        $this->as($this->token)->patchJson("/api/v1/app/branches/{$id}", ['name' => 'Main office'])->assertOk();

        [$created] = $this->logs('branch.created');
        $this->assertSame($this->tenant->id, $created->tenant_id);
        $this->assertSame($id, $created->resource_id);
        $this->assertSame('branch', $created->resource_type);
        $this->assertSame('tenant', $created->actor_scope);
        $this->assertNotNull($created->actor_user_id);
        $this->assertNotNull($created->occurred_at);
        $this->assertSame('req-12345', json_decode($created->context, true)['request_id']);

        [$updated] = $this->logs('branch.updated');
        $changes = json_decode($updated->changes, true);
        $this->assertSame('Head office', $changes['before']['name']);
        $this->assertSame('Main office', $changes['after']['name']);
    }

    public function test_platform_actions_are_attributed_to_the_platform_scope(): void
    {
        $this->asPlatform()->postJson('/api/v1/platform/tenants', ['code' => 'newco', 'name' => 'NewCo'])->assertCreated();

        $row = collect($this->logs('tenant.created'))->last(); // the fixtures created alpha earlier
        $this->assertSame('platform', $row->actor_scope);
        $this->assertNotNull($row->actor_user_id);
    }

    public function test_the_main_security_and_configuration_events_are_audited(): void
    {
        $platform = $this->platformToken($this->platformUser());
        $other = $this->tenant('beta', subscribed: false);

        $this->as($platform)->postJson("/api/v1/platform/tenants/{$other->id}/entitlements/modules", ['module_code' => 'ACCOUNTING_CORE', 'state' => 'ACTIVE', 'source' => 'MANUAL_OVERRIDE'])->assertCreated();
        $this->as($platform)->putJson("/api/v1/platform/tenants/{$other->id}/capacity/USER_LIMIT", ['limit_value' => 3, 'source' => 'MANUAL_OVERRIDE'])->assertOk();
        $this->as($platform)->postJson("/api/v1/platform/tenants/{$other->id}/status", ['status' => 'SUSPENDED', 'reason' => 'non payment'])->assertOk();
        $this->as($this->token)->postJson('/api/v1/app/roles', ['name' => 'Auditor', 'permissions' => ['audit.view']])->assertCreated();
        $this->as($this->token)->postJson('/api/v1/app/users', ['name' => 'N', 'email' => 'n@alpha.test', 'password' => self::PASSWORD, 'role_ids' => []])->assertCreated();

        foreach (['entitlement.module_granted', 'entitlement.capacity_set', 'tenant.status_changed', 'role.created', 'membership.created'] as $action) {
            $this->assertNotEmpty($this->logs($action), $action);
        }
        $this->assertSame($other->id, collect($this->logs('entitlement.module_granted'))->last()->tenant_id);
    }

    public function test_sign_ins_and_failed_sign_ins_are_audited_without_the_password(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@alpha.test', 'password' => 'Wrong-Passw0rd!'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@alpha.test', 'password' => self::PASSWORD])->assertOk();

        $failed = $this->logs('auth.login_failed');
        $this->assertCount(1, $failed);
        $this->assertSame('INVALID_CREDENTIALS', json_decode($failed[0]->changes, true)['after']['reason']);
        $this->assertCount(1, $this->logs('auth.login'));

        $dump = json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString('Wrong-Passw0rd!', $dump);
        $this->assertStringNotContainsString(self::PASSWORD, $dump);
    }

    public function test_secrets_never_reach_the_audit_log(): void
    {
        $this->as($this->token)->postJson('/api/v1/app/users', ['name' => 'N', 'email' => 'n@alpha.test', 'password' => self::PASSWORD, 'role_ids' => []])->assertCreated();
        $this->asPlatform()->postJson('/api/v1/platform/tenants', [
            'code' => 'gamma', 'name' => 'Gamma', 'admin' => ['name' => 'G', 'email' => 'g@gamma.test', 'password' => self::PASSWORD],
        ])->assertCreated();

        app(AuditService::class)->record('test.secret_scrub', 'test', null,
            ['password' => 'p1', 'api_key' => 'k1', 'nested' => ['access_token' => 't1', 'Authorization' => 'Bearer x', 'client_secret' => 's1', 'keep' => 'visible']],
            ['password_confirmation' => 'p2', 'name' => 'visible']);

        $dump = json_encode(DB::table('audit_logs')->get());
        foreach ([self::PASSWORD, 'p1', 'p2', 'k1', 't1', 'Bearer x', 's1', '$2y$'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, $secret);
        }
        $scrubbed = json_decode($this->logs('test.secret_scrub')[0]->changes, true);
        $this->assertSame('visible', $scrubbed['before']['nested']['keep']);
        $this->assertSame('visible', $scrubbed['after']['name']);
    }

    public function test_audit_rows_cannot_be_updated_or_deleted_even_with_raw_sql(): void
    {
        $this->as($this->token)->postJson('/api/v1/app/branches', ['code' => 'HQ', 'name' => 'HQ'])->assertCreated();
        $id = $this->logs('branch.created')[0]->id;

        foreach ([
            fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']),
            fn () => DB::table('audit_logs')->where('id', $id)->delete(),
            fn () => DB::statement("UPDATE audit_logs SET actor_user_id = NULL WHERE id = '{$id}'"),
            fn () => DB::statement('DELETE FROM audit_logs'),
        ] as $attempt) {
            try {
                DB::transaction($attempt); // own savepoint, so the failed statement does not poison the test transaction
                $this->fail('the audit table accepted a change');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertSame('branch.created', DB::table('audit_logs')->where('id', $id)->value('action'));
    }

    public function test_a_failed_change_leaves_no_audit_row_behind(): void
    {
        $this->as($this->token)->postJson('/api/v1/app/branches', ['code' => 'HQ', 'name' => 'HQ'])->assertCreated();
        $this->as($this->token)->postJson('/api/v1/app/branches', ['code' => 'HQ', 'name' => 'Duplicate'])->assertStatus(422)->assertJsonPath('code', 'CODE_TAKEN');

        $this->assertCount(1, $this->logs('branch.created'));
    }

    public function test_the_tenant_audit_view_shows_only_its_own_trail_and_needs_permission(): void
    {
        $beta = $this->tenant('beta');
        $betaToken = $this->tenantToken($this->member($beta)[0], $beta);
        $this->as($betaToken)->postJson('/api/v1/app/branches', ['code' => 'BETA', 'name' => 'Beta'])->assertCreated();
        $this->as($this->token)->postJson('/api/v1/app/branches', ['code' => 'ALPHA', 'name' => 'Alpha'])->assertCreated();

        $rows = $this->as($this->token)->getJson('/api/v1/app/audit-logs?action=branch')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertSame([$this->tenant->id], array_values(array_unique(array_column($rows, 'tenant_id'))));

        $limited = $this->tenantToken($this->member($this->tenant, ['organization.view'])[0], $this->tenant);
        $this->as($limited)->getJson('/api/v1/app/audit-logs')->assertForbidden();
    }

    public function test_the_platform_audit_view_spans_tenants_and_can_filter(): void
    {
        $beta = $this->tenant('beta');
        $betaToken = $this->tenantToken($this->member($beta)[0], $beta);
        $this->as($betaToken)->postJson('/api/v1/app/branches', ['code' => 'BETA', 'name' => 'Beta'])->assertCreated();
        $this->as($this->token)->postJson('/api/v1/app/branches', ['code' => 'ALPHA', 'name' => 'Alpha'])->assertCreated();

        $all = $this->asPlatform()->getJson('/api/v1/platform/audit-logs?action=branch.created')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->tenant->id, $beta->id], array_column($all, 'tenant_id'));

        $only = $this->asPlatform()->getJson("/api/v1/platform/audit-logs?action=branch.created&tenant_id={$beta->id}")->json('data');
        $this->assertSame([$beta->id], array_column($only, 'tenant_id'));

        $this->as($this->platformToken($this->platformUser(['platform.tenant.view'])))->getJson('/api/v1/platform/audit-logs')->assertForbidden();
    }
}
