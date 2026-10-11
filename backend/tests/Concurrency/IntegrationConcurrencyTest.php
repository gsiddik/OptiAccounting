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
use Tests\Support\RaceRunner;

/**
 * OA6 gate: parallel deliveries of the same event. Workers are separate PHP processes receiving into the real inbox. Invariants: one row per
 * (connection, source_system, event_id) however many deliveries race, exactly one of them creates it, the rest are duplicates (same
 * content) or conflicts (different content), and the stored content is the creator's.
 */
class IntegrationConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures;

    private Tenant $tenant;

    private IntegrationConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->tenant();
        $this->connection = $this->connectionFor($this->tenant);
    }

    private function job(array $envelope): array
    {
        return ['mode' => 'inbox', 'tenant_id' => $this->tenant->id, 'connection_id' => $this->connection->id, 'envelope' => $envelope];
    }

    private function race(array $envelopes): array
    {
        $results = (new RaceRunner)->start(array_map(fn ($e) => $this->job($e), $envelopes))->results();
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
}
