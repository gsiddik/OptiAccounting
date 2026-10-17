<?php

namespace Tests\Concurrency;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\ReceiveResult;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\RaceHelpers;
use Tests\Support\RaceRunner;

/**
 * OA6 gate: parallel deliveries of the same event. Workers are separate PHP processes receiving into the real inbox. Invariants: one row per
 * (connection, source_system, event_id) however many deliveries race, exactly one of them creates it, the rest are duplicates (same
 * content) or conflicts (different content), and the stored content is the creator's.
 */
class IntegrationConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, PayablesFixtures, RaceHelpers;

    private Tenant $tenant;

    private IntegrationConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->tenant();
        $this->connection = $this->connectionFor($this->tenant);
    }

    private function inboxJob(array $envelope): array
    {
        return ['mode' => 'inbox', 'tenant_id' => $this->tenant->id, 'connection_id' => $this->connection->id, 'envelope' => $envelope];
    }

    private function race(array $envelopes): array
    {
        $results = (new RaceRunner)->start(array_map(fn ($e) => $this->inboxJob($e), $envelopes))->results();
        foreach ($results as $i => $result) {
            $this->assertSame(200, $result['status'], "worker {$i}: ".json_encode($result['body']));
        }

        return $results;
    }

    public function test_parallel_deliveries_of_one_event_create_exactly_one_row(): void
    {
        $envelope = $this->envelope(['event_id' => 'evt-race']);

        $results = $this->race(array_fill(0, 8, $envelope));

        $outcomes = array_count_values(array_map(fn ($r) => $r['body']['outcome'], $results));
        $this->assertEquals([ReceiveResult::CREATED => 1, ReceiveResult::DUPLICATE => 7], $outcomes);
        $this->assertSame(1, DB::table('external_events')->count());
        $this->assertSame(1, count(array_unique(array_map(fn ($r) => $r['body']['id'], $results))));
        $row = DB::table('external_events')->first();
        $this->assertSame([7, false, 'RECEIVED'], [(int) $row->duplicate_count, (bool) $row->conflict, $row->status]);
    }

    public function test_parallel_deliveries_with_different_content_leave_the_first_content_and_flag_the_rest(): void
    {
        $variants = [];
        foreach (range(1, 6) as $i) {
            $variants[] = $this->envelope(['event_id' => 'evt-race', 'correlation_id' => "variant-{$i}"]);
        }

        $results = $this->race($variants);

        $outcomes = array_count_values(array_map(fn ($r) => $r['body']['outcome'], $results));
        $this->assertSame(1, $outcomes[ReceiveResult::CREATED]);
        $this->assertSame(5, $outcomes[ReceiveResult::CONFLICT] ?? 0);
        $this->assertSame(1, DB::table('external_events')->count());
        $row = DB::table('external_events')->first();
        $this->assertTrue((bool) $row->conflict);
        $created = array_values(array_filter($results, fn ($r) => $r['body']['outcome'] === ReceiveResult::CREATED))[0];
        $winner = array_search($created, $results, true);
        $this->assertSame('variant-'.($winner + 1), json_decode($row->envelope, true)['correlation_id'], 'the stored content is the creator\'s, untouched by the conflicts');
        $this->assertSame(5, DB::table('audit_logs')->where('action', 'accounting.integration.event.conflict')->count());
    }

    /** A processor in flight holds the connection FOR SHARE; a pause needs it FOR UPDATE, so it waits for the processor and then holds the door shut (audit A20). */
    public function test_a_pause_waits_for_the_processing_in_flight_and_nothing_starts_after_it(): void
    {
        $token = $this->memberToken($this->tenant);
        $event = $this->storedEvent($this->tenant, $this->connection);

        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec("select 1 from integration_connections where id = '{$this->connection->id}' for share"); // EventProcessor, mid-transaction
        $pause = (new RaceRunner)->start([$this->job('POST', "/api/v1/app/integration/connections/{$this->connection->id}/pause", null, $token)], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $pause);

        $this->assertTrue($pause->running(), 'the pause waits for the processor');
        $this->assertSame('ACTIVE', DB::table('integration_connections')->where('id', $this->connection->id)->value('status'));
        $holder->commit();
        [$paused] = $pause->results();
        $this->assertSame(200, $paused['status']);
        $this->assertSame('PAUSED', DB::table('integration_connections')->where('id', $this->connection->id)->value('status'));

        // after the pause nothing is booked: a retry is refused and the event is as it was
        DB::table('external_events')->where('id', $event->id)->update(['status' => 'NEEDS_REVIEW', 'last_error_code' => 'PERIOD_CLOSED']);
        [$retry] = (new RaceRunner)->start([$this->job('POST', "/api/v1/app/integration/events/{$event->id}/retry", ['reason' => 'Period reopened'], $token)], delay: 0)->results();
        $this->assertSame([409, 'CONNECTION_NOT_ACTIVE'], [$retry['status'], $retry['body']['code']]);
        $this->assertSame(['NEEDS_REVIEW', 'PERIOD_CLOSED'], [DB::table('external_events')->where('id', $event->id)->value('status'), DB::table('external_events')->where('id', $event->id)->value('last_error_code')]);
    }

    /** A pause that is committing (connection locked FOR UPDATE) makes a retry that arrives meanwhile wait, then see PAUSED and refuse. */
    public function test_a_retry_arriving_while_a_pause_commits_waits_and_is_refused(): void
    {
        $token = $this->memberToken($this->tenant);
        $event = $this->storedEvent($this->tenant, $this->connection);
        DB::table('external_events')->where('id', $event->id)->update(['status' => 'NEEDS_REVIEW', 'last_error_code' => 'PERIOD_CLOSED']);

        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec("select 1 from integration_connections where id = '{$this->connection->id}' for update"); // the pause, mid-transaction
        $retry = (new RaceRunner)->start([$this->job('POST', "/api/v1/app/integration/events/{$event->id}/retry", ['reason' => 'Period reopened'], $token)], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $retry);

        $holder->exec("update integration_connections set status = 'PAUSED' where id = '{$this->connection->id}'");
        $holder->commit();
        [$result] = $retry->results();

        $this->assertSame([409, 'CONNECTION_NOT_ACTIVE'], [$result['status'], $result['body']['code']]);
        $this->assertSame('NEEDS_REVIEW', DB::table('external_events')->where('id', $event->id)->value('status'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'accounting.integration.event.retried')->count());
    }
}
