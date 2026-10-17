<?php

namespace Tests\Feature\Integration;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\ConnectionHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\OptiFleetFixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/**
 * Ecosystem audit A27 and A17: what happened to a processed event in the books NOW (processing finished is not "in the ledger"), the health of a
 * connection (alerts for what a person has to look at), and the read-only `integration:health` command an operator or a scheduler runs.
 */
class IntegrationHealthTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, OptiFleetFixtures, PayablesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeFleet();
    }

    private function summary(Tenant $tenant, IntegrationConnection $connection): array
    {
        return $this->as($this->memberToken($tenant))->getJson(self::IT."/connections/{$connection->id}/summary")->assertOk()->json();
    }

    /** @return array<string,array<string,mixed>> the alerts of the connection by code */
    private function alerts(Tenant $tenant, IntegrationConnection $connection): array
    {
        return collect($this->summary($tenant, $connection)['health']['alerts'])->keyBy('code')->all();
    }

    private function invoice(string $number): object
    {
        return DB::table('ap_invoices')->where('vendor_invoice_number', $number)->orderByDesc('created_at')->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function memo(string $type): array
    {
        return $this->fleetEvent("optifleet.maintenance_memo.{$type}", [
            'informational' => true, 'memo_number' => 'MM-001', 'memo_cost' => '1000000.0000', 'currency' => 'IDR', 'vendor' => ['id' => 'ven-1', 'code' => 'BNK-01', 'name' => 'Bengkel Maju', 'tax_id' => null],
            'workshop_invoice' => null, 'snapshot_basis' => 'event_time',
        ]);
    }

    // ------------------------------------------------------------------------------------------------ A27: books state

    public function test_a_processed_event_is_in_the_ledger_only_when_its_document_is_and_the_figure_follows_the_document_live(): void
    {
        [$tenant, $connection] = $this->fleetTenant('alpha', []); // approval required
        $token = $this->memberToken($tenant);
        $recorded = $this->recordedEvent();
        $this->pullFleet($tenant, $connection, [$recorded]);

        // Processing finished, nothing is in the general ledger yet.
        $books = $this->summary($tenant, $connection);
        $this->assertSame([1, 1, 0, 1], [$books['processed'], $books['books']['AWAITING_APPROVAL'], $books['books']['IN_LEDGER'], $books['outcomes']['AWAITING_APPROVAL']]);
        $detail = $this->as($token)->getJson(self::IT.'/events/'.$this->eventRow($recorded)->id)->assertOk();
        $detail->assertJsonPath('status', 'POSTED')->assertJsonPath('books_state', 'AWAITING_APPROVAL')->assertJsonPath('books.state', 'AWAITING_APPROVAL')
            ->assertJsonPath('books.documents.0.document_type', 'ap_invoice')->assertJsonPath('books.documents.0.status', 'SUBMITTED')->assertJsonPath('books.documents.0.journal_id', null);

        // Approved but not posted is still not in the ledger; posted it is. The event itself never changed.
        $invoice = $this->invoice('INV-001');
        $this->postJson(self::AP."/ap-invoices/{$invoice->id}/approve")->assertOk();
        $this->assertSame([1, 0], [$this->summary($tenant, $connection)['books']['AWAITING_APPROVAL'], $this->summary($tenant, $connection)['books']['IN_LEDGER']]);
        $before = (array) $this->eventRow($recorded);
        $this->postJson(self::AP."/ap-invoices/{$invoice->id}/post")->assertOk();
        $after = $this->summary($tenant, $connection);
        $this->assertSame([0, 1, 1, 1], [$after['books']['AWAITING_APPROVAL'], $after['books']['IN_LEDGER'], $after['processed'], $after['outcomes']['AWAITING_APPROVAL']]);
        $this->as($token)->getJson(self::IT.'/events/'.$this->eventRow($recorded)->id)->assertOk()->assertJsonPath('books_state', 'IN_LEDGER')
            ->assertJsonPath('books.documents.0.journal_status', 'POSTED');
        $this->assertEquals($before, (array) $this->eventRow($recorded), 'reading the books state never writes to the event');
    }

    public function test_every_state_a_document_can_be_in_is_counted_apart_and_the_list_carries_it_per_event(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $second = $this->recordedEvent(['invoice_number' => 'INV-002'], ['id' => 'wi-2', 'number' => 'INV-002']);
        $corrected = $this->fleetEvent('optifleet.workshop_invoice.corrected', $this->fleetPayload([
            'invoice_number' => 'INV-002', 'subtotal' => '2000000.0000', 'tax_total' => '220000.0000', 'total_amount' => '2220000.0000',
            'lines' => [['description' => 'Overhaul', 'quantity' => '1.0000', 'unit_price' => '2000000.0000', 'line_total' => '2000000.0000']],
        ]) + ['correction' => ['id' => 'cor-1', 'reason' => 'Harga salah', 'previous_values' => ['total_amount' => '1110000.0000'], 'requested_values' => ['total_amount' => '2220000.0000']]],
            ['id' => 'wi-2', 'number' => 'INV-002'], ['occurred_at' => '2026-03-12T09:00:00.000Z']);
        $events = [$this->recordedEvent(), $second, $corrected, $this->memo('billed')];
        $unbooked = $this->paymentEvent(['id' => 'pay-9'], ['id' => 'wi-9', 'number' => 'INV-009'], ['invoice_number' => 'INV-009']); // no such invoice: needs review, books nothing
        $this->pullFleet($tenant, $connection, [...$events, $unbooked]);

        $summary = $this->summary($tenant, $connection);
        $this->assertSame(['IN_LEDGER' => 2, 'AWAITING_APPROVAL' => 0, 'DRAFT' => 0, 'REVERSED' => 1, 'VOID' => 0, 'INFORMATIONAL' => 1, 'NO_EFFECT' => 0, 'UNCLASSIFIED' => 0], $summary['books']);
        $this->assertSame(4, $summary['processed'], 'an event that needs review is not counted as processed or as booked');

        $listed = $this->as($this->memberToken($tenant))->getJson(self::IT."/events?connection_id={$connection->id}&per_page=50")->assertOk()->json('data');
        $byEvent = collect($listed)->mapWithKeys(fn ($row) => [$row['event_id'] => $row['books_state']])->all();
        $this->assertSame(['IN_LEDGER', 'REVERSED', 'IN_LEDGER', 'INFORMATIONAL', null], array_map(fn ($e) => $byEvent[$e['event_id']], [...$events, $unbooked]));
    }

    public function test_a_document_left_as_a_draft_is_a_draft_and_not_booked(): void
    {
        [$tenant, $connection] = $this->fleetTenant('alpha', ['approval_required' => false, 'sod_creator_not_poster' => true]);
        $this->pullFleet($tenant, $connection, [$this->recordedEvent()]);

        $summary = $this->summary($tenant, $connection);

        $this->assertSame([1, 0, 1], [$summary['books']['DRAFT'], $summary['books']['IN_LEDGER'], $summary['processed']]);
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_the_list_reads_the_books_state_of_a_whole_page_with_one_set_of_queries(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $events = [];
        foreach (range(1, 6) as $n) {
            $events[] = $this->recordedEvent(['invoice_number' => "INV-10{$n}"], ['id' => "wi-10{$n}", 'number' => "INV-10{$n}"]);
        }
        $this->pullFleet($tenant, $connection, $events);
        $token = $this->memberToken($tenant);

        DB::enableQueryLog();
        $this->as($token)->getJson(self::IT."/events?connection_id={$connection->id}&per_page=50")->assertOk()->assertJsonCount(6, 'data');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(1, $queries->filter(fn ($q) => str_contains($q, 'external_source_links'))->count());
        $this->assertSame(1, $queries->filter(fn ($q) => str_contains($q, 'from "ap_invoices"'))->count());
    }

    // ------------------------------------------------------------------------------------------------ A17: alerts

    public function test_a_healthy_connection_has_no_alerts(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $this->pullFleet($tenant, $connection, [$this->recordedEvent()]);

        $health = $this->summary($tenant, $connection)['health'];

        $this->assertSame(['OK', []], [$health['level'], $health['alerts']]);
        $this->as($this->memberToken($tenant))->getJson(self::IT.'/connections')->assertOk()->assertJsonPath('data.0.health_level', 'OK')->assertJsonPath('data.0.access.state', 'OK');
    }

    public function test_a_payment_waiting_for_an_invoice_that_never_arrived_becomes_an_alert_after_the_grace_period(): void
    {
        config(['optientry.integration.orphan_grace_minutes' => 60]);
        [$tenant, $connection] = $this->fleetTenant();
        $payment = $this->paymentEvent();
        $this->pullFleet($tenant, $connection, [$payment]);
        $this->assertSame(['NEEDS_REVIEW', 'INVOICE_NOT_FOUND'], $this->outcomeOf($payment));

        // Just received: the invoice may be on its way, so it is a wait, not an alert.
        $this->assertArrayNotHasKey('MISSING_RECORDED_EVENT', $this->alerts($tenant, $connection));

        Carbon::setTestNow(now()->addMinutes(61));
        $alert = $this->alerts($tenant, $connection)['MISSING_RECORDED_EVENT'];
        $this->assertSame(['ALERT', 1], [$alert['severity'], $alert['count']]);
        $this->assertSame('ALERT', $this->summary($tenant, $connection)['health']['level']);

        // The source repairs it (the invoice event arrives) and a person retries the payment: the alert ends.
        $this->pullFleet($tenant, $connection, [$this->recordedEvent()]);
        $this->as($this->memberToken($tenant))->postJson(self::IT.'/events/'.$this->eventRow($payment)->id.'/retry', ['reason' => 'The invoice arrived'])->assertOk()->assertJsonPath('status', 'POSTED');
        $this->assertArrayNotHasKey('MISSING_RECORDED_EVENT', $this->alerts($tenant, $connection));
    }

    public function test_quarantined_events_conflicts_and_dead_letters_are_each_an_alert_with_their_count(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $rebuilt = $this->recordedEvent(['snapshot_basis' => 'backfill_current_state', 'invoice_number' => 'INV-201'], ['id' => 'wi-201', 'number' => 'INV-201']);
        $conflicted = $this->recordedEvent(['invoice_number' => 'INV-202'], ['id' => 'wi-202', 'number' => 'INV-202', 'type' => 'workshop_invoice'], ['event_id' => 'evt-conflict']);
        $dead = $this->recordedEvent(['invoice_number' => 'INV-203'], ['id' => 'wi-203', 'number' => 'INV-203']);
        $this->pullFleet($tenant, $connection, [$rebuilt, $conflicted, $dead]);
        $changed = $conflicted;
        $changed['payload']['total_amount'] = '9999999.0000';
        $this->receive($tenant, $connection->refresh(), $changed); // the source sends different content under the same event id
        DB::table('external_events')->where('event_id', $dead['event_id'])->update(['status' => 'DEAD_LETTER', 'attempts' => 5]);

        $alerts = $this->alerts($tenant, $connection);

        $this->assertSame(['WARN', 1], [$alerts['QUARANTINED_EVENTS']['severity'], $alerts['QUARANTINED_EVENTS']['count']]);
        $this->assertSame(['WARN', 1], [$alerts['OPEN_CONFLICTS']['severity'], $alerts['OPEN_CONFLICTS']['count']]);
        $this->assertSame(['ALERT', 1], [$alerts['DEAD_LETTERS']['severity'], $alerts['DEAD_LETTERS']['count']]);
        $this->assertSame('ALERT', $this->summary($tenant, $connection)['health']['level']);
    }

    public function test_a_connection_that_has_not_pulled_for_far_longer_than_its_interval_is_overdue_and_a_stalled_queue_is_flagged(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        DB::table('integration_connections')->where('id', $connection->id)->update(['last_pulled_at' => now(), 'pull_interval_minutes' => 5]);
        $this->storedEvent($tenant, $connection, ['event_id' => 'waiting-1', 'sequence' => 1]);
        $this->storedEvent($tenant, $connection, ['event_id' => 'waiting-2', 'sequence' => 2]);
        $this->assertSame([], array_keys($this->alerts($tenant, $connection)));

        Carbon::setTestNow(now()->addMinutes(45));
        $alerts = $this->alerts($tenant, $connection);

        $this->assertSame('WARN', $alerts['PULL_OVERDUE']['severity']);
        $this->assertGreaterThanOrEqual(45, $alerts['PULL_OVERDUE']['minutes']);
        $this->assertSame(['WARN', 2], [$alerts['BACKLOG_STALLED']['severity'], $alerts['BACKLOG_STALLED']['due']]);
    }

    public function test_when_optientry_itself_holds_the_connection_that_is_said_apart_from_the_sources_state(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $featureId = DB::table('features')->where('code', 'OPTIFLEET_CONNECTOR')->value('id');
        DB::table('tenant_feature_entitlements')->where('tenant_id', $tenant->id)->where('feature_id', $featureId)->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($tenant->id);

        $summary = $this->summary($tenant, $connection);

        $this->assertNotNull($summary['access']['entry']);
        $this->assertContains($summary['access']['entry']['code'], ['FEATURE_NOT_ENABLED', 'CONNECTOR_NOT_ENTITLED']);
        $alert = collect($summary['health']['alerts'])->firstWhere('code', 'ENTRY_HELD');
        $this->assertSame('WARN', $alert['severity']);
        $this->assertNotSame([], $alert['reasons']);
        $this->assertSame('UNKNOWN', $summary['access']['state'], 'the source was never asked, so nothing is said about it');

        $this->inTenant($tenant, function () use ($connection) {
            DB::table('tenants')->where('id', $connection->tenant_id)->update(['status' => 'SUSPENDED']);
            $this->assertSame('TENANT_NOT_ACTIVE', app(ConnectionHealth::class)->heldBy($connection->refresh())['code']);
        });
    }

    // ------------------------------------------------------------------------------------------------ the command

    public function test_the_health_command_exits_non_zero_and_logs_critical_only_for_what_needs_a_person(): void
    {
        [$alpha, $broken] = $this->fleetTenant('alpha');
        [$bravo, $fine] = $this->fleetTenant('bravo');
        $this->pullFleet($bravo, $fine, [$this->recordedEvent()]);
        $this->pullFleet($alpha, $broken, [$this->recordedEvent(['invoice_number' => 'INV-301'], ['id' => 'wi-301', 'number' => 'INV-301'])]);
        DB::table('external_events')->where('tenant_id', $alpha->id)->update(['status' => 'DEAD_LETTER', 'attempts' => 5]);
        $paused = $this->connectionFor($this->tenant('charlie'), ['status' => IntegrationConnection::PAUSED, 'last_pulled_at' => now()->subDays(3)]);
        Log::spy();

        $exit = Artisan::call('integration:health', ['--json' => true]);
        $findings = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertCount(1, $findings, 'a healthy connection and a paused one are not reported');
        $this->assertSame(['alpha', 'ALERT', 'DEAD_LETTERS'], [$findings[0]['tenant'], $findings[0]['level'], $findings[0]['alerts'][0]['code']]);
        Log::shouldHaveReceived('critical')->once()->with('integration.health', \Mockery::on(fn ($context) => $context['connection_id'] === $broken->id && $context['level'] === 'ALERT'));
        $this->assertNotNull($paused);
    }

    public function test_a_warning_is_listed_but_does_not_fail_the_command_and_a_clean_run_says_so(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $this->artisan('integration:health')->expectsOutputToContain('healthy')->assertExitCode(0);

        DB::table('integration_connections')->where('id', $connection->id)->update(['last_pulled_at' => now()->subHours(5)]);
        $this->artisan('integration:health')->expectsOutputToContain('PULL_OVERDUE')->assertExitCode(0);
    }

    public function test_the_health_command_changes_nothing(): void
    {
        [$tenant, $connection] = $this->fleetTenant();
        $this->pullFleet($tenant, $connection, [$this->recordedEvent(), $this->paymentEvent(['id' => 'pay-8'], ['id' => 'wi-8', 'number' => 'INV-008'], ['invoice_number' => 'INV-008'])]);
        DB::table('external_events')->where('tenant_id', $tenant->id)->where('status', 'NEEDS_REVIEW')->update(['received_at' => now()->subDays(2)]);
        $snapshot = fn () => [
            DB::table('integration_connections')->get()->toArray(), DB::table('external_events')->orderBy('id')->get()->toArray(),
            DB::table('journal_entries')->count(), DB::table('ap_invoices')->count(), DB::table('audit_logs')->count(),
        ];
        $before = $snapshot();

        $this->artisan('integration:health')->assertExitCode(1);

        $this->assertEquals($before, $snapshot());
    }

    public function test_the_health_check_runs_every_hour_on_the_scheduler(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('integration:health')->assertExitCode(0);
    }
}
