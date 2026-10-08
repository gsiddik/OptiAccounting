<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Integration\Optinexus\EventCatalog;
use App\Domain\Integration\Optinexus\OptinexusEventRelay;
use App\Domain\Integration\Services\OutboxPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** OA0-N: transactional outbox and its relay to OptiNexus (/events and /audit-events). */
class OutboxRelayTest extends OptinexusTestCase
{
    private function publishEvent(?string $tenantId = null, string $type = 'tenant.linked'): void
    {
        app(OutboxPublisher::class)->event($type, $tenantId, ['optinexus_tenant_id' => self::NEXUS_TENANT, 'code' => 'acme-id']);
    }

    private function row(): object
    {
        return DB::table('outbox_events')->orderBy('created_at')->firstOrFail();
    }

    public function test_an_event_exists_only_if_the_change_it_describes_committed(): void
    {
        try {
            DB::transaction(function () {
                $this->publishEvent();
                throw new \RuntimeException('the business change failed');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('outbox_events')->count());

        DB::transaction(fn () => $this->publishEvent());
        $this->assertSame(1, DB::table('outbox_events')->count());
    }

    public function test_standalone_installations_write_no_outbox_rows(): void
    {
        config(['optiaccounting.identity_mode' => 'standalone']);

        $this->publishEvent();
        app(OutboxPublisher::class)->audit('sso.login', null);

        $this->assertSame(0, DB::table('outbox_events')->count());
    }

    public function test_an_event_is_delivered_under_its_own_id_with_the_optinexus_tenant(): void
    {
        $tenant = $this->linkedTenant();
        $this->publishEvent($tenant->id);
        $row = $this->row();

        $this->assertSame(['delivered' => 1, 'retrying' => 0, 'failed' => 0], app(OptinexusEventRelay::class)->relay());

        $sent = $this->nexusRequests('/api/v1/events')[0];
        $this->assertSame($row->id, $sent['body']['event_id'], 'idempotency key = outbox id');
        $this->assertSame('optiaccounting.tenant.linked', $sent['body']['event_key']);
        $this->assertSame('1', $sent['body']['event_version']);
        $this->assertSame(self::NEXUS_TENANT, $sent['body']['tenant_id'], 'OptiNexus id, never the local one');
        $this->assertEqualsCanonicalizing(['optinexus_tenant_id' => self::NEXUS_TENANT, 'code' => 'acme-id'], $sent['body']['data']);
        $this->assertSame(self::NEXUS_TENANT, $sent['body']['data']['optinexus_tenant_id']);
        $this->assertStringStartsWith('Bearer service-token-', $sent['auth']);
        $this->assertSame('DELIVERED', $this->row()->status);
        $this->assertNotNull($this->row()->delivered_at);

        $this->assertSame(0, app(OptinexusEventRelay::class)->relay()['delivered'], 'a delivered row is never sent again');
    }

    public function test_a_security_record_goes_to_the_audit_endpoint(): void
    {
        $tenant = $this->linkedTenant();
        app(OutboxPublisher::class)->audit('sso.login', $tenant->id, ['resource_type' => 'user', 'resource_id' => self::NEXUS_USER, 'actor_identity' => self::NEXUS_USER]);
        $row = $this->row();

        app(OptinexusEventRelay::class)->relay();

        $this->assertSame([], $this->nexusRequests('/api/v1/events'));
        $sent = $this->nexusRequests('/api/v1/audit-events')[0]['body'];
        $this->assertSame('sso.login', $sent['action']);
        $this->assertSame(self::NEXUS_TENANT, $sent['tenant_id']);
        $this->assertSame($row->id, $sent['metadata']['outbox_id'], 'the audit endpoint has no idempotency key, so a duplicate is recognisable');
        $this->assertSame('DELIVERED', $this->row()->status);
    }

    public function test_a_platform_level_row_is_sent_without_a_tenant(): void
    {
        $this->publishEvent(null);

        app(OptinexusEventRelay::class)->relay();

        $this->assertArrayNotHasKey('tenant_id', $this->nexusRequests('/api/v1/events')[0]['body']);
    }

    public function test_an_unknown_event_type_is_retried_with_backoff_and_parked_after_the_limit(): void
    {
        config(['optiaccounting.optinexus.relay.max_attempts' => 3]);
        $this->publishEvent($this->linkedTenant()->id);
        $this->nexus['event_response'] = [422, ['success' => false, 'error' => ['code' => 'EVENT_INVALID']]];
        $relay = app(OptinexusEventRelay::class);

        $this->assertSame(1, $relay->relay()['retrying']);
        $this->assertSame(1, $this->row()->attempts);
        $this->assertStringContainsString('Event Catalog', $this->row()->last_error);

        $this->assertSame(['delivered' => 0, 'retrying' => 0, 'failed' => 0], $relay->relay(), 'not before the backoff (2 min after the first attempt)');
        $this->travel(121)->seconds();
        $this->assertSame(1, $relay->relay()['retrying']);
        $this->assertSame(2, $this->row()->attempts);

        $this->travel(241)->seconds();
        $this->assertSame(1, $relay->relay()['failed'], 'third attempt reaches the limit');
        $this->assertSame('FAILED', $this->row()->status);

        // Once the type is registered, an operator re-queues the parked rows.
        $this->nexus['event_response'] = [201, ['success' => true]];
        $this->artisan('optiaccounting:nexus:relay-events', ['--retry-failed' => true])->assertSuccessful()->expectsOutputToContain('Delivered 1');
        $this->assertSame('DELIVERED', $this->row()->status);
    }

    public function test_a_payload_optinexus_rejects_fails_at_once(): void
    {
        $this->publishEvent($this->linkedTenant()->id);
        $this->nexus['event_response'] = [422, ['success' => false, 'error' => ['code' => 'VALIDATION_FAILED']]];

        $this->assertSame(1, app(OptinexusEventRelay::class)->relay()['failed']);
        $this->assertSame('FAILED', $this->row()->status);
        $this->assertStringContainsString('HTTP 422', $this->row()->last_error);

        $this->nexus['event_response'] = [409, ['success' => false, 'error' => ['code' => 'EVENT_DUPLICATE']]];
        DB::table('outbox_events')->update(['status' => 'PENDING', 'attempts' => 0, 'last_attempted_at' => null]);
        $this->assertSame(1, app(OptinexusEventRelay::class)->relay()['failed']);
    }

    public function test_server_errors_and_throttling_are_retried_not_parked(): void
    {
        $this->publishEvent($this->linkedTenant()->id);

        foreach ([500, 503, 429, 408] as $status) {
            DB::table('outbox_events')->update(['status' => 'PENDING', 'attempts' => 0, 'last_attempted_at' => null]);
            $this->nexus['event_response'] = [$status, []];
            $this->assertSame(1, app(OptinexusEventRelay::class)->relay()['retrying'], (string) $status);
            $this->assertSame('PENDING', $this->row()->status);
        }
    }

    public function test_an_unreachable_optinexus_stops_the_batch_instead_of_hammering_it(): void
    {
        $tenant = $this->linkedTenant();
        $this->publishEvent($tenant->id);
        $this->publishEvent($tenant->id, 'membership.provisioned');
        $this->publishEvent($tenant->id, 'membership.deactivated');
        $this->nexus['down'] = true;

        $result = app(OptinexusEventRelay::class)->relay();

        $this->assertSame(1, $result['retrying']);
        $this->assertSame(1, DB::table('outbox_events')->where('attempts', 1)->count());
        $this->assertSame(2, DB::table('outbox_events')->where('attempts', 0)->count(), 'the rest of the batch was not even tried');

        $this->nexus['down'] = false;
        $this->travel(121)->seconds();
        $this->assertSame(3, app(OptinexusEventRelay::class)->relay()['delivered']);
    }

    public function test_an_expired_service_token_is_renewed_once(): void
    {
        $tenant = $this->linkedTenant();
        $this->publishEvent($tenant->id);
        app(OptinexusEventRelay::class)->relay(); // fetches service-token-1
        $this->publishEvent($tenant->id, 'membership.provisioned');
        Cache::forget('optinexus.service.token');
        Cache::put('optinexus.service.token', 'revoked-token', 600); // OptiNexus no longer accepts it

        $this->assertSame(1, app(OptinexusEventRelay::class)->relay()['delivered']);
        $this->assertSame(2, $this->nexus['service_tokens']);
    }

    public function test_rows_of_a_tenant_that_is_not_linked_stay_untouched(): void
    {
        $unlinked = $this->tenant('plain-co', subscribed: false);
        $this->publishEvent($unlinked->id);

        $this->assertSame(['delivered' => 0, 'retrying' => 0, 'failed' => 0], app(OptinexusEventRelay::class)->relay());
        $this->assertSame(0, $this->row()->attempts);
        $this->assertSame([], $this->nexusRequests('/api/v1/events'));
    }

    public function test_the_batch_size_is_respected_and_the_oldest_go_first(): void
    {
        config(['optiaccounting.optinexus.relay.batch_size' => 2]);
        $tenant = $this->linkedTenant();
        foreach (['tenant.linked', 'membership.provisioned', 'membership.deactivated'] as $type) {
            $this->publishEvent($tenant->id, $type);
        }

        $this->assertSame(2, app(OptinexusEventRelay::class)->relay()['delivered']);
        $this->assertSame('optiaccounting.membership.deactivated', DB::table('outbox_events')->where('status', 'PENDING')->value('event_type'));
    }

    public function test_the_relay_command_does_nothing_outside_optinexus_mode(): void
    {
        $this->publishEvent($this->linkedTenant()->id);
        config(['optiaccounting.identity_mode' => 'standalone']);

        $this->artisan('optiaccounting:nexus:relay-events')->assertSuccessful()->expectsOutputToContain('nothing to relay');
        $this->assertSame('PENDING', $this->row()->status);
    }

    public function test_every_event_the_application_publishes_is_in_the_catalog_that_the_manifest_registers(): void
    {
        $session = $this->signedIn();
        $this->logoutScopeTenant();

        $published = DB::table('outbox_events')->where('kind', 'EVENT')->distinct()->pluck('event_type')->all();

        $this->assertNotEmpty($published);
        $this->assertSame([], array_diff($published, EventCatalog::keys()), 'an event that is not in the catalog would be refused by OptiNexus');
        $this->assertNotNull($session);
    }

    private function logoutScopeTenant(): void
    {
        $token = $this->nexusLogoutToken(accessRevoked: true, revoked: ['reason' => 'tenant_membership_removed', 'scope' => 'tenant', 'tenant_id' => self::NEXUS_TENANT]);
        $this->post('/api/v1/integration/optinexus/backchannel-logout', ['logout_token' => $token])->assertOk();
    }
}
