<?php

namespace Tests\Feature\Integration;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\EventProcessor;
use App\Domain\Integration\Services\IntegrationRunner;
use App\Domain\Integration\Services\ProcessOutcome;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationFixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA6: events are applied through the adapter in isolation; failures never touch accounting data and are retried, escalated and audited. */
class EventProcessorTest extends TestCase
{
    use AccountingFixtures, Fixtures, IntegrationFixtures, PayablesFixtures;

    private function process(ExternalEvent $event): ExternalEvent
    {
        return $this->inTenant($event->tenant, fn () => app(EventProcessor::class)->process($event));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_successful_event_is_posted_with_its_journal_and_source_links(): void
    {
        $tenant = $this->accountingTenant();
        $connection = $this->connectionFor($tenant);
        $journal = $this->postedJournal($tenant);
        $adapter = $this->fakeAdapter(fn (ExternalEvent $e) => new ProcessOutcome($journal->id, [
            ['source_type' => 'workshop_invoice', 'source_id' => 'wi-1', 'source_document_number' => 'WI-001', 'document_type' => 'ap_invoice', 'document_id' => '0192b6a0-0000-7000-8000-000000000001'],
            ['source_type' => 'workshop_invoice_payment', 'source_id' => 'pay-1'],
        ]));
        $event = $this->storedEvent($tenant, $connection);

        $done = $this->process($event);
        $again = $this->process($event);

        $this->assertSame(['POSTED', $journal->id, null], [$done->status, $done->resulting_journal_id, $done->last_error_code]);
        $this->assertNotNull($done->processed_at);
        $this->assertSame(1, $adapter->calls, 'a posted event is never applied twice');
        $this->assertSame('POSTED', $again->status);

        $links = DB::table('external_source_links')->where('source_event_id', $event->event_id)->orderBy('source_type')->get();
        $this->assertCount(2, $links);
        $this->assertSame([$journal->id, 'ap_invoice', 'WI-001'], [$links[0]->journal_id, $links[0]->document_type, $links[0]->source_document_number]);
        $this->assertSame($journal->id, $links[1]->journal_id, 'a link defaults to the outcome journal');

        $this->as($this->memberToken($tenant));
        $this->getJson(self::IT."/source-links?journal_id={$journal->id}")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.source_system', 'optifleet');
        $this->getJson(self::IT.'/source-links?document_type=ap_invoice&document_id=0192b6a0-0000-7000-8000-000000000001')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::IT.'/source-links')->assertStatus(422);
        $this->getJson(self::IT."/events/{$event->id}")->assertOk()->assertJsonCount(2, 'source_links')->assertJsonPath('resulting_journal_id', $journal->id);
    }

