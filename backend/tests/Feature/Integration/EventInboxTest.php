<?php

namespace Tests\Feature\Integration;

use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\ReceiveResult;
use App\Domain\Shared\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA6: the inbox is idempotent, takes the tenant from the connection, gates what must not be processed and never changes stored content. */
class EventInboxTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, PayablesFixtures;

    public function test_an_event_is_stored_once_and_the_same_delivery_again_is_a_counted_duplicate(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $envelope = $this->envelope(['sequence' => 41]);

        $first = $this->receive($tenant, $connection, $envelope);
        $again = $this->receive($tenant, $connection, $envelope);
        $reordered = $this->receive($tenant, $connection, array_reverse($envelope, true) + ['sequence' => 99]); // key order and the transport sequence are not content

        $this->assertSame(ReceiveResult::CREATED, $first->outcome);
        $this->assertSame([ReceiveResult::DUPLICATE, ReceiveResult::DUPLICATE], [$again->outcome, $reordered->outcome]);
        $this->assertSame($first->event->id, $again->event->id);
        $this->assertSame(1, DB::table('external_events')->count());
        $row = $first->event->refresh();
        $this->assertSame(['RECEIVED', 2, false, 41], [$row->status, $row->duplicate_count, $row->conflict, $row->sequence]);
        $this->assertSame($tenant->id, $row->tenant_id);
        $this->assertEquals($envelope['payload'], $row->envelope['payload']);
    }

    public function test_the_same_event_id_with_different_content_is_a_conflict_and_the_stored_content_is_untouched(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $envelope = $this->envelope(['event_id' => 'evt-1']);
        $stored = $this->receive($tenant, $connection, $envelope)->event;

        $changed = $envelope;
        $changed['payload']['total_amount'] = '9999999.00';
        $result = $this->receive($tenant, $connection, $changed);

        $this->assertSame(ReceiveResult::CONFLICT, $result->outcome);
        $row = $stored->refresh();
        $this->assertTrue($row->conflict);
        $this->assertSame('1110000.00', $row->envelope['payload']['total_amount']);
        $this->assertSame(1, DB::table('external_events')->count());
        $audit = DB::table('audit_logs')->where('action', 'accounting.integration.event.conflict')->first();
        $this->assertSame([$tenant->id, $row->id], [$audit->tenant_id, $audit->resource_id]);

        // The database itself refuses to rewrite the evidence or remove it.
        foreach (["envelope = '{}'::jsonb", "content_hash = repeat('0', 64)", "event_type = 'x.y'"] as $set) {
            try {
                DB::transaction(fn () => DB::statement("update external_events set {$set} where id = ?", [$row->id]));
                $this->fail("an update of {$set} was accepted");
            } catch (QueryException $e) {
                $this->assertStringContainsString('never changes', $e->getMessage());
            }
        }
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::statement('delete from external_events where id = ?', [$row->id]));
    }

    public function test_the_tenant_comes_from_the_connection_and_never_from_the_payload(): void
    {
        $alpha = $this->tenant('alpha');
        $bravo = $this->tenant('bravo');
        $connection = $this->connectionFor($alpha);

        $event = $this->storedEvent($alpha, $connection, ['tenant_id' => $bravo->id, 'payload' => ['tenant_id' => $bravo->id, 'total_amount' => '1.00']]);

        $this->assertSame($alpha->id, $event->tenant_id);
        $this->assertSame(0, DB::table('external_events')->where('tenant_id', $bravo->id)->count());
    }

    public function test_an_event_that_predates_the_cutover_is_rejected_in_the_tenants_local_date(): void
    {
        $tenant = $this->tenant(); // Asia/Jakarta, UTC+7
        $connection = $this->connectionFor($tenant, ['cutover_date' => '2026-03-01']);

        $before = $this->storedEvent($tenant, $connection, ['occurred_at' => '2026-02-28T16:59:00Z']); // 23:59 on 28 Feb in Jakarta
        $onDay = $this->storedEvent($tenant, $connection, ['occurred_at' => '2026-02-28T17:00:00Z']);  // 00:00 on 1 Mar in Jakarta

        $this->assertSame(['REJECTED', 'BEFORE_CUTOVER'], [$before->status, $before->last_error_code]);
        $this->assertSame('RECEIVED', $onDay->status);
        $this->assertSame('2026-02-28 17:00:00', $onDay->occurred_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_money_as_a_json_number_is_rejected_but_decimal_strings_and_other_numbers_are_fine(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);

        $number = $this->storedEvent($tenant, $connection, ['payload' => ['lines' => [['unit_amount' => 100.5]], 'currency' => 'IDR']]);
        $integer = $this->storedEvent($tenant, $connection, ['payload' => ['total' => 1000]]);
        $tax = $this->storedEvent($tenant, $connection, ['payload' => ['input_tax' => 11.0]]);
        $fine = $this->storedEvent($tenant, $connection, ['payload' => ['total_amount' => '1000.00', 'quantity' => 3, 'odometer' => 120500, 'lines' => [['amount' => '1.00']]]]);

        foreach ([$number, $integer, $tax] as $rejected) {
            $this->assertSame(['REJECTED', 'MONEY_NOT_DECIMAL_STRING'], [$rejected->status, $rejected->last_error_code]);
        }
        $this->assertStringContainsString('payload.lines.0.unit_amount', $number->last_error);
        $this->assertSame('RECEIVED', $fine->status);
    }

    public function test_an_unsupported_schema_major_or_event_type_needs_review_once_an_adapter_exists(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $this->fakeAdapter(supports: fn (string $type, int $major) => $type === 'workshop_invoice.recorded' && $major === 1);

        $v2 = $this->storedEvent($tenant, $connection, ['schema_version' => '2.0']);
        $other = $this->storedEvent($tenant, $connection, ['event_type' => 'tire.fitted']);
        $minor = $this->storedEvent($tenant, $connection, ['schema_version' => '1.7']);

        $this->assertSame(['NEEDS_REVIEW', 'UNSUPPORTED_EVENT'], [$v2->status, $v2->last_error_code]);
        $this->assertSame('NEEDS_REVIEW', $other->status);
        $this->assertSame('RECEIVED', $minor->status, 'additive minor versions stay in the major');
    }

    public function test_a_malformed_envelope_or_one_from_another_source_is_refused_and_nothing_is_stored(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);

        $cases = [
            'no event id' => [['event_id' => ''], 'INVALID_ENVELOPE'],
            'event type' => [['event_type' => 'Workshop Invoice'], 'INVALID_ENVELOPE'],
            'schema version' => [['schema_version' => 'v1'], 'INVALID_ENVELOPE'],
            'occurred_at' => [['occurred_at' => '10/03/2026'], 'INVALID_ENVELOPE'],
            'impossible date' => [['occurred_at' => '2026-13-45T00:00:00Z'], 'INVALID_ENVELOPE'],
            'payload not an object' => [['payload' => 'x'], 'INVALID_ENVELOPE'],
            'other source system' => [['source_system' => 'erp'], 'ENVELOPE_SOURCE_MISMATCH'],
            'other source tenant' => [['source_tenant_reference' => 'fleet-co-2'], 'ENVELOPE_SOURCE_MISMATCH'],
        ];
        foreach ($cases as $label => [$override, $code]) {
            try {
                $this->receive($tenant, $connection, $this->envelope($override));
                $this->fail("{$label} was accepted");
            } catch (DomainException $e) {
                $this->assertSame($code, $e->errorCode, $label);
            }
        }
        $this->assertSame(0, DB::table('external_events')->count());
    }

    public function test_the_same_event_id_on_two_connections_or_tenants_is_two_events(): void
    {
        $alpha = $this->tenant('alpha');
        $bravo = $this->tenant('bravo');
        $one = $this->connectionFor($alpha);
        $two = $this->connectionFor($alpha, ['source_tenant_reference' => 'fleet-co-2']);
        $foreign = $this->connectionFor($bravo);

        $this->receive($alpha, $one, $this->envelope(['event_id' => 'same']));
        $this->receive($alpha, $two, $this->envelope(['event_id' => 'same', 'source_tenant_reference' => 'fleet-co-2']));
        $this->receive($bravo, $foreign, $this->envelope(['event_id' => 'same']));

        $this->assertSame(3, ExternalEvent::query()->withoutGlobalScopes()->where('event_id', 'same')->count());
    }

    public function test_the_events_api_lists_filters_and_shows_the_stored_envelope_for_the_own_tenant_only(): void
    {
        $alpha = $this->tenant('alpha');
        $bravo = $this->tenant('bravo');
        $connection = $this->connectionFor($alpha);
        $recorded = $this->storedEvent($alpha, $connection, ['occurred_at' => '2026-03-10T08:00:00+07:00']);
        $late = $this->storedEvent($alpha, $connection, ['event_type' => 'workshop_invoice.cancelled', 'occurred_at' => '2026-04-20T08:00:00+07:00']);
        $rejected = $this->storedEvent($alpha, $connection, ['occurred_at' => '2025-12-01T08:00:00+07:00']);
        $this->receive($alpha, $connection, $this->envelope(['event_id' => $late->event_id, 'correlation_id' => 'changed'])); // conflict
        $foreign = $this->storedEvent($bravo, $this->connectionFor($bravo));

        $this->as($this->memberToken($alpha));
        $ids = fn (string $query) => collect($this->getJson(self::IT."/events{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$recorded->id, $late->id, $rejected->id])->sort()->values()->all(), $ids(''));
        $this->assertSame([$rejected->id], $ids('?status=REJECTED'));
        $this->assertSame([$late->id], $ids('?event_type=workshop_invoice.cancelled'));
        $this->assertSame([$late->id], $ids('?conflict=1'));
        $this->assertSame([$late->id], $ids('?from=2026-04-01&to=2026-04-30'));
        $this->assertSame([$recorded->id], $ids('?from=2026-03-10&to=2026-03-10'));
        $this->assertSame(3, count($ids("?connection_id={$connection->id}")));
        $this->getJson(self::IT.'/events?status=NOPE')->assertStatus(422);

        $detail = $this->getJson(self::IT."/events/{$recorded->id}")->assertOk()->assertJsonPath('event_id', $recorded->event_id);
        $this->assertSame('1110000.00', $detail->json('envelope.payload.total_amount'));
        $this->getJson(self::IT."/events/{$foreign->id}")->assertNotFound();
        $this->postJson(self::IT."/events/{$foreign->id}/retry", ['reason' => 'try'])->assertNotFound();
    }

    public function test_the_connection_summary_counts_received_against_processed(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant, ['last_cursor' => '77', 'last_error' => 'boom', 'consecutive_failures' => 2]);
        $this->storedEvent($tenant, $connection);
        $posted = $this->storedEvent($tenant, $connection, ['event_id' => 'p']);
        $this->receive($tenant, $connection, $this->envelope(['event_id' => 'p']));
        $this->storedEvent($tenant, $connection, ['occurred_at' => '2025-01-01T00:00:00Z']);
        DB::table('external_events')->where('id', $posted->id)->update(['status' => 'POSTED', 'processed_at' => now()]);

        $this->as($this->memberToken($tenant));
        $summary = $this->getJson(self::IT."/connections/{$connection->id}/summary")->assertOk()->json();

        $this->assertSame([3, 1, 1, 0, '77', 2], [$summary['received'], $summary['posted'], $summary['duplicates'], $summary['conflicts'], $summary['last_cursor'], $summary['consecutive_failures']]);
        $this->assertSame(['RECEIVED' => 1, 'VALIDATED' => 0, 'POSTED' => 1, 'NEEDS_REVIEW' => 0, 'REJECTED' => 1, 'FAILED' => 0, 'DEAD_LETTER' => 0], $summary['counts']);
        $this->assertSame(IntegrationConnection::ACTIVE, $summary['status']);
    }
}
