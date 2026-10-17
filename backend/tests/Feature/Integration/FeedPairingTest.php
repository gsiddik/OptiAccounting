<?php

namespace Tests\Feature\Integration;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\ConnectionService;
use App\Domain\Integration\Services\FeedPairing;
use App\Domain\Integration\Services\PullService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/**
 * Ecosystem audit A19 (consumer side of the Fleet feed contract, OptiFleet docs/integration/ACCOUNTING_FEED.md): the pairing block of every page is
 * compared with the OptiNexus tenant of the organization, and a source that refuses, a source that does not answer and OptiEntry holding the
 * connection itself are three different situations that are recorded, shown and retried differently.
 */
class FeedPairingTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, PayablesFixtures;

    private const FEED = 'https://fleet.example.test/api/v1/integration/accounting-feed*';

    private const NEXUS = '7f0c1d2e-3a4b-4c5d-8e6f-0a1b2c3d4e5f';

    private const OTHER_NEXUS = '9a8b7c6d-5e4f-4a3b-9c2d-1e0f9a8b7c6d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAdapter();
    }

    /** An organization linked to an OptiNexus tenant, with pairing required (the setting of an OptiNexus installation). */
    private function paired(string $code = 'alpha', array $connection = []): array
    {
        config(['optientry.integration.feed_pairing' => 'required']);
        $tenant = $this->tenant($code);
        DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_tenant_id' => self::NEXUS]);

        return [$tenant->refresh(), $this->connectionFor($tenant, $connection)];
    }

    /** @return array<string,mixed> */
    private function pairing(array $override = []): array
    {
        return $override + ['nexus_tenant_id' => self::NEXUS, 'producer_application' => 'optifleet', 'consumer_application' => 'optiaccounting'];
    }

    /** @return array<string,mixed> a page of the contract, `pairing` included unless it is null */
    private function page(array $envelopes, ?array $pairing, ?string $highWater = null): array
    {
        $last = $envelopes === [] ? null : (string) end($envelopes)['sequence'];

        return ['data' => $envelopes, 'next_cursor' => $last, 'has_more' => false, 'high_water' => $highWater ?? ($last ?? '0')]
            + ($pairing === null ? [] : ['pairing' => $pairing]);
    }

    private function pull(IntegrationConnection $connection, Tenant $tenant): array
    {
        return $this->inTenant($tenant, fn () => app(PullService::class)->pull($connection->refresh()));
    }

    private function row(IntegrationConnection $connection): object
    {
        return DB::table('integration_connections')->where('id', $connection->id)->first();
    }

    // ------------------------------------------------------------------------------------------------ pairing

    public function test_a_page_for_the_organizations_own_nexus_tenant_is_accepted_and_the_pairing_is_remembered(): void
    {
        [$tenant, $connection] = $this->paired();
        Http::fake([self::FEED => Http::response($this->page([$this->envelope(['event_id' => 'a', 'sequence' => 1])], $this->pairing(['nexus_tenant_id' => strtoupper(self::NEXUS)]), '4'))]);

        $summary = $this->pull($connection, $tenant);

        $this->assertSame([1, null], [$summary['received'], $summary['error']]);
        $row = $this->row($connection);
        $this->assertSame(['1', 'OK', null, 0, self::NEXUS, 4], [$row->last_cursor, $row->feed_access, $row->feed_access_code, $row->consecutive_failures, $row->paired_nexus_tenant_id, (int) $row->source_high_water]);
        $this->assertNotNull($row->feed_checked_at);
        $this->assertNull($row->feed_next_attempt_at);
    }

    /** @return array<string,array{0:?array,1:string}> */
    public static function wrongPairings(): array
    {
        return [
            'no pairing block' => [null, 'FEED_PAIRING_MISSING'],
            'a pairing block without the tenant' => [['producer_application' => 'optifleet'], 'FEED_PAIRING_MISSING'],
            'another OptiNexus tenant' => [['nexus_tenant_id' => self::OTHER_NEXUS, 'producer_application' => 'optifleet', 'consumer_application' => 'optiaccounting'], 'FEED_PAIRING_MISMATCH'],
            'another producer application' => [['nexus_tenant_id' => self::NEXUS, 'producer_application' => 'optiradar', 'consumer_application' => 'optiaccounting'], 'FEED_PAIRING_MISMATCH'],
            'another consumer application' => [['nexus_tenant_id' => self::NEXUS, 'producer_application' => 'optifleet', 'consumer_application' => 'someone-else'], 'FEED_PAIRING_MISMATCH'],
        ];
    }

    #[DataProvider('wrongPairings')]
    public function test_a_page_that_is_not_for_this_organization_is_refused_stores_nothing_and_never_turns_the_connection_to_error(?array $pairing, string $code): void
    {
        [$tenant, $connection] = $this->paired('alpha', ['last_cursor' => '5']);
        Http::fake([self::FEED => Http::response($this->page([$this->envelope(['event_id' => 'x', 'sequence' => 6])], $pairing))]);

        for ($i = 1; $i <= 7; $i++) { // more attempts than the failures that turn a connection to ERROR
            $summary = $this->pull($connection, $tenant);
            $this->assertNotNull($summary['error']);
        }

        $row = $this->row($connection);
        $this->assertSame([IntegrationConnection::ACTIVE, '5', 0, 'REFUSED', $code], [$row->status, $row->last_cursor, $row->consecutive_failures, $row->feed_access, $row->feed_access_code]);
        $this->assertSame(0, DB::table('external_events')->count());
        $this->assertNotNull($row->feed_next_attempt_at, 'the source is asked again later, not at once');
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'accounting.integration.connection.errored')->count());
    }

    public function test_an_organization_that_is_not_linked_to_optinexus_cannot_be_paired_so_nothing_is_pulled_for_it(): void
    {
        config(['optientry.integration.feed_pairing' => 'required']);
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        Http::fake([self::FEED => Http::response($this->page([$this->envelope(['sequence' => 1])], $this->pairing()))]);

        // The scheduler leaves it alone (OptiEntry holds the connection) and "sync now" says why.
        $this->artisan('integration:pull')->assertSuccessful();
        Http::assertNothingSent();
        $this->as($this->memberToken($tenant))->postJson(self::IT."/connections/{$connection->id}/sync-now")->assertForbidden()
            ->assertJsonPath('code', 'TENANT_NOT_LINKED')->assertJsonPath('details.held_by', 'TENANT_NOT_LINKED');
        Http::assertNothingSent();

        // Reading the feed directly still refuses: a page is never accepted for an organization without a pairing.
        $summary = $this->pull($connection, $tenant);
        $this->assertStringContainsString('not linked to OptiNexus', $summary['error']);
        $this->assertSame(['REFUSED', 'FEED_PAIRING_NOT_LINKED', 0], [$this->row($connection)->feed_access, $this->row($connection)->feed_access_code, $this->row($connection)->consecutive_failures]);
        $this->assertSame(0, DB::table('external_events')->count());

        $access = $this->as($this->memberToken($tenant))->getJson(self::IT."/connections/{$connection->id}")->assertOk()->json('access');
        $this->assertSame(['TENANT_NOT_LINKED', true, false], [$access['entry']['code'], $access['pairing']['required'], $access['pairing']['linked']]);
    }

    public function test_the_pairing_is_not_checked_where_it_is_off_and_the_nexus_tenant_never_comes_from_the_page(): void
    {
        config(['optientry.integration.feed_pairing' => 'off']);
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        // A page that names another OptiNexus tenant is accepted as it is: nothing is verified, and nothing from the page is stored as the pairing.
        Http::fake([self::FEED => Http::response($this->page([$this->envelope(['event_id' => 'a', 'sequence' => 1])], $this->pairing(['nexus_tenant_id' => self::OTHER_NEXUS])))]);

        $summary = $this->pull($connection, $tenant);

        $this->assertSame([1, null], [$summary['received'], $summary['error']]);
        $this->assertSame(['OK', null], [$this->row($connection)->feed_access, $this->row($connection)->paired_nexus_tenant_id]);
        $this->assertNull(DB::table('tenants')->where('id', $tenant->id)->value('optinexus_tenant_id'));
    }

    public function test_pairing_is_required_automatically_while_optinexus_is_the_identity_authority(): void
    {
        foreach ([['auto', 'optinexus', true], ['auto', 'standalone', false], ['required', 'standalone', true], ['off', 'optinexus', false], ['unknown', 'optinexus', true]] as [$setting, $mode, $expected]) {
            config(['optientry.integration.feed_pairing' => $setting, 'optientry.identity_mode' => $mode]);
            $this->assertSame($expected, FeedPairing::required(), "{$setting} / {$mode}");
        }
    }

    // ------------------------------------------------------------------------------------------------ three situations

    /** @return array<string,array{0:callable,1:string,2:string,3:bool}> response, access, code, counted */
    public static function sourceAnswers(): array
    {
        return [
            'credential unknown or revoked' => [fn () => Http::response(['code' => 'FEED_UNAUTHENTICATED'], 401), 'REFUSED', 'FEED_UNAUTHENTICATED', false],
            'tenant not paired at the source' => [fn () => Http::response(['code' => 'FEED_TENANT_NOT_PAIRED'], 403), 'REFUSED', 'FEED_TENANT_NOT_PAIRED', false],
            'consumer not entitled' => [fn () => Http::response(['code' => 'FEED_CONSUMER_NOT_ENTITLED'], 403), 'REFUSED', 'FEED_CONSUMER_NOT_ENTITLED', false],
            'a refusal without a code' => [fn () => Http::response('no', 403), 'REFUSED', 'FEED_FORBIDDEN', false],
            'governance unavailable' => [fn () => Http::response(['code' => 'FEED_GOVERNANCE_UNAVAILABLE'], 503, ['Retry-After' => '120']), 'UNAVAILABLE', 'FEED_GOVERNANCE_UNAVAILABLE', true],
            'rate limited' => [fn () => Http::response('slow down', 429), 'UNAVAILABLE', 'SOURCE_RATE_LIMITED', true],
            'server error' => [fn () => Http::response('boom', 500), 'UNAVAILABLE', 'SOURCE_ERROR', true],
            'not the feed' => [fn () => Http::response('not here', 404), 'INVALID', 'SOURCE_ERROR', true],
            'a malformed page' => [fn () => Http::response(['data' => 'nope', 'has_more' => false]), 'INVALID', 'FEED_PAGE_INVALID', true],
            'unreachable' => [fn () => throw new ConnectionException('cURL error 28: timed out'), 'UNAVAILABLE', 'SOURCE_UNREACHABLE', true],
        ];
    }

    #[DataProvider('sourceAnswers')]
    public function test_a_refusing_a_silent_and_a_confused_source_are_recorded_apart(callable $answer, string $access, string $code, bool $counted): void
    {
        [$tenant, $connection] = $this->paired('alpha', ['last_cursor' => '3']);
        Http::fake([self::FEED => $answer]);

        $summary = $this->pull($connection, $tenant);

        $row = $this->row($connection);
        $this->assertNotNull($summary['error']);
        $this->assertSame(['3', $access, $code, $counted ? 1 : 0], [$row->last_cursor, $row->feed_access, $row->feed_access_code, $row->consecutive_failures]);
        $this->assertNotNull($row->feed_access_since);
        $this->assertNotNull($row->feed_checked_at);

        // The health of the connection says the same in the words of the API.
        $api = $this->as($this->memberToken($tenant))->getJson(self::IT."/connections/{$connection->id}/summary")->assertOk()->json();
        $this->assertSame([$access, $code], [$api['access']['state'], $api['access']['code']]);
        $alert = collect($api['health']['alerts'])->firstWhere('code', 'SOURCE_'.$access);
        $this->assertSame($code, $alert['reason']);
        $this->assertSame($access === 'UNAVAILABLE' ? 'WARN' : 'ALERT', $alert['severity']);
    }

    public function test_backoff_after_a_refusal_and_after_a_retry_after_is_honoured_by_the_scheduler_and_a_good_page_ends_it(): void
    {
        [$tenant, $connection] = $this->paired();
        $answer = Http::response(['code' => 'FEED_CONSUMER_NOT_ENTITLED'], 403);
        Http::fake([self::FEED => function () use (&$answer) {
            return $answer;
        }]);

        $this->artisan('integration:pull')->assertSuccessful();
        Http::assertSentCount(1);
        $next = Carbon::parse($this->row($connection)->feed_next_attempt_at);
        $this->assertTrue($next->between(now()->addMinutes(14), now()->addMinutes(16)), 'a refused source is asked again after 15 minutes');

        // Five minutes later the pull interval has passed, but the source was refused: it is not asked again yet.
        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('integration:pull')->assertSuccessful();
        Http::assertSentCount(1);

        // After the back-off it is asked again, and when the cause is lifted the pull resumes from the cursor it kept.
        Carbon::setTestNow(now()->addMinutes(11));
        $answer = Http::response($this->page([$this->envelope(['event_id' => 'a', 'sequence' => 1])], $this->pairing()));
        $this->artisan('integration:pull')->assertSuccessful();
        Http::assertSentCount(2);
        $row = $this->row($connection);
        $this->assertSame(['OK', null, '1', null, 0], [$row->feed_access, $row->feed_access_code, $row->last_cursor, $row->feed_next_attempt_at, $row->consecutive_failures]);
        $this->assertSame(1, DB::table('external_events')->count());

        // An outage with a Retry-After: counted, and not asked before the source's own hint (capped at an hour).
        $answer = Http::response('later', 503, ['Retry-After' => '99999']);
        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('integration:pull')->assertSuccessful();
        $row = $this->row($connection);
        $this->assertSame(['UNAVAILABLE', 1], [$row->feed_access, $row->consecutive_failures]);
        $this->assertTrue(Carbon::parse($row->feed_next_attempt_at)->between(now()->addMinutes(59), now()->addMinutes(61)));
    }

    public function test_the_since_of_a_refusal_does_not_move_while_the_same_refusal_lasts(): void
    {
        [$tenant, $connection] = $this->paired();
        $answer = Http::response(['code' => 'FEED_UNAUTHENTICATED'], 401);
        Http::fake([self::FEED => function () use (&$answer) {
            return $answer;
        }]);

        $this->pull($connection, $tenant);
        $first = $this->row($connection)->feed_access_since;
        Carbon::setTestNow(now()->addMinutes(30));
        $this->pull($connection, $tenant);
        $this->assertSame($first, $this->row($connection)->feed_access_since);

        $answer = Http::response('boom', 500);
        $this->pull($connection, $tenant);
        $this->assertNotSame($first, $this->row($connection)->feed_access_since);
    }

    public function test_a_cursor_ahead_of_the_source_is_invalid_not_accepted_and_shown_as_a_source_behind_the_cursor(): void
    {
        [$tenant, $connection] = $this->paired('alpha', ['last_cursor' => '10']);
        Http::fake([self::FEED => Http::response($this->page([], $this->pairing(), '4'))]);

        $summary = $this->pull($connection, $tenant);

        $this->assertStringContainsString('ahead of the source', $summary['error']);
        $row = $this->row($connection);
        $this->assertSame(['10', 'INVALID', 'FEED_CURSOR_AHEAD', 1, 4], [$row->last_cursor, $row->feed_access, $row->feed_access_code, $row->consecutive_failures, (int) $row->source_high_water]);
        $health = $this->as($this->memberToken($tenant))->getJson(self::IT."/connections/{$connection->id}/summary")->assertOk()->json();
        $this->assertSame(['SOURCE_BEHIND_CURSOR', 'SOURCE_INVALID'], collect($health['health']['alerts'])->pluck('code')->sort()->values()->all());
        $this->assertSame([4, 10, 0], [$health['source']['high_water'], $health['source']['cursor'], $health['source']['lag']]);
    }

    public function test_the_lag_is_the_distance_between_the_sources_high_water_and_the_cursor(): void
    {
        [$tenant, $connection] = $this->paired();
        $envelopes = [$this->envelope(['event_id' => 'a', 'sequence' => 1]), $this->envelope(['event_id' => 'b', 'sequence' => 2])];
        Http::fake([self::FEED => Http::response(['data' => $envelopes, 'next_cursor' => '2', 'has_more' => true, 'high_water' => '30', 'pairing' => $this->pairing()])]);

        $this->inTenant($tenant, fn () => app(ConnectionService::class)->pullSucceeded($connection->refresh(), '2', ['high_water' => '30']));

        $source = $this->as($this->memberToken($tenant))->getJson(self::IT."/connections/{$connection->id}/summary")->assertOk()->json('source');
        $this->assertSame([30, 2, 28], [$source['high_water'], $source['cursor'], $source['lag']]);
    }

    // ------------------------------------------------------------------------------------------------ activation

    public function test_a_connection_is_switched_on_only_when_the_source_serves_this_organization(): void
    {
        [$tenant, $connection] = $this->paired('alpha', ['status' => IntegrationConnection::READY]);
        $this->as($this->memberToken($tenant));
        $answer = Http::response(['code' => 'FEED_TENANT_NOT_PAIRED'], 403);
        Http::fake([self::FEED => function () use (&$answer) {
            return $answer;
        }]);

        $this->postJson(self::IT."/connections/{$connection->id}/activate")->assertStatus(409)->assertJsonPath('code', 'CONNECTION_FEED_REFUSED')
            ->assertJsonPath('details.feed_code', 'FEED_TENANT_NOT_PAIRED')->assertJsonPath('details.access', 'REFUSED');
        $this->assertSame(IntegrationConnection::READY, $this->row($connection)->status);

        $answer = Http::response('down', 503);
        $this->postJson(self::IT."/connections/{$connection->id}/activate")->assertStatus(409)->assertJsonPath('code', 'CONNECTION_FEED_UNAVAILABLE');

        $answer = Http::response($this->page([], $this->pairing(['nexus_tenant_id' => self::OTHER_NEXUS])));
        $this->postJson(self::IT."/connections/{$connection->id}/activate")->assertStatus(409)->assertJsonPath('code', 'CONNECTION_FEED_REFUSED')->assertJsonPath('details.feed_code', 'FEED_PAIRING_MISMATCH');
        $this->assertSame([IntegrationConnection::READY, 0, 0], [$this->row($connection)->status, DB::table('external_events')->count(), DB::table('integration_gaps')->count()]);

        $answer = Http::response($this->page([], $this->pairing()));
        $this->postJson(self::IT."/connections/{$connection->id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->assertSame(0, DB::table('external_events')->count(), 'the probe asks for one event at most and stores nothing');
    }

    public function test_the_activation_probe_asks_from_the_stored_cursor_for_one_event_and_a_legacy_installation_is_not_probed(): void
    {
        [$tenant, $connection] = $this->paired('alpha', ['status' => IntegrationConnection::READY, 'last_cursor' => '7']);
        $this->as($this->memberToken($tenant));
        Http::fake([self::FEED => Http::response($this->page([], $this->pairing(), '7'))]);

        $this->postJson(self::IT."/connections/{$connection->id}/activate")->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'after=7') && str_contains($r->url(), 'limit=1'));

        config(['optientry.integration.feed_pairing' => 'off']);
        $legacy = $this->connectionFor($this->tenant('bravo'), ['status' => IntegrationConnection::READY]);
        Http::fake();
        $this->as($this->memberToken(Tenant::query()->where('code', 'bravo')->firstOrFail()))->postJson(self::IT."/connections/{$legacy->id}/activate")->assertOk();
        Http::assertNothingSent();
    }

    public function test_resuming_a_paused_connection_is_refused_while_the_source_refuses_the_pairing(): void
    {
        [$tenant, $connection] = $this->paired('alpha', ['last_cursor' => '2']);
        $this->as($this->memberToken($tenant))->postJson(self::IT."/connections/{$connection->id}/pause")->assertOk();
        Http::fake([self::FEED => Http::response($this->page([], $this->pairing(['nexus_tenant_id' => self::OTHER_NEXUS]), '9'))]);

        $this->postJson(self::IT."/connections/{$connection->id}/resume")->assertStatus(409)->assertJsonPath('code', 'SOURCE_HEAD_UNAVAILABLE')
            ->assertJsonPath('details.access', 'REFUSED')->assertJsonPath('details.feed_code', 'FEED_PAIRING_MISMATCH');
        $this->assertSame(['PAUSED', 'OPEN'], [$this->row($connection)->status, DB::table('integration_gaps')->value('status')]);
    }

    // ------------------------------------------------------------------------------------------------ review.required

    private function quarantined(string $id, int $sequence, array $review = []): array
    {
        return $this->envelope(['event_id' => $id, 'sequence' => $sequence]) + ['review' => $review + ['required' => true, 'reason' => 'backfill_current_state', 'state' => 'QUARANTINED', 'note' => 'rebuilt from current state']];
    }

    public function test_an_event_the_source_quarantined_waits_for_a_person_and_is_never_booked_automatically(): void
    {
        [$tenant, $connection] = $this->paired();
        $adapter = $this->fakeAdapter();
        Http::fake([self::FEED => Http::response($this->page([
            $this->envelope(['event_id' => 'live', 'sequence' => 1]),
            $this->quarantined('rebuilt', 2),
            $this->envelope(['event_id' => 'live-2', 'sequence' => 3]),
        ], $this->pairing()))]);

        $summary = $this->pull($connection, $tenant);

        $this->assertSame([3, null], [$summary['received'], $summary['error']]);
        $state = fn (string $id) => (array) DB::table('external_events')->where('event_id', $id)->first(['status', 'last_error_code']);
        $this->assertSame(['POSTED', null], array_values($state('live')));
        $this->assertSame(['NEEDS_REVIEW', 'SOURCE_REVIEW_REQUIRED'], array_values($state('rebuilt')));
        $this->assertSame(['POSTED', null], array_values($state('live-2')));
        $this->assertSame(2, $adapter->calls, 'the quarantined event never reached the adapter');
        $this->assertSame('3', $this->row($connection)->last_cursor, 'the cursor moves past it: the feed is not blocked by one event');
        $this->assertStringContainsString('backfill_current_state', DB::table('external_events')->where('event_id', 'rebuilt')->value('last_error'));

        // It shows in the health of the connection, and a person decides, event by event.
        $token = $this->memberToken($tenant);
        $alerts = $this->as($token)->getJson(self::IT."/connections/{$connection->id}/summary")->assertOk()->json('health.alerts');
        $this->assertSame(['QUARANTINED_EVENTS', 1], [$alerts[0]['code'], $alerts[0]['count']]);
        $id = DB::table('external_events')->where('event_id', 'rebuilt')->value('id');
        $this->postJson(self::IT."/events/{$id}/retry", ['reason' => 'Checked against the workshop invoice'])->assertOk()->assertJsonPath('status', 'POSTED');
        $this->assertSame(3, $adapter->calls);
    }

    public function test_a_quarantined_event_can_be_dismissed_and_books_nothing(): void
    {
        [$tenant, $connection] = $this->paired();
        $adapter = $this->fakeAdapter();
        Http::fake([self::FEED => Http::response($this->page([$this->quarantined('rebuilt', 1)], $this->pairing()))]);
        $this->pull($connection, $tenant);
        $id = DB::table('external_events')->where('event_id', 'rebuilt')->value('id');

        $this->as($this->memberToken($tenant))->postJson(self::IT."/events/{$id}/dismiss", ['reason' => 'Cancelled at the workshop'])->assertOk()->assertJsonPath('status', 'REJECTED');

        $this->assertSame(0, $adapter->calls);
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_a_review_marker_that_is_not_exactly_true_does_not_hold_an_event(): void
    {
        [$tenant, $connection] = $this->paired();
        $adapter = $this->fakeAdapter();
        Http::fake([self::FEED => Http::response($this->page([
            $this->envelope(['event_id' => 'fine', 'sequence' => 1]) + ['review' => ['required' => false]],
            $this->envelope(['event_id' => 'odd', 'sequence' => 2]) + ['review' => ['required' => 'yes']],
        ], $this->pairing()))]);

        $this->pull($connection, $tenant);

        $this->assertSame(['POSTED', 'POSTED'], DB::table('external_events')->orderBy('sequence')->pluck('status')->all());
        $this->assertSame(2, $adapter->calls);
    }

    /** Pull to cursor 2, pause, the source goes on to 5 meanwhile (its event 4 is quarantined), switch on again, then pull: events 3-5 are the gap, 6 is new. */
    private function gapWithQuarantine(string $policy): array
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'Asia/Jakarta'));
        [$tenant, $connection] = $this->paired('alpha', ['last_cursor' => '2', 'gap_policy' => $policy]);
        $adapter = $this->fakeAdapter();
        $token = $this->memberToken($tenant);
        $this->as($token)->postJson(self::IT."/connections/{$connection->id}/pause")->assertOk();

        $during = fn (int $n) => $this->envelope(['event_id' => "gap-{$n}", 'sequence' => $n, 'occurred_at' => '2026-06-15T09:00:00+07:00']);
        $after = $this->envelope(['event_id' => 'after', 'sequence' => 6, 'occurred_at' => '2026-06-15T09:59:00+07:00']);
        Http::fake([self::FEED => Http::sequence()
            ->push($this->page([$during(3)], $this->pairing(), '5'))
            ->push($this->page([$during(3), $this->quarantined('gap-4', 4, []), $during(5), $after], $this->pairing(), '6')),
        ]);
        $this->postJson(self::IT."/connections/{$connection->id}/resume")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->pull($connection->refresh(), $tenant);

        return [$tenant, $adapter, $token];
    }

    public function test_a_quarantined_event_inside_a_gap_keeps_its_own_reason_and_is_not_part_of_the_gaps_batch_decision(): void
    {
        [$tenant, $adapter, $token] = $this->gapWithQuarantine('REQUIRES_REVIEW');
        $state = fn (string $id) => array_values((array) DB::table('external_events')->where('event_id', $id)->first(['status', 'last_error_code']));
        $this->assertSame([['NEEDS_REVIEW', 'GAP_REVIEW'], ['NEEDS_REVIEW', 'SOURCE_REVIEW_REQUIRED'], ['NEEDS_REVIEW', 'GAP_REVIEW'], ['POSTED', null]],
            array_map($state, ['gap-3', 'gap-4', 'gap-5', 'after']));

        $gap = DB::table('integration_gaps')->first();
        $this->as($token)->postJson(self::IT."/gaps/{$gap->id}/resolution", ['decision' => 'PROCESS', 'reason' => 'The pause was planned'])
            ->assertOk()->assertJsonPath('handled', 2)->assertJsonPath('processed', 2)->assertJsonPath('remaining', 0);

        $this->assertSame([['POSTED', null], ['NEEDS_REVIEW', 'SOURCE_REVIEW_REQUIRED'], ['POSTED', null]], array_map($state, ['gap-3', 'gap-4', 'gap-5']),
            'the batch decision of a gap does not release what the source itself quarantined');
        $this->assertSame(3, $adapter->calls);
    }

    public function test_the_gap_policy_refuses_a_quarantined_event_of_the_gap_before_anything_else(): void
    {
        [, $adapter] = $this->gapWithQuarantine('IGNORE_GAP');

        $this->assertSame([['REJECTED', 'GAP_IGNORED'], ['REJECTED', 'GAP_IGNORED'], ['REJECTED', 'GAP_IGNORED'], ['POSTED', null]],
            array_map(fn ($id) => array_values((array) DB::table('external_events')->where('event_id', $id)->first(['status', 'last_error_code'])), ['gap-3', 'gap-4', 'gap-5', 'after']));
        $this->assertSame(1, $adapter->calls);
    }

    // ------------------------------------------------------------------------------------------------ schema

    public function test_the_database_refuses_an_unknown_access_state_or_a_negative_high_water(): void
    {
        [, $connection] = $this->paired();

        foreach ([['feed_access' => 'BROKEN'], ['source_high_water' => -1]] as $bad) {
            try {
                DB::transaction(fn () => DB::table('integration_connections')->where('id', $connection->id)->update($bad)); // a savepoint: the failed statement must not end the test's transaction
                $this->fail('the database accepted '.json_encode($bad));
            } catch (QueryException $e) {
                $this->assertStringContainsString('integration_connections_', $e->getMessage());
            }
        }
        $this->assertSame('UNKNOWN', $this->row($connection)->feed_access);
    }
}