    public function test_a_business_refusal_needs_review_and_rolls_back_whatever_the_adapter_wrote(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $this->fakeAdapter(function (ExternalEvent $e) use ($tenant) {
            DB::table('external_dimensions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'source_system' => 'optifleet', 'source_tenant_reference' => 'x',
                'entity_type' => 'VENDOR', 'external_id' => 'half-written', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
            throw new DomainException('The period is closed.', 'PERIOD_NOT_OPEN', 409);
        });

        $event = $this->process($this->storedEvent($tenant, $connection));

        $this->assertSame(['NEEDS_REVIEW', 'PERIOD_NOT_OPEN', 0, null], [$event->status, $event->last_error_code, $event->attempts, $event->next_attempt_at]);
        $this->assertSame(0, DB::table('external_dimensions')->count(), 'the adapter transaction was rolled back');
        $this->assertSame(0, DB::table('external_source_links')->count());
    }

    public function test_a_technical_failure_backs_off_and_the_fifth_attempt_is_dead_lettered(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $adapter = $this->fakeAdapter(fn () => throw new RuntimeException('connection reset'));
        $event = $this->storedEvent($tenant, $connection);
        Carbon::setTestNow('2026-03-10 10:00:00');

        foreach ([1 => 1, 2 => 5, 3 => 15, 4 => 60] as $attempt => $minutes) {
            $event = $this->process($event);
            $this->assertSame(['FAILED', $attempt, 'RuntimeException', 'connection reset'], [$event->status, $event->attempts, $event->last_error_code, $event->last_error]);
            $this->assertEquals(now()->addMinutes($minutes), $event->next_attempt_at);

            $this->process($event); // not due yet: untouched
            $this->assertSame($attempt, $event->refresh()->attempts);
            Carbon::setTestNow(now()->addMinutes($minutes)->addSecond());
        }

        $event = $this->process($event);
        $this->assertSame(['DEAD_LETTER', 5, null], [$event->status, $event->attempts, $event->next_attempt_at]);
        $this->assertSame(5, $adapter->calls);
        $this->process($event);
        $this->assertSame(5, $adapter->calls, 'a dead letter is not retried by itself');
        $audit = DB::table('audit_logs')->where('action', 'accounting.integration.event.dead_lettered')->first();
        $this->assertSame([$tenant->id, $event->id], [$audit->tenant_id, $audit->resource_id]);
    }

    public function test_no_adapter_or_an_unsupported_type_needs_review(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $event = $this->storedEvent($tenant, $connection);

        $noAdapter = $this->process($event);
        $this->assertSame(['NEEDS_REVIEW', 'NO_ADAPTER'], [$noAdapter->status, $noAdapter->last_error_code]);

        $this->fakeAdapter(supports: fn () => false);
        DB::table('external_events')->where('id', $event->id)->update(['status' => 'RECEIVED']);
        $unsupported = $this->process($event);
        $this->assertSame(['NEEDS_REVIEW', 'UNSUPPORTED_EVENT'], [$unsupported->status, $unsupported->last_error_code]);
    }

    public function test_a_person_retries_with_a_reason_and_the_retry_is_audited_and_authorized(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $broken = true;
        $this->fakeAdapter(function () use (&$broken) {
            return $broken ? throw new DomainException('Vendor is not mapped.', 'VENDOR_UNMAPPED', 422) : new ProcessOutcome;
        });
        $event = $this->process($this->storedEvent($tenant, $connection));
        $rejected = $this->storedEvent($tenant, $connection, ['occurred_at' => '2020-01-01T00:00:00Z']);
        $this->assertSame('NEEDS_REVIEW', $event->status);

        $reader = $this->memberToken($tenant, ['accounting.integration.view']);
        $retrier = $this->memberToken($tenant, ['accounting.integration.view', 'accounting.integration.retry']);

        $this->as($reader)->postJson(self::IT."/events/{$event->id}/retry", ['reason' => 'Vendor mapped'])->assertForbidden();
        $this->as($retrier)->postJson(self::IT."/events/{$event->id}/retry", [])->assertStatus(422);
        $this->postJson(self::IT."/events/{$rejected->id}/retry", ['reason' => 'Try anyway'])->assertStatus(409)->assertJsonPath('code', 'EVENT_NOT_RETRYABLE');

        $this->postJson(self::IT."/events/{$event->id}/retry", ['reason' => 'Still not mapped'])->assertOk()->assertJsonPath('status', 'NEEDS_REVIEW')->assertJsonPath('last_error_code', 'VENDOR_UNMAPPED');
        $broken = false;
        $this->postJson(self::IT."/events/{$event->id}/retry", ['reason' => 'Vendor mapped'])->assertOk()->assertJsonPath('status', 'POSTED');
        $this->postJson(self::IT."/events/{$event->id}/retry", ['reason' => 'Again'])->assertStatus(409);

        $retries = DB::table('audit_logs')->where('action', 'accounting.integration.event.retried')->orderBy('occurred_at')->get();
        $this->assertCount(2, $retries);
        $this->assertSame('Vendor mapped', json_decode($retries[1]->changes, true)['after']['reason']);
        $this->assertSame([$tenant->id, $event->id], [$retries[1]->tenant_id, $retries[1]->resource_id]);
        $this->assertNotNull($retries[1]->actor_user_id);
    }

    public function test_a_dead_letter_gets_a_fresh_set_of_attempts_when_retried(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connectionFor($tenant);
        $this->fakeAdapter(fn () => throw new RuntimeException('down'));
        $event = $this->storedEvent($tenant, $connection);
        DB::table('external_events')->where('id', $event->id)->update(['status' => 'DEAD_LETTER', 'attempts' => 5]);

        $this->as($this->memberToken($tenant));
        $this->postJson(self::IT."/events/{$event->id}/retry", ['reason' => 'Source is back'])->assertOk()->assertJsonPath('status', 'FAILED')->assertJsonPath('attempts', 1);
    }

    public function test_the_scheduled_run_posts_due_events_of_active_connections_of_entitled_writable_tenants_only(): void
    {
        $alpha = $this->tenant('alpha');
        $bravo = $this->tenant('bravo');
        $paused = $this->connectionFor($alpha, ['source_tenant_reference' => 'paused', 'status' => IntegrationConnection::PAUSED]);
        $active = $this->connectionFor($alpha);
        $readOnly = $this->connectionFor($bravo);
        $adapter = $this->fakeAdapter();

        $a = $this->storedEvent($alpha, $active);
        $b = $this->storedEvent($alpha, $active);
        $failedLater = $this->storedEvent($alpha, $active);
        DB::table('external_events')->where('id', $failedLater->id)->update(['status' => 'FAILED', 'attempts' => 1, 'next_attempt_at' => now()->addMinutes(30)]);
        $onPaused = $this->storedEvent($alpha, $paused, ['source_tenant_reference' => 'paused']);
        $foreign = $this->storedEvent($bravo, $readOnly);
        DB::table('subscriptions')->where('tenant_id', $bravo->id)->update(['status' => 'PAST_DUE']);
        app(AccessCache::class)->touchTenant($bravo->id);

        $totals = app(IntegrationRunner::class)->processDue();

        $this->assertSame(['events' => 2, 'posted' => 2], $totals);
        $this->assertSame(2, $adapter->calls);
        $status = fn (ExternalEvent $e) => DB::table('external_events')->where('id', $e->id)->value('status');
        $this->assertSame(['POSTED', 'POSTED', 'FAILED', 'RECEIVED', 'RECEIVED'], array_map($status, [$a, $b, $failedLater, $onPaused, $foreign]));

        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertSame(['events' => 1, 'posted' => 1], app(IntegrationRunner::class)->processDue());
        $this->artisan('integration:process')->assertSuccessful();
    }
}
