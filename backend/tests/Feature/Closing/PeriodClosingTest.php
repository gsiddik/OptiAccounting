<?php

namespace Tests\Feature\Closing;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA5 batch J: pre-close, soft close, hard close, controlled reopen and the closing history (spec 33-39, 78). */
class PeriodClosingTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->closingTenant();
        $this->admin = $this->memberToken($this->tenant);
        $this->as($this->admin);
        $this->fakeReconciliations();
    }

    private function url(string $month, string $action): string
    {
        return self::CL.'/closing/periods/'.$this->period($this->tenant, $month)->id."/{$action}";
    }

    /** @return list<string> the closing events of the tenant, oldest first, as "TYPE from>to" */
    private function events(?string $month = null): array
    {
        return DB::table('closing_events')->where('tenant_id', $this->tenant->id)
            ->when($month, fn ($q) => $q->where('accounting_period_id', $this->period($this->tenant, $month)->id))
            ->orderBy('created_at')->orderBy('id')->get()->map(fn ($e) => "{$e->event_type} {$e->from_status}>{$e->to_status}")->all();
    }

    private function audit(string $action): int
    {
        return DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', $action)->count();
    }

    // ------------------------------------------------------------------------------------------ pre-close

    public function test_pre_close_is_repeatable_idempotent_and_changes_no_state(): void
    {
        $this->pendingJournal($this->tenant, '2026-03-10'); // NOT_READY on purpose: pre-close reports blockers, it does not refuse
        $before = [DB::table('accounting_periods')->orderBy('id')->get()->toJson(), DB::table('journal_entries')->orderBy('id')->get()->toJson()];

        $first = $this->postJson($this->url('2026-03', 'pre-close'))->assertOk()->json();
        $second = $this->postJson($this->url('2026-03', 'pre-close'))->assertOk()->json();

        $strip = fn (array $r) => array_diff_key($r, array_flip(['evaluated_at', 'event_id']));
        $this->assertEquals($strip($first), $strip($second), 'the same facts give the same answer');
        $this->assertSame(['NOT_READY', 1, 'HARD'], [$first['result'], $first['blocking_count'], $first['target']]);
        $this->assertNotSame($first['event_id'], $second['event_id']);
        $this->assertSame(['PRE_CLOSE OPEN>OPEN', 'PRE_CLOSE OPEN>OPEN'], $this->events('2026-03'));
        $this->assertSame(2, $this->audit('accounting.period.pre_closed'));
        $this->assertSame($before, [DB::table('accounting_periods')->orderBy('id')->get()->toJson(), DB::table('journal_entries')->orderBy('id')->get()->toJson()], 'pre-close changed nothing');

        $evidence = json_decode(DB::table('closing_events')->where('id', $first['event_id'])->value('readiness_checks'), true);
        $this->assertSame('PENDING_JOURNALS', collect($evidence)->firstWhere('result', 'FAIL')['code'], 'the history keeps what the actor saw');
        $this->assertSame('NOT_READY', DB::table('closing_events')->where('id', $first['event_id'])->value('readiness'));
    }

    public function test_pre_close_works_on_a_soft_closed_period_but_not_on_a_closed_or_future_one(): void
    {
        $this->postJson($this->url('2026-03', 'soft-close'))->assertOk();
        $this->postJson($this->url('2026-03', 'pre-close'))->assertOk();
        $this->hardClose($this->tenant, '2026-03');

        $this->postJson($this->url('2026-03', 'pre-close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_NOT_CLOSABLE');
        $this->forcePeriodStatus($this->tenant, '2026-12', 'FUTURE');
        $this->postJson($this->url('2026-12', 'pre-close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_NOT_CLOSABLE');
    }

    // ------------------------------------------------------------------------------------------ soft close

    public function test_soft_close_moves_open_to_soft_closed_without_a_readiness_gate_and_records_the_evidence(): void
    {
        $this->pendingJournal($this->tenant, '2026-03-10'); // would block a hard close, not a soft close

        $period = $this->postJson($this->url('2026-03', 'soft-close'), ['reason' => 'Mulai penutupan Maret'])->assertOk()->json();

        $this->assertSame('SOFT_CLOSED', $period['status']);
        $this->assertSame(['SOFT_CLOSE OPEN>SOFT_CLOSED'], $this->events('2026-03'));
        $event = DB::table('closing_events')->first();
        $this->assertSame(['SOFT', 'Mulai penutupan Maret', 'NOT_READY'], [
            collect(json_decode($event->readiness_checks, true))->firstWhere('code', 'CHECKLIST')['severity'] === 'WARN' ? 'SOFT' : 'HARD', $event->reason, $event->readiness,
        ], 'a soft close records a soft-target evidence; the pending journal is in it but did not stop the close');
        $this->assertSame(1, $this->audit('accounting.period.soft_closed'));
        $this->assertSame(1, $this->audit('accounting.period.status_changed'));
        $this->postJson($this->url('2026-03', 'soft-close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION');
    }

    public function test_a_soft_closed_period_refuses_normal_posting_and_an_exceptional_posting_is_audited(): void
    {
        $id = $this->pendingJournal($this->tenant, '2026-03-10');
        $this->postJson($this->url('2026-03', 'soft-close'))->assertOk();

        $poster = $this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.journal.post']);
        $this->as($poster)->postJson(self::CL."/journals/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_SOFT_CLOSED');
        $this->assertSame(0, $this->audit('accounting.journal.posted_in_soft_closed_period'));

        $exceptional = $this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.journal.post', 'accounting.journal.post_soft_closed']);
        $this->as($exceptional)->postJson(self::CL."/journals/{$id}/post")->assertOk();

        $log = DB::table('audit_logs')->where('action', 'accounting.journal.posted_in_soft_closed_period')->first();
        $this->assertSame($id, $log->resource_id);
        $after = json_decode($log->changes, true)['after'];
        $this->assertSame(['2026-03', '2026-03-10'], [$after['period'], $after['posting_date']]);
        $this->assertNotNull($after['journal_number']);
        $this->assertNotNull($log->actor_user_id);

        // A posting into an OPEN period leaves no such line.
        $open = $this->as($this->admin)->pendingJournal($this->tenant, '2026-04-10');
        $this->as($exceptional)->postJson(self::CL."/journals/{$open}/post")->assertOk();
        $this->assertSame(1, $this->audit('accounting.journal.posted_in_soft_closed_period'));
    }

    // ------------------------------------------------------------------------------------------ hard close

    public function test_a_ready_period_is_hard_closed_with_history_evidence_and_audit(): void
    {
        $this->postedJournal($this->tenant);

        $period = $this->postJson($this->url('2026-03', 'hard-close'), ['reason' => 'Penutupan bulanan'])->assertOk()->json();

        $this->assertSame('CLOSED', $period['status']);
        $this->assertNotNull($period['closed_at']);
        $this->assertNotNull($period['closed_by']);
        $this->assertSame(['HARD_CLOSE OPEN>CLOSED'], $this->events('2026-03'));
        $event = DB::table('closing_events')->first();
        $this->assertSame(['READY', 'Penutupan bulanan', $period['closed_by']], [$event->readiness, $event->reason, $event->actor_id]);
        $this->assertCount(14, json_decode($event->readiness_checks, true));
        $this->assertSame([1, 1], [$this->audit('accounting.period.hard_closed'), $this->audit('accounting.period.status_changed')]);
        $log = json_decode(DB::table('audit_logs')->where('action', 'accounting.period.hard_closed')->value('changes'), true);
        $this->assertSame(['OPEN', 'CLOSED', 'READY'], [$log['before']['status'], $log['after']['status'], $log['after']['readiness']]);
    }

    public function test_a_period_that_is_not_ready_is_refused_with_the_blocking_checks_and_nothing_changes(): void
    {
        $this->pendingJournal($this->tenant, '2026-03-10');

        $response = $this->postJson($this->url('2026-03', 'hard-close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_NOT_READY');

        $this->assertSame(['PENDING_JOURNALS'], array_column($response->json('details.blocking'), 'code'));
        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-03'));
        $this->assertSame([], $this->events());
        $this->assertSame(0, $this->audit('accounting.period.hard_closed'));
    }

    public function test_a_reconciliation_mismatch_blocks_a_hard_close_when_the_policy_blocks_and_only_warns_when_it_warns(): void
    {
        $this->fakeReconciliations([ReconciliationResult::AR_GL => ReconciliationResult::MISMATCH]);

        $this->postJson($this->url('2026-03', 'hard-close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_NOT_READY')->assertJsonPath('details.blocking.0.code', 'AR_RECONCILIATION');
        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-03'));

        $this->putJson(self::CL.'/closing/policy', ['rules' => [['check_code' => 'AR_RECONCILIATION', 'severity' => 'WARN']]])->assertOk();
        $this->postJson($this->url('2026-03', 'hard-close'))->assertOk()->assertJsonPath('status', 'CLOSED');
        $event = DB::table('closing_events')->first();
        $this->assertSame('READY', $event->readiness);
        $this->assertSame('WARN', collect(json_decode($event->readiness_checks, true))->firstWhere('code', 'AR_RECONCILIATION')['result'], 'the warning is part of the recorded evidence');
    }

    public function test_the_policy_can_require_a_soft_close_before_the_hard_close(): void
    {
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['require_soft_close_first' => true]])->assertOk();

        $this->postJson($this->url('2026-03', 'hard-close'))->assertStatus(409)->assertJsonPath('code', 'SOFT_CLOSE_REQUIRED');
        $this->postJson($this->url('2026-03', 'soft-close'))->assertOk();
        $this->postJson($this->url('2026-03', 'hard-close'))->assertOk()->assertJsonPath('status', 'CLOSED');
        $this->assertSame(['SOFT_CLOSE OPEN>SOFT_CLOSED', 'HARD_CLOSE SOFT_CLOSED>CLOSED'], $this->events('2026-03'));
    }

    public function test_a_second_hard_close_of_the_same_period_is_refused_and_leaves_one_history_row(): void
    {
        $this->hardClose($this->tenant, '2026-03');

        $this->postJson($this->url('2026-03', 'hard-close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION');
        $this->postJson($this->url('2026-03', 'soft-close'))->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION');
        $this->assertSame(['HARD_CLOSE OPEN>CLOSED'], $this->events());
    }

    public function test_a_hard_close_stops_every_later_posting_in_the_service_and_in_the_database(): void
    {
        $draft = $this->draft($this->tenant, ['document_date' => '2026-03-20', 'posting_date' => '2026-03-20'])['id'];
        $approved = $this->pendingJournal($this->tenant, '2026-04-20');
        $this->hardClose($this->tenant, '2026-03');
        $this->forcePeriodStatus($this->tenant, '2026-04', 'CLOSED'); // an approved journal blocks a real close, so this period is arranged directly

        // The service refuses, whoever asks and whatever they may do in a soft-closed period.
        $exceptional = $this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.journal.post', 'accounting.journal.post_soft_closed', 'accounting.journal.create', 'accounting.journal.submit', 'accounting.journal.approve']);
        $this->as($exceptional)->postJson(self::CL."/journals/{$draft}/submit")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->postJson(self::CL."/journals/{$approved}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame(['DRAFT', 'APPROVED'], [DB::table('journal_entries')->where('id', $draft)->value('status'), DB::table('journal_entries')->where('id', $approved)->value('status')]);
        $this->assertSame(0, $this->audit('accounting.journal.posted_in_soft_closed_period'));

        // And the database refuses a posting that tries to slip in behind the service.
        $period = $this->period($this->tenant, '2026-04');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('the posting date is not in an open accounting period');
        DB::table('journal_entries')->where('id', $approved)->update([
            'status' => 'POSTED', 'journal_number' => 'JV-RAW-1', 'accounting_period_id' => $period->id, 'fiscal_year_id' => $period->fiscal_year_id, 'total_debit' => '100000', 'total_credit' => '100000',
        ]);
    }

    // ------------------------------------------------------------------------------------------ authorization

    public function test_each_closing_action_needs_its_own_permission(): void
    {
        $actions = ['pre-close' => 'accounting.period.preclose', 'soft-close' => 'accounting.period.soft_close', 'hard-close' => 'accounting.period.hard_close'];
        foreach ($actions as $action => $permission) {
            $without = $this->memberToken($this->tenant, array_values(array_diff(array_values($actions) + ['accounting.period.view', 'accounting.period.reopen'], [$permission])));
            $this->as($without)->postJson($this->url('2026-03', $action))->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        }
        $this->as($this->memberToken($this->tenant, ['accounting.period.view', ...array_values($actions)]))->postJson($this->url('2026-03', 'reopen'), ['reason' => 'Alasan yang cukup panjang'])
            ->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-03'));
        $this->assertSame([], $this->events());
    }

    public function test_a_read_only_module_cannot_close_or_reopen_but_can_still_read_readiness(): void
    {
        $this->hardClose($this->tenant, '2026-02');
        $this->setModuleState($this->tenant, 'ACCOUNTING_REPORTING', 'READ_ONLY');
        $this->as($this->admin);

        foreach (['pre-close', 'soft-close', 'hard-close'] as $action) {
            $this->postJson($this->url('2026-03', $action))->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        }
        $this->postJson($this->url('2026-02', 'reopen'), ['reason' => 'Alasan yang cukup panjang'])->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        $this->getJson($this->url('2026-03', 'readiness'))->assertOk();
        $this->assertSame(['CLOSED', 'OPEN'], [$this->periodStatus($this->tenant, '2026-02'), $this->periodStatus($this->tenant, '2026-03')]);
    }

    public function test_a_narrower_data_scope_cannot_evaluate_close_or_reopen(): void
    {
        $branch = $this->branches($this->tenant, 'BR1')[0];
        $all = ['accounting.period.view', 'accounting.period.preclose', 'accounting.period.soft_close', 'accounting.period.hard_close', 'accounting.period.reopen', 'accounting.closing.checklist.manage'];
        $scoped = $this->scopedToken($this->tenant, $all, 'BRANCH', $branch);
        $this->hardClose($this->tenant, '2026-02');
        $this->as($scoped);

        foreach (['pre-close', 'soft-close', 'hard-close'] as $action) {
            $this->postJson($this->url('2026-03', $action))->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        }
        $this->postJson($this->url('2026-02', 'reopen'), ['reason' => 'Alasan yang cukup panjang'])->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        foreach (['readiness', 'checklist'] as $read) {
            $this->getJson($this->url('2026-03', $read))->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        }
        $this->getJson(self::CL.'/closing/overview')->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        $this->getJson(self::CL.'/closing/history')->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        $this->postJson($this->url('2026-03', 'checklist/ANY/complete'))->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        $this->getJson(self::CL.'/closing/policy')->assertOk();
        $this->assertSame(['CLOSED', 'OPEN'], [$this->periodStatus($this->tenant, '2026-02'), $this->periodStatus($this->tenant, '2026-03')]);
    }

    // ------------------------------------------------------------------------------------------ reopen

    public function test_a_reopen_needs_a_reason_and_writes_history_and_audit(): void
    {
        $this->hardClose($this->tenant, '2026-03');

        foreach ([[], ['reason' => ''], ['reason' => 'terlalu'], ['reason' => '         ']] as $body) {
            $this->postJson($this->url('2026-03', 'reopen'), $body)->assertStatus(422);
        }
        $this->assertSame('CLOSED', $this->periodStatus($this->tenant, '2026-03'));

        $period = $this->postJson($this->url('2026-03', 'reopen'), ['reason' => 'Koreksi akrual yang terlewat', 'target' => 'SOFT_CLOSED'])->assertOk()->json();

        $this->assertSame(['SOFT_CLOSED', null, null], [$period['status'], $period['closed_at'], $period['closed_by']]);
        $this->assertSame(['HARD_CLOSE OPEN>CLOSED', 'REOPEN CLOSED>SOFT_CLOSED'], $this->events('2026-03'));
        $event = DB::table('closing_events')->where('event_type', 'REOPEN')->first();
        $this->assertSame(['Koreksi akrual yang terlewat'], [$event->reason]);
        $this->assertNotNull($event->actor_id);
        $log = DB::table('audit_logs')->where('action', 'accounting.period.reopened')->first();
        $changes = json_decode($log->changes, true);
        $this->assertSame(['CLOSED', 'SOFT_CLOSED', 'Koreksi akrual yang terlewat'], [$changes['before']['status'], $changes['after']['status'], $changes['after']['reason']]);
        $this->assertNotNull($changes['before']['closed_at']);
    }

    public function test_reopen_preserves_the_whole_history_and_a_period_can_be_closed_again(): void
    {
        $this->hardClose($this->tenant, '2026-03');
        $firstClose = DB::table('closing_events')->where('event_type', 'HARD_CLOSE')->first();

        $this->postJson($this->url('2026-03', 'reopen'), ['reason' => 'Koreksi akrual yang terlewat', 'target' => 'OPEN'])->assertOk()->assertJsonPath('status', 'OPEN');
        $this->hardClose($this->tenant, '2026-03');

        $this->assertSame(['HARD_CLOSE OPEN>CLOSED', 'REOPEN CLOSED>OPEN', 'HARD_CLOSE OPEN>CLOSED'], $this->events('2026-03'));
        $this->assertEquals($firstClose, DB::table('closing_events')->where('id', $firstClose->id)->first(), 'the first close is untouched');
        $this->assertSame(3, $this->getJson(self::CL.'/closing/history?period_id='.$this->period($this->tenant, '2026-03')->id)->json('total'));
    }

    public function test_a_soft_closed_period_is_reopened_to_open_with_a_reason_and_an_event(): void
    {
        $this->postJson($this->url('2026-03', 'soft-close'))->assertOk();

        $this->postJson($this->url('2026-03', 'reopen'), ['target' => 'SOFT_CLOSED', 'reason' => 'Tidak ada yang perlu dibuka'])->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION');
        $this->postJson($this->url('2026-03', 'reopen'), ['reason' => 'Ada transaksi susulan'])->assertOk()->assertJsonPath('status', 'OPEN'); // the default step back from a soft close
        $this->postJson($this->url('2026-03', 'reopen'), ['reason' => 'Ada transaksi susulan'])->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION'); // an OPEN period is not reopened

        $this->assertSame(['SOFT_CLOSE OPEN>SOFT_CLOSED', 'REOPEN SOFT_CLOSED>OPEN'], $this->events('2026-03'));
    }

    public function test_the_closing_history_is_append_only_in_the_database(): void
    {
        $this->hardClose($this->tenant, '2026-03');
        $id = DB::table('closing_events')->value('id');

        foreach ([
            fn () => DB::table('closing_events')->where('id', $id)->update(['reason' => 'diubah']),
            fn () => DB::table('closing_events')->where('id', $id)->delete(),
            fn () => DB::table('closing_events')->where('tenant_id', $this->tenant->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('the closing history accepted a change');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertSame(1, DB::table('closing_events')->count());
    }

    public function test_the_database_lets_a_period_leave_closed_only_with_a_reopen_row_of_the_same_transaction(): void
    {
        $this->hardClose($this->tenant, '2026-03');
        $period = $this->period($this->tenant, '2026-03');
        $move = fn (string $to) => fn () => DB::table('accounting_periods')->where('id', $period->id)->update(['status' => $to]);

        // A raw update, or a history row that does not match (other period, other target, other transition), is refused.
        $event = fn (array $override) => DB::table('closing_events')->insert($override + [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'accounting_period_id' => $period->id, 'fiscal_year_id' => $period->fiscal_year_id, 'event_type' => 'REOPEN',
            'from_status' => 'CLOSED', 'to_status' => 'OPEN', 'reason' => 'Percobaan langsung ke basis data', 'created_at' => now(),
        ]);
        $attempts = [
            'no history row' => fn () => $move('OPEN')(),
            'a row for another period' => function () use ($event, $move) {
                $event(['accounting_period_id' => $this->period($this->tenant, '2026-04')->id]);
                $move('OPEN')();
            },
            'a row for another target' => function () use ($event, $move) {
                $event(['to_status' => 'SOFT_CLOSED']);
                $move('OPEN')();
            },
            'a row that is not a reopen' => function () use ($event, $move) {
                $event(['event_type' => 'PRE_CLOSE']);
                $move('OPEN')();
            },
            'FUTURE is not a reopen target' => function () use ($event, $move) {
                $event(['to_status' => 'FUTURE']);
                $move('FUTURE')();
            },
        ];
        foreach ($attempts as $label => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("the database accepted: {$label}");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0], $label);
            }
        }
        $this->assertSame('CLOSED', $this->periodStatus($this->tenant, '2026-03'));

        // With the matching row in the same transaction the door opens.
        DB::transaction(function () use ($event, $move) {
            $event([]);
            $move('OPEN')();
        });
        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-03'));
    }

    public function test_closed_period_journals_stay_immutable_before_after_a_close_and_after_a_reopen(): void
    {
        $journal = $this->postedJournal($this->tenant, ['posting_date' => '2026-03-05', 'document_date' => '2026-03-05']);
        $line = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->first();
        $fingerprint = fn () => md5(DB::table('journal_entries')->where('id', $journal->id)->first()->total_debit.DB::table('journal_lines')->where('journal_entry_id', $journal->id)->orderBy('id')->get()->toJson());
        $refused = function (string $when) use ($journal, $line) {
            foreach ([
                fn () => DB::table('journal_entries')->where('id', $journal->id)->update(['description' => 'diubah']),
                fn () => DB::table('journal_entries')->where('id', $journal->id)->delete(),
                fn () => DB::table('journal_lines')->where('id', $line->id)->update(['debit' => '1.0000', 'credit' => '0.0000']),
                fn () => DB::table('journal_lines')->where('id', $line->id)->delete(),
            ] as $i => $attempt) {
                try {
                    DB::transaction($attempt);
                    $this->fail("posted history changed {$when} (attempt {$i})");
                } catch (QueryException $e) {
                    $this->assertSame('23514', $e->errorInfo[0], "{$when} (attempt {$i})");
                }
            }
        };
        $before = $fingerprint();

        $refused('before the close');
        $this->hardClose($this->tenant, '2026-03');
        $refused('while closed');
        $this->postJson($this->url('2026-03', 'reopen'), ['reason' => 'Koreksi dengan jurnal pembalik', 'target' => 'OPEN'])->assertOk();
        $refused('after the reopen');

        $this->assertSame($before, $fingerprint());
        // A correction is a reversal journal, never an edit.
        $this->postJson(self::CL."/journals/{$journal->id}/reverse", ['reason' => 'Koreksi', 'posting_date' => '2026-03-06'])->assertCreated();
        $this->assertSame($before, $fingerprint());
    }

    // ------------------------------------------------------------------------------------------ reopen policy

    public function test_by_default_a_period_cannot_be_reopened_while_a_later_one_is_closed(): void
    {
        $this->hardClose($this->tenant, '2026-03');
        $this->hardClose($this->tenant, '2026-04');
        $body = ['reason' => 'Koreksi akrual yang terlewat', 'target' => 'OPEN'];

        $this->postJson($this->url('2026-03', 'reopen'), $body)->assertStatus(409)->assertJsonPath('code', 'REOPEN_LATER_PERIOD_CLOSED')->assertJsonPath('details.later_closed.0', '2026-04');
        $this->assertSame('CLOSED', $this->periodStatus($this->tenant, '2026-03'));

        $this->postJson($this->url('2026-04', 'reopen'), $body)->assertOk();
        $this->postJson($this->url('2026-03', 'reopen'), $body)->assertOk();
        $this->assertSame(['OPEN', 'OPEN'], [$this->periodStatus($this->tenant, '2026-03'), $this->periodStatus($this->tenant, '2026-04')]);
    }

    public function test_the_policy_limits_how_many_periods_back_a_reopen_may_reach(): void
    {
        foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $month) {
            $this->hardClose($this->tenant, $month);
        }
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['reopen_requires_later_open' => false, 'reopen_max_periods_back' => 1]])->assertOk();
        $body = ['reason' => 'Koreksi akrual yang terlewat', 'target' => 'SOFT_CLOSED'];

        $this->postJson($this->url('2026-01', 'reopen'), $body)->assertStatus(409)->assertJsonPath('code', 'REOPEN_TOO_FAR_BACK')->assertJsonPath('details.periods_back', 3);
        $this->postJson($this->url('2026-02', 'reopen'), $body)->assertStatus(409)->assertJsonPath('code', 'REOPEN_TOO_FAR_BACK')->assertJsonPath('details.periods_back', 2);
        $this->postJson($this->url('2026-03', 'reopen'), $body)->assertOk()->assertJsonPath('status', 'SOFT_CLOSED'); // one back from April
        $this->postJson($this->url('2026-04', 'reopen'), $body)->assertOk();

        $this->putJson(self::CL.'/closing/policy', ['settings' => ['reopen_max_periods_back' => null]])->assertOk();
        $this->postJson($this->url('2026-01', 'reopen'), $body)->assertOk();
    }

    public function test_the_person_who_closed_a_period_may_be_barred_from_reopening_it(): void
    {
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['separate_closer_and_reopener' => true]])->assertOk();
        $this->hardClose($this->tenant, '2026-03');
        $body = ['reason' => 'Koreksi akrual yang terlewat', 'target' => 'OPEN'];

        $this->postJson($this->url('2026-03', 'reopen'), $body)->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION')->assertJsonPath('details.rule', 'closer_not_reopener');
        $this->assertSame('CLOSED', $this->periodStatus($this->tenant, '2026-03'));

        $this->as($this->memberToken($this->tenant))->postJson($this->url('2026-03', 'reopen'), $body)->assertOk()->assertJsonPath('status', 'OPEN');
    }

    public function test_reopening_the_last_period_of_a_closed_fiscal_year_reopens_the_year_in_the_same_transaction(): void
    {
        foreach (range(1, 12) as $n) {
            $this->hardClose($this->tenant, sprintf('2026-%02d', $n));
        }
        $year = $this->fiscalYear($this->tenant, 'FY2026');
        $this->postJson(self::CL."/fiscal-years/{$year->id}/close")->assertOk()->assertJsonPath('status', 'CLOSED');

        $this->postJson($this->url('2026-12', 'reopen'), ['reason' => 'Koreksi setelah audit', 'target' => 'SOFT_CLOSED'])->assertOk()->assertJsonPath('status', 'SOFT_CLOSED');

        $this->assertSame('OPEN', DB::table('fiscal_years')->where('id', $year->id)->value('status'));
        $this->assertSame(1, $this->audit('accounting.fiscal_year.reopened'));
        $yearEvent = DB::table('closing_events')->where('fiscal_year_id', $year->id)->whereNull('accounting_period_id')->first();
        $this->assertSame(['REOPEN', 'CLOSED', 'OPEN', 'Koreksi setelah audit'], [$yearEvent->event_type, $yearEvent->from_status, $yearEvent->to_status, $yearEvent->reason]);
        $this->assertSame(['HARD_CLOSE OPEN>CLOSED', 'REOPEN CLOSED>SOFT_CLOSED'], $this->events('2026-12'));
    }

    // ------------------------------------------------------------------------------------------ history and overview

    public function test_the_history_lists_events_newest_first_and_filters_by_period_year_and_type(): void
    {
        $this->postJson($this->url('2026-03', 'pre-close'))->assertOk();
        $this->postJson($this->url('2026-03', 'soft-close'))->assertOk();
        $this->hardClose($this->tenant, '2026-03');
        $this->hardClose($this->tenant, '2026-02');

        $all = $this->getJson(self::CL.'/closing/history')->assertOk();
        $this->assertSame(4, $all->json('total'));
        $this->assertSame(['HARD_CLOSE', 'HARD_CLOSE', 'SOFT_CLOSE', 'PRE_CLOSE'], array_column($all->json('data'), 'event_type'));
        $first = $all->json('data.0');
        $this->assertSame(['2026-02', 'FY2026', 'OPEN', 'CLOSED', 'READY'], [$first['period_code'], $first['fiscal_year_code'], $first['from_status'], $first['to_status'], $first['readiness']]);
        $this->assertNotNull($first['actor_name']);
        $this->assertCount(14, $first['readiness_checks']);

        $this->assertSame(3, $this->getJson(self::CL.'/closing/history?period_id='.$this->period($this->tenant, '2026-03')->id)->json('total'));
        $this->assertSame(2, $this->getJson(self::CL.'/closing/history?event_type=HARD_CLOSE')->json('total'));
        $this->assertSame(4, $this->getJson(self::CL.'/closing/history?fiscal_year_id='.$this->fiscalYear($this->tenant, 'FY2026')->id)->json('total'));
        $this->assertSame(1, $this->getJson(self::CL.'/closing/history?per_page=1')->json('last_page') === 4 ? 1 : 0);
        $this->getJson(self::CL.'/closing/history?event_type=NOPE')->assertStatus(422);
    }

    public function test_the_overview_shows_every_period_with_its_status_last_event_and_a_readiness_flag_for_ended_open_periods(): void
    {
        $today = $this->tenant->businessDate();
        $this->hardClose($this->tenant, '2026-01');
        $this->pendingJournal($this->tenant, '2026-02-10');

        $overview = $this->getJson(self::CL.'/closing/overview')->assertOk()->json();
        $periods = array_column($overview['periods'], null, 'code');

        $this->assertCount(12, $overview['periods']);
        $this->assertSame($today, $overview['business_date']);
        $this->assertSame(['CLOSED', 'HARD_CLOSE', null], [$periods['2026-01']['status'], $periods['2026-01']['last_event']['event_type'], $periods['2026-01']['readiness']]);
        $this->assertSame(['NOT_READY', ['PENDING_JOURNALS']], [$periods['2026-02']['readiness']['result'], $periods['2026-02']['readiness']['blocking']]);
        $this->assertSame('READY', $periods['2026-03']['readiness']['result']);
        foreach ($overview['periods'] as $period) {
            $ended = $period['status'] !== 'CLOSED' && $period['end_date'] <= $today;
            $this->assertSame($ended, $period['readiness'] !== null, "{$period['code']}: only an open or soft-closed period that has ended is evaluated");
        }
        $this->assertSame('NOT_RUN', $overview['fiscal_years'][0]['year_end']['status']);
    }

    public function test_every_closing_route_answers_not_found_for_another_tenants_period(): void
    {
        $other = $this->closingTenant('bravo');
        $this->as($this->memberToken($other));
        $march = $this->period($this->tenant, '2026-03')->id;

        foreach (['readiness' => 'get', 'checklist' => 'get', 'pre-close' => 'post', 'soft-close' => 'post', 'hard-close' => 'post', 'checklist/ANY/complete' => 'post', 'checklist/ANY/waive' => 'post', 'checklist/ANY/reset' => 'post'] as $path => $method) {
            $this->json($method, self::CL."/closing/periods/{$march}/{$path}", [])->assertNotFound();
        }
        $this->postJson(self::CL."/closing/periods/{$march}/reopen", ['reason' => 'Percobaan lintas tenant'])->assertNotFound();
        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-03'));
        $this->assertSame(0, DB::table('closing_events')->where('tenant_id', $this->tenant->id)->count());

        // The other tenant's own closing data never shows ours.
        $this->as($this->admin)->hardClose($this->tenant, '2026-03');
        $this->as($this->memberToken($other));
        $this->assertSame(0, $this->getJson(self::CL.'/closing/history')->json('total'));
        $this->assertSame([], array_column(array_filter($this->getJson(self::CL.'/closing/overview')->json('periods'), fn ($p) => $p['last_event'] !== null), 'code'));
    }
}
