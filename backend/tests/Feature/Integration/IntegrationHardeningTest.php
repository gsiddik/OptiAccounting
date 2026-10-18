<?php

namespace Tests\Feature\Integration;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Contracts\ConnectorRequirements;
use App\Domain\Integration\Contracts\IntegrationAdapter;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\AdapterRegistry;
use App\Domain\Integration\Services\ConnectionService;
use App\Domain\Integration\Services\EventProcessor;
use App\Domain\Integration\Services\IntegrationRunner;
use App\Domain\Integration\Services\ProcessOutcome;
use App\Domain\Integration\Services\PullService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/**
 * Ecosystem audit A17, A18, A20, A21, A22 and A28 on the integration core: what a pause, a disconnect, a changed payload, a malformed feed or a
 * large backlog may and may not do to the books. The source is faked from the feed contract; the inbox, the processor, the pull and the lifecycle
 * are the real ones.
 */
class IntegrationHardeningTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, PayablesFixtures;

    private const FEED = 'https://fleet.example.test/api/v1/integration/accounting-feed*';

    /** @return array<string,mixed> */
    private function page(array $envelopes, ?string $next = null, bool $more = false, ?string $highWater = null): array
    {
        return ['data' => $envelopes, 'next_cursor' => $next, 'has_more' => $more] + ($highWater === null ? [] : ['high_water' => $highWater]);
    }

    private function pull(IntegrationConnection $connection, Tenant $tenant): array
    {
        return $this->inTenant($tenant, fn () => app(PullService::class)->pull($connection));
    }

    private function stateOf(string $eventId): array
    {
        $row = DB::table('external_events')->where('event_id', $eventId)->first();

        return [$row->status, $row->last_error_code];
    }

    // ------------------------------------------------------------------------------------------------ A22

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function malformedPages(): array
    {
        $e = fn (mixed $sequence, string $id = 'e') => ['sequence' => $sequence, 'event_id' => $id];

        return [
            'not an object' => [['not a feed']],
            'data is not a list' => [['data' => 'x', 'has_more' => false]],
            'has_more is not a boolean' => [['data' => [], 'has_more' => 'no']],
            'sequence is a float' => [['data' => [$e(11.5)], 'next_cursor' => '11', 'has_more' => false]],
            'sequence is negative' => [['data' => [$e('-1')], 'next_cursor' => '-1', 'has_more' => false]],
            'sequence has a leading zero' => [['data' => [$e('011')], 'next_cursor' => '11', 'has_more' => false]],
            'sequence is not numeric' => [['data' => [$e('abc')], 'next_cursor' => 'abc', 'has_more' => false]],
            'sequence exceeds 64 bits' => [['data' => [$e('9223372036854775808')], 'next_cursor' => '9223372036854775808', 'has_more' => false]],
            'sequence skips a number' => [['data' => [$e(12)], 'next_cursor' => '12', 'has_more' => false]],
            'sequence repeats the cursor' => [['data' => [$e(10)], 'next_cursor' => '10', 'has_more' => false]],
            'sequences go down' => [['data' => [$e(11, 'a'), $e(10, 'b')], 'next_cursor' => '10', 'has_more' => false]],
            'sequences repeat' => [['data' => [$e(11, 'a'), $e(11, 'b')], 'next_cursor' => '11', 'has_more' => false]],
            'next_cursor is not the last sequence' => [['data' => [$e(11)], 'next_cursor' => '12', 'has_more' => false]],
            'next_cursor is missing' => [['data' => [$e(11)], 'has_more' => false]],
            'an empty page says more follow' => [['data' => [], 'next_cursor' => null, 'has_more' => true]],
            'an empty page moves the cursor' => [['data' => [], 'next_cursor' => '11', 'has_more' => false]],
            'an event lies beyond the high-water mark' => [['data' => [$e(11)], 'next_cursor' => '11', 'has_more' => false, 'high_water' => '10']],
            'the high-water mark is not a sequence' => [['data' => [], 'next_cursor' => null, 'has_more' => false, 'high_water' => '1.5']],
        ];
    }

    #[DataProvider('malformedPages')]
    public function test_a_malformed_page_never_moves_the_cursor_or_stores_anything(array $page): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '10']);
        $this->fakeAdapter();
        Http::fake([self::FEED => Http::response($page)]);

        $summary = $this->pull($connection, $tenant);

        $this->assertStringStartsWith('The source answered with an invalid feed page', (string) $summary['error']);
        $this->assertSame(0, DB::table('external_events')->count());
        $connection->refresh();
        $this->assertSame(['10', 1], [$connection->last_cursor, $connection->consecutive_failures]);
    }

    public function test_a_page_larger_than_requested_is_refused_and_digit_string_or_integer_sequences_are_accepted(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $this->fakeAdapter();
        $many = array_map(fn (int $n) => $this->envelope(['event_id' => "e{$n}", 'sequence' => $n]), range(1, 101));
        $answer = $this->page($many, '101');
        Http::fake([self::FEED => function () use (&$answer) {
            return Http::response($answer);
        }]);
        $this->assertStringContainsString('more events than requested', $this->pull($connection, $tenant)['error']);
        $this->assertSame([0, null], [DB::table('external_events')->count(), $connection->refresh()->last_cursor]);

        $answer = $this->page([$this->envelope(['event_id' => 'a', 'sequence' => 1]), $this->envelope(['event_id' => 'b', 'sequence' => '2'])], '2', false, '2');
        $this->assertSame(2, $this->pull($connection, $tenant)['received']);
        $this->assertSame('2', $connection->refresh()->last_cursor);
    }

    // ------------------------------------------------------------------------------------------------ A18

    /** Pull to cursor 2, pause, the source goes on to 5 meanwhile, switch on again, then pull: events 3-5 are the gap, 6 is new. */
    private function gapScenario(string $policy): array
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'Asia/Jakarta'));
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '2', 'gap_policy' => $policy]);
        $adapter = $this->fakeAdapter();
        $this->as($this->memberToken($tenant))->postJson(self::IT."/connections/{$connection->id}/pause")->assertOk();

        $during = fn (int $n) => $this->envelope(['event_id' => "gap-{$n}", 'sequence' => $n, 'occurred_at' => '2026-06-15T09:00:00+07:00']);
        $after = $this->envelope(['event_id' => 'after', 'sequence' => 6, 'occurred_at' => '2026-06-15T09:59:00+07:00']);
        Http::fake([self::FEED => Http::sequence()
            ->push($this->page([$during(3)], '3', true, '5'))                                  // the probe made when the connection is switched on again
            ->push($this->page([$during(3), $during(4), $during(5), $after], '6', false, '6')), // the pull that follows
        ]);
        $this->postJson(self::IT."/connections/{$connection->id}/resume")->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('open_gap', null);

        return [$tenant, $connection, $adapter, $this->pull($connection->refresh(), $tenant)];
    }

    public function test_pausing_opens_a_gap_that_resuming_closes_with_the_sources_head(): void
    {
        [$tenant, $connection] = $this->gapScenario('REQUIRES_REVIEW');

        $gap = DB::table('integration_gaps')->where('connection_id', $connection->id)->first();
        $this->assertSame(['CLOSED', 'PAUSED', 'REQUIRES_REVIEW', 2, 5], [$gap->status, $gap->open_reason, $gap->policy, (int) $gap->sequence_at_open, (int) $gap->ceiling_sequence]);
        $this->assertSame(['accounting.integration.gap.opened', 'accounting.integration.gap.closed'],
            DB::table('audit_logs')->where('resource_id', $gap->id)->orderBy('occurred_at')->pluck('action')->all());

        // a second pause is a second gap; the old one stays as it was
        $this->postJson(self::IT."/connections/{$connection->id}/pause")->assertOk();
        $this->assertSame(['CLOSED', 'OPEN'], DB::table('integration_gaps')->orderBy('opened_at')->pluck('status')->all());
    }

    public function test_requires_review_holds_every_event_of_the_gap_and_books_only_the_new_one(): void
    {
        [$tenant, , $adapter, $summary] = $this->gapScenario('REQUIRES_REVIEW');

        $this->assertSame([4, 1, 1], [$summary['received'], $summary['processed'], $summary['posted']]);
        $this->assertSame([['NEEDS_REVIEW', 'GAP_REVIEW'], ['NEEDS_REVIEW', 'GAP_REVIEW'], ['NEEDS_REVIEW', 'GAP_REVIEW'], ['POSTED', null]],
            array_map(fn ($id) => $this->stateOf($id), ['gap-3', 'gap-4', 'gap-5', 'after']));
        $this->assertSame(1, $adapter->calls);

        // A person decides for the whole gap: PROCESS books them through the normal path, in source order.
        $gap = DB::table('integration_gaps')->first();
        $token = $this->memberToken($tenant);
        $this->as($this->memberToken($tenant, ['accounting.integration.view']))->postJson(self::IT."/gaps/{$gap->id}/resolution", ['decision' => 'PROCESS', 'reason' => 'Checked with the workshop'])->assertForbidden();
        $this->as($token)->postJson(self::IT."/gaps/{$gap->id}/resolution", ['decision' => 'PROCESS', 'reason' => 'Checked with the workshop'])
            ->assertOk()->assertJsonPath('handled', 3)->assertJsonPath('processed', 3)->assertJsonPath('remaining', 0);
        $this->assertSame(4, $adapter->calls);
        $this->assertSame(['gap-3', 'gap-4', 'gap-5'], DB::table('external_events')->where('gap_id', $gap->id)->where('status', 'POSTED')->orderBy('sequence')->pluck('event_id')->all());
        $this->getJson(self::IT."/gaps/{$gap->id}")->assertOk()->assertJsonPath('events.total', 3)->assertJsonPath('events.held', 0)->assertJsonPath('events.processed', 3);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.integration.gap.resolved')->count());
    }

    public function test_dismissing_a_gap_refuses_its_events_for_good(): void
    {
        [$tenant, , $adapter] = $this->gapScenario('BACKFILL_GAP');

        $this->assertSame(['NEEDS_REVIEW', 'GAP_BACKFILL_REVIEW'], $this->stateOf('gap-4'));
        $gap = DB::table('integration_gaps')->first();
        $this->as($this->memberToken($tenant))->postJson(self::IT."/gaps/{$gap->id}/resolution", ['decision' => 'DISMISS', 'reason' => 'Booked by hand'])
            ->assertOk()->assertJsonPath('handled', 3)->assertJsonPath('processed', 0);
        $this->assertSame(['REJECTED', 'REVIEW_DISMISSED'], $this->stateOf('gap-4'));
        $this->assertSame(1, $adapter->calls);
        $this->postJson(self::IT."/gaps/{$gap->id}/resolution", ['decision' => 'MAYBE', 'reason' => 'x'])->assertStatus(422);
    }

    public function test_ignore_gap_and_start_new_cutover_refuse_the_events_of_the_gap(): void
    {
        [, , $adapter] = $this->gapScenario('IGNORE_GAP');
        $this->assertSame([['REJECTED', 'GAP_IGNORED'], ['REJECTED', 'GAP_IGNORED'], ['REJECTED', 'GAP_IGNORED'], ['POSTED', null]],
            array_map(fn ($id) => $this->stateOf($id), ['gap-3', 'gap-4', 'gap-5', 'after']));
        $this->assertSame(1, $adapter->calls);
    }

    public function test_start_new_cutover_moves_the_cutover_to_the_day_of_reconnection(): void
    {
        [, $connection, $adapter] = $this->gapScenario('START_NEW_CUTOVER');

        $this->assertSame('2026-06-15', $connection->refresh()->cutover_date->toDateString());
        $this->assertSame('2026-06-15', DB::table('integration_gaps')->value('new_cutover_date'));
        $this->assertSame([['REJECTED', 'GAP_NEW_CUTOVER'], ['REJECTED', 'GAP_NEW_CUTOVER'], ['REJECTED', 'GAP_NEW_CUTOVER'], ['POSTED', null]],
            array_map(fn ($id) => $this->stateOf($id), ['gap-3', 'gap-4', 'gap-5', 'after']));
        $this->assertSame(1, $adapter->calls);
    }

    public function test_a_connection_cannot_be_switched_on_again_while_the_source_cannot_state_its_head(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '2']);
        $token = $this->memberToken($tenant);
        $this->as($token)->postJson(self::IT."/connections/{$connection->id}/pause")->assertOk();

        $answer = null;
        Http::fake([self::FEED => function () use (&$answer) {
            return $answer;
        }]);
        foreach ([Http::response('down', 503), Http::response($this->page([], null)), Http::response($this->page([$this->envelope(['sequence' => 3])], '3', true))] as $answer) {
            $this->postJson(self::IT."/connections/{$connection->id}/resume")->assertStatus(409)->assertJsonPath('code', 'SOURCE_HEAD_UNAVAILABLE');
            $this->assertSame(['PAUSED', 'OPEN'], [$connection->refresh()->status, DB::table('integration_gaps')->value('status')]);
        }
    }

    // ------------------------------------------------------------------------------------------------ A20

    public function test_a_pause_while_a_page_is_on_its_way_stops_the_pull_at_the_page_boundary(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '10']);
        $adapter = $this->fakeAdapter();
        Http::fake([self::FEED => function () use ($tenant, $connection) {
            $this->inTenant($tenant, fn () => app(ConnectionService::class)->pause($connection->id)); // somebody pauses while the HTTP call runs

            return Http::response($this->page([$this->envelope(['event_id' => 'late', 'sequence' => 11])], '11'));
        }]);

        $summary = $this->pull($connection, $tenant);

        $this->assertTrue($summary['halted']);
        $this->assertSame([0, 0, null], [$summary['pages'], $summary['received'], $summary['error']]);
        $this->assertSame(0, DB::table('external_events')->count());
        $connection->refresh();
        $this->assertSame([IntegrationConnection::PAUSED, '10', 0], [$connection->status, $connection->last_cursor, $connection->consecutive_failures]);
        $this->assertSame(0, $adapter->calls);
    }

    public function test_a_disconnect_or_a_change_of_settings_while_a_page_is_on_its_way_halts_the_pull_too(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '10']);
        $this->fakeAdapter();
        $change = fn () => $this->inTenant($tenant, fn () => app(ConnectionService::class)->update($connection->id, ['pull_interval_minutes' => 10]));
        Http::fake([self::FEED => function () use ($change) {
            $change();

            return Http::response($this->page([$this->envelope(['event_id' => 'late', 'sequence' => 11])], '11'));
        }]);

        $this->assertTrue($this->pull($connection, $tenant)['halted']);
        $this->assertSame([0, '10'], [DB::table('external_events')->count(), $connection->refresh()->last_cursor]);
    }

    public function test_a_failure_of_a_pull_that_was_overtaken_by_a_pause_is_not_counted(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '10']);
        $this->fakeAdapter();
        Http::fake([self::FEED => function () use ($tenant, $connection) {
            $this->inTenant($tenant, fn () => app(ConnectionService::class)->pause($connection->id));

            return Http::response('boom', 500);
        }]);

        $this->assertStringContainsString('HTTP 500', $this->pull($connection, $tenant)['error']);
        $this->assertSame([IntegrationConnection::PAUSED, 0, null], [$connection->refresh()->status, $connection->consecutive_failures, $connection->last_error]);
    }

    public function test_nothing_is_processed_retried_or_resolved_into_the_books_while_the_connection_is_not_active(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $adapter = $this->fakeAdapter();
        $waiting = $this->storedEvent($tenant, $connection);
        $reviewed = $this->storedEvent($tenant, $connection);
        DB::table('external_events')->where('id', $reviewed->id)->update(['status' => 'NEEDS_REVIEW', 'last_error_code' => 'PERIOD_CLOSED']);

        $token = $this->memberToken($tenant);
        foreach (['pause', 'disconnect'] as $move) {
            DB::table('integration_connections')->where('id', $connection->id)->update(['status' => 'ACTIVE']);
            $this->as($token)->postJson(self::IT."/connections/{$connection->id}/{$move}")->assertOk();

            // the processor leaves the event exactly as it is
            $this->inTenant($tenant, fn () => app(EventProcessor::class)->process(ExternalEvent::query()->findOrFail($waiting->id)));
            $this->assertSame('RECEIVED', DB::table('external_events')->where('id', $waiting->id)->value('status'));
            // a retry is refused and changes nothing
            $this->postJson(self::IT."/events/{$reviewed->id}/retry", ['reason' => 'Period reopened'])->assertStatus(409)->assertJsonPath('code', 'CONNECTION_NOT_ACTIVE');
            $this->assertSame(['NEEDS_REVIEW', 'PERIOD_CLOSED', 0], [...$this->stateOf($reviewed->event_id), $adapter->calls]);
            $this->assertSame(0, $this->inTenant($tenant, fn () => app(IntegrationRunner::class)->processDue()['events']));
        }

        // dismissing is not booking: it stays possible, and is audited
        $this->postJson(self::IT."/events/{$reviewed->id}/dismiss", ['reason' => 'Booked by hand'])->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('last_error_code', 'REVIEW_DISMISSED');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.integration.event.dismissed')->count());
        $this->postJson(self::IT."/events/{$reviewed->id}/dismiss", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'EVENT_NOT_DISMISSIBLE');

        // switched on again, the waiting event is processed once and the retry works
        Http::fake([self::FEED => Http::response($this->page([], null, false, '0'))]);
        DB::table('integration_connections')->where('id', $connection->id)->update(['status' => 'READY']);
        $this->postJson(self::IT."/connections/{$connection->id}/activate")->assertOk();
        $this->assertSame(1, $this->inTenant($tenant, fn () => app(IntegrationRunner::class)->processDue()['events']));
        $this->assertSame(1, $adapter->calls);
    }

    // ------------------------------------------------------------------------------------------------ A21

    private function changed(array $envelope): array
    {
        $envelope['payload']['total_amount'] = '9999999.00';

        return $envelope;
    }

    public function test_a_changed_payload_holds_an_unposted_event_until_a_person_decides_and_the_evidence_never_changes(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $adapter = $this->fakeAdapter(fn (ExternalEvent $e) => new ProcessOutcome(outcome: ProcessOutcome::INFORMATIONAL));
        $envelope = $this->envelope(['event_id' => 'evt-1', 'sequence' => 1]);
        $stored = $this->receive($tenant, $connection, $envelope)->event;
        $this->receive($tenant, $connection, $this->changed($envelope));

        $row = DB::table('external_events')->where('id', $stored->id)->first();
        $this->assertSame(['NEEDS_REVIEW', 'PAYLOAD_CONFLICT', true], [$row->status, $row->last_error_code, (bool) $row->conflict]);
        $evidence = DB::table('external_event_conflicts')->where('external_event_id', $stored->id)->first();
        $this->assertSame('9999999.00', json_decode($evidence->envelope, true)['payload']['total_amount']);
        $this->receive($tenant, $connection, $this->changed($envelope)); // the same disputed payload again is not a second piece of evidence
        $this->assertSame(1, DB::table('external_event_conflicts')->count());

        // never processed, never retried while disputed
        $this->assertSame(0, $this->inTenant($tenant, fn () => app(IntegrationRunner::class)->processDue()['events']));
        $token = $this->memberToken($tenant);
        $this->as($token)->postJson(self::IT."/events/{$stored->id}/retry", ['reason' => 'Try it'])->assertStatus(409)->assertJsonPath('code', 'EVENT_CONFLICT_OPEN');
        $this->assertSame(0, $adapter->calls);

        // the evidence and a decided conflict are immutable at the database
        $this->assertSame(1, $this->expectDatabaseRefusal(fn () => DB::table('external_event_conflicts')->where('id', $evidence->id)->update(['envelope' => json_encode(['x' => 1])])));
        $this->assertSame(1, $this->expectDatabaseRefusal(fn () => DB::table('external_event_conflicts')->where('id', $evidence->id)->delete()));

        // a reviewer keeps the original: it is processed with its original payload
        $this->getJson(self::IT."/events/{$stored->id}")->assertOk()->assertJsonCount(1, 'conflicts');
        $this->as($this->memberToken($tenant, ['accounting.integration.retry']))->postJson(self::IT."/events/{$stored->id}/conflict-resolution", ['decision' => 'USE_ORIGINAL', 'reason' => 'x1'])->assertForbidden();
        $this->as($token)->postJson(self::IT."/events/{$stored->id}/conflict-resolution", ['decision' => 'USE_ORIGINAL', 'reason' => 'The first one is right'])
            ->assertOk()->assertJsonPath('status', 'POSTED')->assertJsonPath('conflict', false)->assertJsonPath('accounting_outcome', 'INFORMATIONAL');
        $this->assertSame(1, $adapter->calls);
        $resolved = DB::table('external_event_conflicts')->where('id', $evidence->id)->first();
        $this->assertSame(['USE_ORIGINAL', 'The first one is right'], [$resolved->resolution, $resolved->resolution_reason]);
        $this->postJson(self::IT."/events/{$stored->id}/conflict-resolution", ['decision' => 'REJECT', 'reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'NO_OPEN_CONFLICT');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.integration.event.conflict_resolved')->count());
    }

    private function expectDatabaseRefusal(callable $change): int
    {
        try {
            DB::transaction($change); // a savepoint inside the test's transaction: the refusal must not abort the rest of the test
        } catch (QueryException) {
            return 1;
        }

        return 0;
    }

    public function test_rejecting_a_disputed_event_books_nothing_and_a_posted_one_cannot_be_rejected(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $adapter = $this->fakeAdapter();
        $held = $this->envelope(['event_id' => 'held', 'sequence' => 1]);
        $this->receive($tenant, $connection, $held);
        $this->receive($tenant, $connection, $this->changed($held));

        $posted = $this->storedEvent($tenant, $connection, ['event_id' => 'posted', 'sequence' => 2]);
        $this->inTenant($tenant, fn () => app(EventProcessor::class)->process($posted));
        $this->receive($tenant, $connection, $this->changed($this->envelope(['event_id' => 'posted', 'sequence' => 2] + $posted->envelope)));
        $this->assertSame(['POSTED', true, 1], [...[DB::table('external_events')->where('id', $posted->id)->value('status'), (bool) DB::table('external_events')->where('id', $posted->id)->value('conflict')], $adapter->calls]);

        $token = $this->memberToken($tenant);
        $heldId = DB::table('external_events')->where('event_id', 'held')->value('id');
        $this->as($token)->postJson(self::IT."/events/{$heldId}/conflict-resolution", ['decision' => 'REJECT', 'reason' => 'Looks wrong'])
            ->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('last_error_code', 'CONFLICT_REJECTED');
        $this->postJson(self::IT."/events/{$posted->id}/conflict-resolution", ['decision' => 'REJECT', 'reason' => 'Looks wrong'])->assertStatus(409)->assertJsonPath('code', 'EVENT_ALREADY_POSTED');
        $this->postJson(self::IT."/events/{$posted->id}/conflict-resolution", ['decision' => 'USE_ORIGINAL', 'reason' => 'The posted one stands'])->assertOk()->assertJsonPath('status', 'POSTED');
        $this->assertSame(1, $adapter->calls);
    }

    // ------------------------------------------------------------------------------------------------ A17

    public function test_a_snapshot_that_is_not_the_state_at_event_time_waits_for_review_instead_of_booking(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $adapter = $this->fakeAdapter();
        $with = fn (string $id, mixed $basis) => $this->storedEvent($tenant, $connection, ['event_id' => $id, 'payload' => ['total_amount' => '1.00', 'snapshot_basis' => $basis]]);

        $rebuilt = $with('rebuilt', 'backfill_current_state');
        $exact = $with('exact', 'event_time');
        $plain = $this->storedEvent($tenant, $connection, ['event_id' => 'plain']);

        $this->assertSame([['NEEDS_REVIEW', 'SNAPSHOT_BASIS_REVIEW'], ['RECEIVED', null], ['RECEIVED', null]], array_map(fn ($id) => $this->stateOf($id), ['rebuilt', 'exact', 'plain']));
        $this->assertSame(2, $this->inTenant($tenant, fn () => app(IntegrationRunner::class)->processDue()['events']));
        $this->assertSame(2, $adapter->calls);

        // only a person releases it, knowing what it is
        $this->as($this->memberToken($tenant))->postJson(self::IT."/events/{$rebuilt->id}/retry", ['reason' => 'Amounts checked against the work order'])->assertOk()->assertJsonPath('status', 'POSTED');
        $this->assertSame(3, $adapter->calls);
    }

    // ------------------------------------------------------------------------------------------------ A28

    /** A connector of another source whose tenant entitlement has lapsed: it reports problems, so the processor leaves its events alone. */
    private function lapsedAdapter(): IntegrationAdapter
    {
        return new class implements ConnectorRequirements, IntegrationAdapter
        {
            public int $calls = 0;

            public function sourceSystem(): string
            {
                return 'lapsed';
            }

            public function supports(string $eventType, int $major): bool
            {
                return true;
            }

            public function process(ExternalEvent $event): ProcessOutcome
            {
                $this->calls++;

                return new ProcessOutcome;
            }

            public function cleanSettings(array $settings): array
            {
                return $settings;
            }

            public function missingSettings(array $settings): array
            {
                return [];
            }

            public function entitlementProblems(): array
            {
                return ['feature LAPSED is not enabled'];
            }
        };
    }

    /** Inserts $count RECEIVED events straight into the inbox (a big backlog without paying for the gate). */
    private function backlog(Tenant $tenant, IntegrationConnection $connection, int $count, string $prefix): void
    {
        $now = now()->utc()->format('Y-m-d H:i:s.u').'+00';
        foreach (array_chunk(range(1, $count), 100) as $chunk) {
            DB::table('external_events')->insert(array_map(fn (int $n) => [
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'connection_id' => $connection->id, 'source_system' => $connection->source_system,
                'event_id' => "{$prefix}-{$n}", 'event_type' => 'optifleet.workshop_invoice.recorded', 'schema_version' => '1.0', 'occurred_at' => '2026-03-10 01:00:00+00',
                'sequence' => $n, 'envelope' => json_encode($this->envelope(['event_id' => "{$prefix}-{$n}", 'source_system' => $connection->source_system])), 'content_hash' => hash('sha256', "{$prefix}-{$n}"),
                'status' => 'RECEIVED', 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }

    public function test_a_large_backlog_of_a_lapsed_connector_never_starves_the_work_of_another_connection(): void
    {
        $tenant = $this->tenant();
        $lapsedConnection = $this->connectionFor($tenant, ['source_system' => 'lapsed', 'source_tenant_reference' => 'lapsed-1']);
        $healthy = $this->connectionFor($tenant, ['source_tenant_reference' => 'fleet-co-1']);
        $lapsed = $this->lapsedAdapter();
        $ok = $this->fakeAdapter();
        $this->app->instance(AdapterRegistry::class, new AdapterRegistry([$lapsed, $this->app->make(AdapterRegistry::class)->for('optifleet')]));

        $this->backlog($tenant, $lapsedConnection, 230, 'old'); // older than, and more than one batch ahead of, the healthy connection's work
        Carbon::setTestNow(now()->addMinute());
        $this->backlog($tenant, $healthy, 3, 'new');

        $totals = $this->inTenant($tenant, fn () => app(IntegrationRunner::class)->processDue());

        $this->assertSame(['events' => 3, 'posted' => 3, 'journals' => 0, 'held' => 1], $totals);
        $this->assertSame([0, 3], [$lapsed->calls, $ok->calls]);
        $this->assertSame(230, DB::table('external_events')->where('connection_id', $lapsedConnection->id)->where('status', 'RECEIVED')->count(), 'held events stay exactly as they are');

        // the backlog shows who is waiting, for how long, and why
        $backlog = $this->as($this->memberToken($tenant))->getJson(self::IT."/connections/{$lapsedConnection->id}/summary")->assertOk()->json('backlog');
        $this->assertSame([230, 'CONNECTOR_NOT_ENTITLED', false], [$backlog['due'], $backlog['blocked_by'], $backlog['stalled']]);
        $this->getJson(self::IT."/connections/{$healthy->id}/summary")->assertOk()->assertJsonPath('backlog.due', 0)->assertJsonPath('backlog.blocked_by', null);
    }

    public function test_every_connection_gets_its_own_bounded_batch_and_the_rest_waits_for_the_next_run(): void
    {
        $tenant = $this->tenant();
        $big = $this->connectionFor($tenant, ['source_tenant_reference' => 'big']);
        $small = $this->connectionFor($tenant, ['source_tenant_reference' => 'small']);
        $adapter = $this->fakeAdapter();
        $this->backlog($tenant, $big, 230, 'big');
        $this->backlog($tenant, $small, 5, 'small');

        $first = $this->inTenant($tenant, fn () => app(IntegrationRunner::class)->processDue());
        $this->assertSame(['events' => 205, 'posted' => 205, 'journals' => 0, 'held' => 0], $first);
        $this->assertSame(30, DB::table('external_events')->where('status', 'RECEIVED')->count());

        $second = $this->inTenant($tenant, fn () => app(IntegrationRunner::class)->processDue());
        $this->assertSame(30, $second['events']);
        $this->assertSame(235, $adapter->calls);
        $this->assertSame(0, DB::table('external_events')->where('status', 'RECEIVED')->count());
    }

    public function test_the_summary_flags_a_backlog_the_scheduler_is_not_keeping_up_with(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $this->fakeAdapter();
        $this->backlog($tenant, $connection, 2, 'slow');
        Carbon::setTestNow(now()->addMinutes(45));

        $backlog = $this->as($this->memberToken($tenant))->getJson(self::IT."/connections/{$connection->id}/summary")->assertOk()->json('backlog');

        $this->assertSame([2, null, true], [$backlog['due'], $backlog['blocked_by'], $backlog['stalled']]);
        $this->assertGreaterThanOrEqual(2600, $backlog['oldest_due_age_seconds']);
    }
}
