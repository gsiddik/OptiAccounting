<?php

namespace Tests\Feature\Integration;

use App\Domain\Integration\Models\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA6: connection configuration, lifecycle and the rules around the credential. */
class ConnectionTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, PayablesFixtures;

    private function body(array $override = []): array
    {
        return $override + ['name' => 'ERP PT Maju', 'source_system' => 'erp']; // a source without an adapter of its own: the generic rules (OptiFleet's are in OptiFleetAdapterTest)
    }

    private function create(array $override = []): array
    {
        return $this->postJson(self::IT.'/connections', $this->body($override))->assertCreated()->json();
    }

    private function complete(): array
    {
        return ['source_tenant_reference' => 'fleet-co-1', 'base_url' => 'https://fleet.example.test/', 'credential' => 'feed-secret-123', 'cutover_date' => '2026-03-01'];
    }

    public function test_a_connection_follows_its_configuration_from_draft_to_ready_and_never_shows_its_credential(): void
    {
        $tenant = $this->tenant();
        $this->as($this->memberToken($tenant));

        $draft = $this->create();
        $this->assertSame('DRAFT', $draft['status']);
        $this->assertSame(['OPENING_BALANCE_ONLY', 'REQUIRES_REVIEW', 5, false], [$draft['cutover_policy'], $draft['gap_policy'], $draft['pull_interval_minutes'], $draft['has_credential']]);

        $partial = $this->patchJson(self::IT."/connections/{$draft['id']}", ['source_tenant_reference' => 'fleet-co-1'])->assertOk()->json();
        $this->assertSame('CONFIGURING', $partial['status']);

        $ready = $this->patchJson(self::IT."/connections/{$draft['id']}", $this->complete())->assertOk()->json();
        $this->assertSame(['READY', 'https://fleet.example.test', true], [$ready['status'], $ready['base_url'], $ready['has_credential']]);
        $this->assertStringNotContainsString('feed-secret-123', json_encode($ready));
        $this->assertStringNotContainsString('feed-secret-123', $this->getJson(self::IT.'/connections')->assertOk()->getContent());
        $this->assertStringNotContainsString('feed-secret-123', $this->getJson(self::IT."/connections/{$draft['id']}")->getContent());

        $stored = DB::table('integration_connections')->where('id', $draft['id'])->value('credential');
        $this->assertNotSame('feed-secret-123', $stored, 'the credential is stored encrypted');
        $this->assertStringNotContainsString('feed-secret-123', DB::table('audit_logs')->where('resource_id', $draft['id'])->pluck('changes')->implode(' '));

        // Leaving the credential out of an update keeps it.
        $this->patchJson(self::IT."/connections/{$draft['id']}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('has_credential', true)->assertJsonPath('status', 'READY');
    }

    public function test_lifecycle_moves_follow_the_map_and_activation_needs_a_complete_connection(): void
    {
        $tenant = $this->tenant();
        $this->as($this->memberToken($tenant));
        $id = $this->create()['id'];

        $this->postJson(self::IT."/connections/{$id}/activate")->assertStatus(422)->assertJsonPath('code', 'CONNECTION_INCOMPLETE')
            ->assertJsonPath('details.missing', ['credential', 'base_url', 'source_tenant_reference', 'cutover_date']);
        $this->patchJson(self::IT."/connections/{$id}", $this->complete())->assertOk();
        $this->postJson(self::IT."/connections/{$id}/pause")->assertStatus(409)->assertJsonPath('code', 'CONNECTION_INVALID_TRANSITION');

        $this->postJson(self::IT."/connections/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson(self::IT."/connections/{$id}/activate")->assertStatus(409)->assertJsonPath('code', 'CONNECTION_INVALID_TRANSITION');
        $this->postJson(self::IT."/connections/{$id}/pause")->assertOk()->assertJsonPath('status', 'PAUSED');
        $this->postJson(self::IT."/connections/{$id}/resume")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson(self::IT."/connections/{$id}/resume")->assertStatus(409);

        // An active connection cannot lose what it needs.
        $this->patchJson(self::IT."/connections/{$id}", ['base_url' => null])->assertStatus(422)->assertJsonPath('code', 'CONNECTION_INCOMPLETE');

        $this->postJson(self::IT."/connections/{$id}/disconnect")->assertOk()->assertJsonPath('status', 'DISCONNECTED');
        $this->postJson(self::IT."/connections/{$id}/activate")->assertStatus(409);
        $this->postJson(self::IT."/connections/{$id}/pause")->assertStatus(409);

        // Reconfiguring a disconnected connection reconnects it; it must be activated again on purpose.
        $this->patchJson(self::IT."/connections/{$id}", ['name' => 'Again'])->assertOk()->assertJsonPath('status', 'READY');
        $this->postJson(self::IT."/connections/{$id}/activate")->assertOk();

        $actions = DB::table('audit_logs')->where('resource_id', $id)->orderBy('occurred_at')->pluck('action')->all();
        $this->assertSame(['created', 'updated', 'activated', 'paused', 'resumed', 'disconnected', 'updated', 'activated'],
            array_map(fn ($a) => str_replace('accounting.integration.connection.', '', $a), $actions));
    }

    public function test_validation_and_policy_rules(): void
    {
        $tenant = $this->tenant();
        $this->as($this->memberToken($tenant));

        $this->postJson(self::IT.'/connections', [])->assertStatus(422);
        $this->postJson(self::IT.'/connections', $this->body(['source_system' => 'Opti Fleet']))->assertStatus(422);
        $this->postJson(self::IT.'/connections', $this->body(['base_url' => 'ftp://fleet.example.test']))->assertStatus(422);
        $this->postJson(self::IT.'/connections', $this->body(['pull_interval_minutes' => 0]))->assertStatus(422);
        $this->postJson(self::IT.'/connections', $this->body(['gap_policy' => 'SKIP']))->assertStatus(422);
        $this->postJson(self::IT.'/connections', $this->body(['cutover_policy' => 'HISTORICAL_BACKFILL']))->assertStatus(422)->assertJsonPath('code', 'HISTORICAL_BACKFILL_NOT_SUPPORTED');
        $this->postJson(self::IT.'/connections', $this->body(['cutover_policy' => 'START_FROM_CUTOVER_DATE', 'gap_policy' => 'IGNORE_GAP']))->assertCreated();

        // One connection per source tenant.
        $this->create(['source_tenant_reference' => 'fleet-co-9']);
        $this->postJson(self::IT.'/connections', $this->body(['source_tenant_reference' => 'fleet-co-9']))->assertStatus(409)->assertJsonPath('code', 'CONNECTION_EXISTS');
        $this->assertSame(2, IntegrationConnection::query()->withoutGlobalScopes()->count());
    }

    public function test_the_source_identity_and_cutover_are_locked_once_events_were_received(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $this->storedEvent($tenant, $connection);
        $this->as($this->memberToken($tenant));

        $this->patchJson(self::IT."/connections/{$connection->id}", ['cutover_date' => '2026-02-01'])->assertStatus(409)->assertJsonPath('code', 'CONNECTION_LOCKED');
        $this->patchJson(self::IT."/connections/{$connection->id}", ['source_tenant_reference' => 'other'])->assertStatus(409)->assertJsonPath('code', 'CONNECTION_LOCKED');
        $this->patchJson(self::IT."/connections/{$connection->id}", ['cutover_date' => '2026-01-01', 'name' => 'Same cutover is fine', 'pull_interval_minutes' => 15])->assertOk()
            ->assertJsonPath('pull_interval_minutes', 15);
    }

    public function test_the_list_filters_by_status_and_another_tenants_connections_are_invisible(): void
    {
        $alpha = $this->tenant('alpha');
        $bravo = $this->tenant('bravo');
        $active = $this->connectionFor($alpha);
        $this->connectionFor($alpha, ['source_tenant_reference' => 'x2', 'status' => IntegrationConnection::PAUSED]);
        $foreign = $this->connectionFor($bravo);

        $this->as($this->memberToken($alpha));
        $this->getJson(self::IT.'/connections?status=ACTIVE')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->getJson(self::IT.'/connections')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::IT."/connections/{$foreign->id}")->assertNotFound();
        $this->patchJson(self::IT."/connections/{$foreign->id}", ['name' => 'Hijack'])->assertNotFound();
        $this->postJson(self::IT."/connections/{$foreign->id}/disconnect")->assertNotFound();
        $this->getJson(self::IT.'/connections?status=BOGUS')->assertStatus(422);
    }
}
