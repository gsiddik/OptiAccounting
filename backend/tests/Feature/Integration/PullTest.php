<?php

namespace Tests\Feature\Integration;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\ProcessOutcome;
use App\Domain\Integration\Services\PullService;
use App\Domain\Shared\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA6: the pull reads the cursor feed page by page; the cursor moves only with stored events, failures are counted and isolated. */
class PullTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, PayablesFixtures;

    private const FEED = 'https://fleet.example.test/api/v1/integration/accounting-feed*';

    /** @return array<string,mixed> */
    private function page(array $envelopes, ?string $next, bool $more = false, ?string $highWater = null): array
    {
        return ['data' => $envelopes, 'next_cursor' => $next, 'has_more' => $more] + ($highWater === null ? [] : ['high_water' => $highWater]);
    }

    private function pull(IntegrationConnection $connection, $tenant): array
    {
        return $this->inTenant($tenant, fn () => app(PullService::class)->pull($connection));
    }

    public function test_pages_are_read_from_the_cursor_stored_processed_and_the_cursor_advances(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $adapter = $this->fakeAdapter();
        Http::fake([self::FEED => Http::sequence()
            ->push($this->page([$this->envelope(['event_id' => 'a', 'sequence' => 1]), $this->envelope(['event_id' => 'b', 'sequence' => 2])], '2', true))
            ->push($this->page([$this->envelope(['event_id' => 'c', 'sequence' => 3])], '3', false)),
        ]);

        $summary = $this->pull($connection, $tenant);

        $this->assertSame(['skipped' => false, 'halted' => false, 'pages' => 2, 'received' => 3, 'duplicates' => 0, 'conflicts' => 0, 'processed' => 3, 'posted' => 3, 'journals' => 0, 'error' => null], $summary);
        $requests = Http::recorded()->map(fn (array $pair) => [$pair[0]->url(), $pair[0]->header('Authorization')[0] ?? null])->values()->all();
        $this->assertSame('https://fleet.example.test/api/v1/integration/accounting-feed?limit=100', $requests[0][0]);
        $this->assertSame('https://fleet.example.test/api/v1/integration/accounting-feed?after=2&limit=100', $requests[1][0]);
        $this->assertSame('Bearer feed-secret-123', $requests[0][1]);
        $connection->refresh();
        $this->assertSame(['3', 0, null], [$connection->last_cursor, $connection->consecutive_failures, $connection->last_error]);
        $this->assertNotNull($connection->last_pulled_at);
        $this->assertSame(3, $adapter->calls);
        $this->assertSame([1, 2, 3], DB::table('external_events')->where('status', 'POSTED')->orderBy('sequence')->pluck('sequence')->map(fn ($s) => (int) $s)->all());
    }

    public function test_a_page_delivered_again_is_counted_as_duplicates_and_not_processed_again(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $adapter = $this->fakeAdapter();
        $events = [$this->envelope(['event_id' => 'a', 'sequence' => 1]), $this->envelope(['event_id' => 'b', 'sequence' => 2])];
        $changed = $events[1];
        $changed['payload']['total_amount'] = '1.00';
        Http::fake([self::FEED => Http::sequence()->push($this->page($events, '2'))->push($this->page([$events[0], ['sequence' => 2] + $changed, $this->envelope(['event_id' => 'c', 'sequence' => 3])], '3'))]);

        $this->pull($connection, $tenant);
        DB::table('integration_connections')->where('id', $connection->id)->update(['last_cursor' => null]); // the source replays its feed from the start
        $second = $this->pull($connection->refresh(), $tenant);

        $this->assertSame([1, 1, 1], [$second['received'], $second['duplicates'], $second['conflicts']]);
        $this->assertSame(3, $adapter->calls);
        $this->assertSame(3, DB::table('external_events')->count());
        $this->assertSame(1, DB::table('external_events')->where('conflict', true)->count());
        $this->assertSame('3', $connection->refresh()->last_cursor);
    }

    public function test_a_failed_call_keeps_the_cursor_and_five_failures_in_a_row_turn_the_connection_to_error(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '10']);
        Http::fake([self::FEED => Http::response('boom', 500)]);

        for ($i = 1; $i <= 4; $i++) {
            $summary = $this->pull($connection, $tenant);
            $connection->refresh();
            $this->assertSame('The source answered HTTP 500.', $summary['error']);
            $this->assertSame(['10', $i, IntegrationConnection::ACTIVE, 'The source answered HTTP 500.'], [$connection->last_cursor, $connection->consecutive_failures, $connection->status, $connection->last_error]);
        }
        $this->pull($connection, $tenant);
        $this->assertSame([IntegrationConnection::ERROR, 5, '10'], [$connection->refresh()->status, $connection->consecutive_failures, $connection->last_cursor]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.integration.connection.errored')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('external_events')->count());

        // An ERROR connection is not pulled; resume starts the count again.
        $this->expectException(DomainException::class);
        try {
            $this->pull($connection, $tenant);
        } finally {
            $this->as($this->memberToken($tenant));
            $this->postJson(self::IT."/connections/{$connection->id}/resume")->assertOk()->assertJsonPath('consecutive_failures', 0)->assertJsonPath('last_error', null);
        }
    }

    public function test_an_unreachable_source_a_bad_page_and_a_good_page_each_leave_the_right_trace(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '10']);
        $this->fakeAdapter();

        $answer = fn () => throw new ConnectionException('cURL error 28: timed out');
        Http::fake([self::FEED => function () use (&$answer) {
            return $answer();
        }]);
        $this->assertStringContainsString('could not be reached', $this->pull($connection, $tenant)['error']);
        $this->assertSame(1, $connection->refresh()->consecutive_failures);

        foreach ([['not a feed'], ['data' => 'x', 'has_more' => false], ['data' => [], 'has_more' => 'no'], ['data' => [], 'has_more' => false, 'next_cursor' => ['x']]] as $bad) {
            DB::table('integration_connections')->where('id', $connection->id)->update(['consecutive_failures' => 0]); // stay clear of the five-in-a-row ERROR
            $answer = fn () => Http::response($bad);
            $this->assertStringStartsWith('The source answered with an invalid feed page', $this->pull($connection, $tenant)['error']);
        }
        $this->assertSame('10', $connection->refresh()->last_cursor);

        DB::table('integration_connections')->where('id', $connection->id)->update(['consecutive_failures' => 3]);
        $answer = fn () => Http::response($this->page([], null));
        $this->assertNull($this->pull($connection, $tenant)['error']);
        $connection->refresh();
        $this->assertSame(['10', 0, null], [$connection->last_cursor, $connection->consecutive_failures, $connection->last_error]);
    }

    public function test_a_page_with_an_unidentifiable_envelope_is_stored_as_nothing_and_the_cursor_stays(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '10']);
        $this->fakeAdapter();
        Http::fake([self::FEED => Http::response($this->page([$this->envelope(['event_id' => 'good', 'sequence' => 11]), $this->envelope(['event_id' => '', 'sequence' => 12])], '12'))]);

        $summary = $this->pull($connection, $tenant);

        $this->assertStringContainsString('event_id', $summary['error']);
        $this->assertSame(0, DB::table('external_events')->count(), 'the page is stored whole or not at all');
        $this->assertSame(['10', 1], [$connection->refresh()->last_cursor, $connection->consecutive_failures]);
    }

    public function test_rejected_events_do_not_block_the_feed_and_one_failing_event_does_not_stop_the_others(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $this->fakeAdapter(function (ExternalEvent $event) {
            if ($event->event_id === 'bad') {
                throw new RuntimeException('adapter bug');
            }

            return new ProcessOutcome;
        });
        Http::fake([self::FEED => Http::response($this->page([
            $this->envelope(['event_id' => 'old', 'occurred_at' => '2020-01-01T00:00:00Z', 'sequence' => 1]),
            $this->envelope(['event_id' => 'bad', 'sequence' => 2]),
            $this->envelope(['event_id' => 'float', 'payload' => ['total_amount' => 10.5], 'sequence' => 3]),
            $this->envelope(['event_id' => 'ok', 'sequence' => 4]),
        ], '4'))]);

        $summary = $this->pull($connection, $tenant);

        $status = fn (string $id) => DB::table('external_events')->where('event_id', $id)->value('status');
        $this->assertSame(['REJECTED', 'FAILED', 'REJECTED', 'POSTED'], [$status('old'), $status('bad'), $status('float'), $status('ok')]);
        $this->assertSame([4, 2, 1, null], [$summary['received'], $summary['processed'], $summary['posted'], $summary['error']]);
        $this->assertFalse($summary['halted']);
        $this->assertSame('4', $connection->refresh()->last_cursor);
    }

    public function test_no_http_call_is_made_inside_a_database_transaction(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $this->fakeAdapter();
        $baseline = DB::transactionLevel();
        $levels = [];
        Http::fake([self::FEED => function () use (&$levels) {
            $levels[] = DB::transactionLevel();

            return Http::response($this->page([$this->envelope(['sequence' => count($levels)])], (string) count($levels), count($levels) < 2));
        }]);

        $this->pull($connection, $tenant);

        $this->assertCount(2, $levels);
        $this->assertSame([$baseline, $baseline], $levels);
    }

    public function test_sync_now_pulls_once_for_a_manager_and_is_refused_for_an_inactive_connection_or_a_running_pull(): void
    {
        $tenant = $this->tenant();
        $active = $this->connectionFor($tenant);
        $paused = $this->connectionFor($tenant, ['source_tenant_reference' => 'p', 'status' => IntegrationConnection::PAUSED]);
        $this->fakeAdapter();
        Http::fake([self::FEED => Http::response($this->page([$this->envelope(['sequence' => 1])], '1'))]);

        $viewer = $this->memberToken($tenant, ['accounting.integration.view']);
        $this->as($viewer)->postJson(self::IT."/connections/{$active->id}/sync-now")->assertForbidden();
        $this->as($this->memberToken($tenant))->postJson(self::IT."/connections/{$paused->id}/sync-now")->assertStatus(409)->assertJsonPath('code', 'CONNECTION_NOT_ACTIVE');
        Http::assertNothingSent();

        $this->postJson(self::IT."/connections/{$active->id}/sync-now")->assertOk()->assertJsonPath('received', 1)->assertJsonPath('posted', 1)->assertJsonPath('error', null);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.integration.connection.sync_now')->where('resource_id', $active->id)->count());

        // A pull running elsewhere (another session holds the connection's advisory lock) makes "sync now" wait its turn instead of overlapping.
        $c = config('database.connections.'.config('database.default'));
        $other = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
        $other->prepare('select pg_advisory_lock(hashtext(?), hashtext(?))')->execute(['integration.pull', $active->id]);
        $this->postJson(self::IT."/connections/{$active->id}/sync-now")->assertStatus(409)->assertJsonPath('code', 'PULL_IN_PROGRESS');
        $other = null; // closing the session releases the lock
        $this->postJson(self::IT."/connections/{$active->id}/sync-now")->assertOk();
    }

    public function test_the_scheduled_pull_honours_the_interval_the_status_and_the_tenants_entitlement(): void
    {
        $alpha = $this->tenant('alpha');
        $bravo = $this->tenant('bravo');
        $this->fakeAdapter();
        $started = now()->subSeconds(5)->format('Y-m-d H:i:s');
        $never = $this->connectionFor($alpha, ['source_tenant_reference' => 'never']);
        $recent = $this->connectionFor($alpha, ['source_tenant_reference' => 'recent', 'last_pulled_at' => now()->subMinutes(2), 'pull_interval_minutes' => 15]);
        $elapsed = $this->connectionFor($alpha, ['source_tenant_reference' => 'elapsed', 'last_pulled_at' => now()->subMinutes(5), 'pull_interval_minutes' => 5]);
        $paused = $this->connectionFor($alpha, ['source_tenant_reference' => 'paused', 'status' => IntegrationConnection::PAUSED]);
        $overdue = $this->connectionFor($bravo);
        DB::table('subscriptions')->where('tenant_id', $bravo->id)->update(['status' => 'PAST_DUE']);
        app(AccessCache::class)->touchTenant($bravo->id);
        Http::fake([self::FEED => Http::response($this->page([], null))]);

        $this->artisan('integration:pull')->assertSuccessful();

        $pulledAt = fn (IntegrationConnection $c) => (string) DB::table('integration_connections')->where('id', $c->id)->value('last_pulled_at') >= $started;
        $this->assertSame([true, false, true, false, false], array_map($pulledAt, [$never, $recent, $elapsed, $paused, $overdue]));
        Http::assertSentCount(2);
    }
}
