<?php

namespace Tests\Feature\Closing;

use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA5 batch K: the year-end reversal, its authorization and its interplay with the controlled reopen (spec 71, 79, 81, 82). */
class YearEndReversalTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;

    private const REASON = 'Salah klasifikasi pendapatan';

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->yearEndTenant();
        $this->admin = $this->memberToken($this->tenant);
        $this->as($this->admin);
        $this->fakeReconciliations();
        $this->book(500000, 'revenue');
        $this->book(200000, 'expense');
    }

    private function book(int $amount, string $kind, string $date = '2026-04-10'): void
    {
        $lines = $kind === 'revenue' ? $this->lines($this->tenant, (string) $amount, '1110', '4100') : $this->lines($this->tenant, (string) $amount, '6100', '1110');
        $this->postedJournal($this->tenant, ['document_date' => $date, 'posting_date' => $date, 'lines' => $lines]);
    }

    private function year(string $code = 'FY2026'): string
    {
        return $this->fiscalYear($this->tenant, $code)->id;
    }

    private function net(string $code): string
    {
        return Money::str((string) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.tenant_id', $this->tenant->id)->where('e.status', 'POSTED')->where('a.code', $code)->selectRaw('coalesce(sum(l.debit - l.credit), 0) as n')->value('n'));
    }

    /** @return list<string> the year-level closing events of the fiscal year (no period), oldest first */
    private function events(): array
    {
        return DB::table('closing_events')->where('tenant_id', $this->tenant->id)->where('fiscal_year_id', $this->year())->whereNull('accounting_period_id')
            ->orderBy('created_at')->orderBy('id')->pluck('event_type')->all();
    }

    private function audit(string $action): int
    {
        return DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', $action)->count();
    }

    private function closeTheYear(): void
    {
        foreach (range(1, 12) as $n) {
            $this->hardClose($this->tenant, sprintf('2026-%02d', $n));
        }
        $this->postJson(self::CL.'/fiscal-years/'.$this->year().'/close')->assertOk();
    }

    private function runYearEnd(): void
    {
        $this->postJson(self::CL.'/year-end/'.$this->year().'/run')->assertCreated();
    }

    private function reverse(array $body = ['reason' => self::REASON]): TestResponse
    {
        return $this->postJson(self::CL.'/year-end/'.$this->year().'/reverse', $body);
    }

    // ------------------------------------------------------------------------------------------ reverse

    public function test_a_reversal_restores_the_balances_marks_the_run_and_keeps_the_whole_trail(): void
    {
        $this->runYearEnd();
        $closing = DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->first();
        $this->assertSame(['0.0000', '0.0000', '-300000.0000'], [$this->net('4100'), $this->net('6100'), $this->net('3200')]);

        $run = $this->reverse()->assertOk()->json();

        $this->assertSame(['REVERSED', 1, self::REASON, $closing->id], [$run['status'], $run['run_no'], $run['reverse_reason'], $run['journal_entry_id']]);
        $reversal = DB::table('journal_entries')->where('id', $run['reversal_journal_id'])->first();
        $this->assertSame(['REVERSAL', 'POSTED', $closing->id, '2026-12-31'], [$reversal->journal_type, $reversal->status, $reversal->reverses_journal_id, substr($reversal->posting_date, 0, 10)]);
        $this->assertSame($reversal->id, DB::table('journal_entries')->where('id', $closing->id)->value('reversed_by_journal_id'));
        $this->assertSame(['-500000.0000', '200000.0000', '0.0000'], [$this->net('4100'), $this->net('6100'), $this->net('3200')], 'the temporary accounts carry the year again');

        $record = DB::table('year_end_closings')->first();
        $this->assertSame(['REVERSED', $reversal->id, self::REASON], [$record->status, $record->reversal_journal_id, $record->reverse_reason]);
        $this->assertNotNull($record->reversed_by);
        $this->assertSame(['YEAR_END_RUN', 'YEAR_END_REVERSE'], $this->events());
        $event = DB::table('closing_events')->where('event_type', 'YEAR_END_REVERSE')->first();
        $this->assertSame([self::REASON, $reversal->id, 'POSTED', 'REVERSED'], [$event->reason, $event->journal_entry_id, $event->from_status, $event->to_status]);
        $this->assertSame(1, $this->audit('accounting.year_end.reversed'));
        $this->assertSame(1, $this->audit('accounting.journal.reversed'));
    }

    public function test_after_a_reversal_a_new_run_is_possible_and_both_runs_stay_traceable(): void
    {
        $this->runYearEnd();
        $this->reverse()->assertOk();
        $this->book(100000, 'revenue', '2026-05-10'); // the correction that made the reversal necessary

        $second = $this->postJson(self::CL.'/year-end/'.$this->year().'/run')->assertCreated()->json();

        $this->assertSame([2, 'POSTED', '400000.0000'], [$second['run_no'], $second['status'], $second['net_result']]);
        $show = $this->getJson(self::CL.'/year-end/'.$this->year())->assertOk()->json();
        $this->assertSame([['REVERSED', 1], ['POSTED', 2]], array_map(fn ($r) => [$r['status'], $r['run_no']], $show['runs']));
        $this->assertSame(['POSTED', 2], [$show['year_end']['status'], $show['year_end']['run_no']]);
        $this->assertSame([$this->year().':1', $this->year().':2'], DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->orderBy('source_id')->pluck('source_id')->all());
        $this->assertSame(2, DB::table('accounting_events')->where('event_type', 'YEAR_END_CLOSING')->where('status', 'POSTED')->count());
        $this->assertSame(['0.0000', '0.0000', '-400000.0000'], [$this->net('4100'), $this->net('6100'), $this->net('3200')]);
        $this->assertSame(['YEAR_END_RUN', 'YEAR_END_REVERSE', 'YEAR_END_RUN'], $this->events());
    }

    public function test_a_reversal_needs_a_reason_and_a_posted_run(): void
    {
        $this->reverse()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_NOT_POSTED');

        $this->runYearEnd();
        foreach ([[], ['reason' => ''], ['reason' => 'terlalu'], ['reason' => str_repeat('x', 501)]] as $body) {
            $this->reverse($body)->assertStatus(422);
        }
        $this->assertSame('POSTED', DB::table('year_end_closings')->value('status'));
        $this->assertSame(0, DB::table('journal_entries')->where('journal_type', 'REVERSAL')->count());

        $this->reverse()->assertOk();
        $this->reverse()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_NOT_POSTED');
    }

    public function test_a_closing_journal_reversed_through_the_journal_route_is_caught_up_not_reversed_twice(): void
    {
        $this->runYearEnd();
        $closing = DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->value('id');
        $reversal = $this->postJson(self::CL."/journals/{$closing}/reverse", ['reason' => 'Dibalik lewat jurnal', 'posting_date' => '2026-12-31'])->assertCreated()->json('id');

        $run = $this->reverse()->assertOk()->json();

        $this->assertSame(['REVERSED', $reversal], [$run['status'], $run['reversal_journal_id']]);
        $this->assertSame(1, DB::table('journal_entries')->where('journal_type', 'REVERSAL')->count());
        $this->assertSame(['-500000.0000', '200000.0000', '0.0000'], [$this->net('4100'), $this->net('6100'), $this->net('3200')]);
    }

    public function test_a_year_cannot_be_reversed_while_a_later_year_end_sweeps_it(): void
    {
        $this->runYearEnd();
        $next = $this->postJson(self::CL.'/fiscal-years', ['code' => 'FY2027', 'name' => 'Tahun 2027', 'start_date' => '2027-01-01'])->assertCreated()->json('id');
        $this->postJson(self::CL."/fiscal-years/{$next}/open")->assertOk();
        $this->book(70000, 'revenue', '2027-02-10');
        $this->postJson(self::CL."/year-end/{$next}/run")->assertCreated();

        $this->reverse()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_LATER_YEAR_POSTED')->assertJsonPath('details.fiscal_year', 'FY2027');
        $this->assertSame('POSTED', DB::table('year_end_closings')->where('fiscal_year_id', $this->year())->value('status'));

        $this->postJson(self::CL."/year-end/{$next}/reverse", ['reason' => self::REASON])->assertOk();
        $this->reverse()->assertOk();
        $this->assertSame(['REVERSED', 'REVERSED'], DB::table('year_end_closings')->orderBy('run_at')->pluck('status')->all());
    }

    // ------------------------------------------------------------------------------------------ controlled reopen

    public function test_a_posted_year_end_closing_blocks_the_reopen_of_every_period_of_its_year_until_it_is_reversed(): void
    {
        $this->runYearEnd();
        $this->hardClose($this->tenant, '2026-03');
        $this->hardClose($this->tenant, '2026-12');
        $body = ['reason' => 'Koreksi akrual yang terlewat', 'target' => 'OPEN'];

        foreach (['2026-03', '2026-12'] as $month) {
            $this->postJson(self::CL.'/closing/periods/'.$this->period($this->tenant, $month)->id.'/reopen', $body)
                ->assertStatus(409)->assertJsonPath('code', 'YEAR_END_POSTED')->assertJsonPath('details.fiscal_year', 'FY2026');
            $this->assertSame('CLOSED', $this->periodStatus($this->tenant, $month));
        }

        $this->reverse()->assertOk();
        $this->postJson(self::CL.'/closing/periods/'.$this->period($this->tenant, '2026-03')->id.'/reopen', $body)->assertOk()->assertJsonPath('status', 'OPEN');
    }

    public function test_reversing_a_closed_year_reopens_the_last_period_and_the_year_under_the_reopen_rules(): void
    {
        $this->runYearEnd();
        $this->closeTheYear();

        $this->reverse()->assertOk()->assertJsonPath('status', 'REVERSED');

        $this->assertSame(['SOFT_CLOSED', 'CLOSED', 'OPEN'], [$this->periodStatus($this->tenant, '2026-12'), $this->periodStatus($this->tenant, '2026-11'), DB::table('fiscal_years')->where('id', $this->year())->value('status')]);
        $this->assertSame(['HARD_CLOSE OPEN>CLOSED', 'REOPEN CLOSED>SOFT_CLOSED'], DB::table('closing_events')->where('accounting_period_id', $this->period($this->tenant, '2026-12')->id)->orderBy('created_at')->orderBy('id')
            ->get()->map(fn ($e) => "{$e->event_type} {$e->from_status}>{$e->to_status}")->all());
        $this->assertSame(['YEAR_END_RUN', 'REOPEN', 'YEAR_END_REVERSE'], $this->events());
        $this->assertSame([1, 1], [$this->audit('accounting.period.reopened'), $this->audit('accounting.fiscal_year.reopened')]);
        $this->assertSame(['-500000.0000', '200000.0000', '0.0000'], [$this->net('4100'), $this->net('6100'), $this->net('3200')]);
    }

    public function test_an_actor_who_may_not_post_into_a_soft_closed_period_gets_the_last_period_back_as_open(): void
    {
        $this->runYearEnd();
        $this->closeTheYear();
        $reverser = $this->memberToken($this->tenant, ['accounting.year_end.view', 'accounting.year_end.run', 'accounting.period.reopen']);

        $this->as($reverser)->postJson(self::CL.'/year-end/'.$this->year().'/reverse', ['reason' => self::REASON])->assertOk();

        $this->assertSame('OPEN', $this->periodStatus($this->tenant, '2026-12'));
        $this->assertSame('OPEN', DB::table('fiscal_years')->where('id', $this->year())->value('status'));
    }

    public function test_reversing_a_closed_year_without_the_reopen_permission_changes_nothing(): void
    {
        $this->runYearEnd();
        $this->closeTheYear();
        $reverser = $this->memberToken($this->tenant, ['accounting.year_end.view', 'accounting.year_end.run']);

        $this->as($reverser)->postJson(self::CL.'/year-end/'.$this->year().'/reverse', ['reason' => self::REASON])
            ->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED')->assertJsonPath('details.permission', 'accounting.period.reopen');

        $this->assertSame(['CLOSED', 'CLOSED', 'POSTED'], [$this->periodStatus($this->tenant, '2026-12'), DB::table('fiscal_years')->where('id', $this->year())->value('status'), DB::table('year_end_closings')->value('status')]);
        $this->assertSame(0, DB::table('journal_entries')->where('journal_type', 'REVERSAL')->count());
    }

    public function test_the_reopen_policy_applies_to_the_reversal_and_a_refusal_rolls_everything_back(): void
    {
        $this->putJson(self::CL.'/closing/policy', ['settings' => ['separate_closer_and_reopener' => true]])->assertOk();
        $this->runYearEnd();
        $this->closeTheYear(); // this person closed the last period

        $this->reverse()->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');

        $this->assertSame(['CLOSED', 'CLOSED', 'POSTED'], [$this->periodStatus($this->tenant, '2026-12'), DB::table('fiscal_years')->where('id', $this->year())->value('status'), DB::table('year_end_closings')->value('status')]);
        $this->assertSame(0, DB::table('journal_entries')->where('journal_type', 'REVERSAL')->count());
        $this->assertSame(0, DB::table('closing_events')->where('event_type', 'YEAR_END_REVERSE')->count());

        $this->as($this->memberToken($this->tenant))->postJson(self::CL.'/year-end/'.$this->year().'/reverse', ['reason' => self::REASON])->assertOk();
    }

    // ------------------------------------------------------------------------------------------ authorization

    public function test_viewing_and_running_are_separate_permissions(): void
    {
        $viewer = $this->memberToken($this->tenant, ['accounting.year_end.view']);
        $this->as($viewer);
        $this->getJson(self::CL.'/year-end')->assertOk();
        $this->getJson(self::CL.'/year-end/'.$this->year())->assertOk();
        $this->getJson(self::CL.'/year-end/'.$this->year().'/preview')->assertOk();
        $this->getJson(self::CL.'/year-end/rule-status')->assertOk();
        foreach (['run', 'reverse'] as $action) {
            $this->postJson(self::CL.'/year-end/'.$this->year()."/{$action}", ['reason' => self::REASON])->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        }
        $this->postJson(self::CL.'/year-end/rule-defaults', [])->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');

        $runner = $this->memberToken($this->tenant, ['accounting.year_end.run']);
        $this->as($runner)->getJson(self::CL.'/year-end/'.$this->year().'/preview')->assertStatus(403);
        $this->postJson(self::CL.'/year-end/rule-defaults', [])->assertStatus(403);
        $this->postJson(self::CL.'/year-end/'.$this->year().'/run')->assertCreated();
        $this->assertSame(1, DB::table('year_end_closings')->count());
    }

    public function test_a_read_only_module_keeps_the_reads_and_refuses_run_reverse_and_defaults(): void
    {
        $this->runYearEnd();
        $this->setModuleState($this->tenant, 'ACCOUNTING_REPORTING', 'READ_ONLY');
        $this->as($this->admin);

        $this->getJson(self::CL.'/year-end')->assertOk();
        $this->getJson(self::CL.'/year-end/'.$this->year().'/preview')->assertOk();
        $this->reverse()->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        $this->postJson(self::CL.'/year-end/rule-defaults', [])->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        $this->assertSame('POSTED', DB::table('year_end_closings')->value('status'));
    }

    public function test_the_service_itself_refuses_a_caller_the_route_would_have_let_through(): void
    {
        // The route gate and the service gate are the same decision: with the module switched off the service is never reached, and a person whose
        // data scope is narrower than the whole ledger is stopped by the service because a year-end over a part of the books would be wrong.
        $branch = $this->branches($this->tenant, 'BR1')[0];
        $scoped = $this->scopedToken($this->tenant, ['accounting.year_end.view', 'accounting.year_end.run', 'accounting.period.reopen'], 'BRANCH', $branch);
        $this->as($scoped);

        foreach (['', '/preview'] as $suffix) {
            $this->getJson(self::CL.'/year-end/'.$this->year().$suffix)->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        }
        $this->getJson(self::CL.'/year-end')->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        $this->postJson(self::CL.'/year-end/'.$this->year().'/run')->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        $this->postJson(self::CL.'/year-end/'.$this->year().'/reverse', ['reason' => self::REASON])->assertStatus(403)->assertJsonPath('code', 'CLOSING_REQUIRES_FULL_SCOPE');
        $this->getJson(self::CL.'/year-end/rule-status')->assertOk();
        $this->assertSame(0, DB::table('year_end_closings')->count());
    }

    public function test_another_tenants_fiscal_year_is_not_found_on_every_year_end_route(): void
    {
        $other = $this->yearEndTenant('bravo');
        $foreign = $this->fiscalYear($other, 'FY2026')->id;
        $this->runYearEnd();

        foreach ([['get', ''], ['get', '/preview'], ['post', '/run'], ['post', '/reverse']] as [$method, $suffix]) {
            $this->json($method, self::CL."/year-end/{$foreign}{$suffix}", ['reason' => self::REASON])->assertNotFound();
        }
        $this->assertSame(0, DB::table('year_end_closings')->where('tenant_id', $other->id)->count());
        $this->assertSame(0, DB::table('journal_entries')->where('tenant_id', $other->id)->where('source_type', 'YEAR_END_CLOSING')->count());

        $this->as($this->memberToken($other));
        $this->assertSame(['FY2026'], array_column($this->getJson(self::CL.'/year-end')->json('fiscal_years'), 'code'));
        $this->assertSame('NOT_RUN', $this->getJson(self::CL.'/year-end')->json('fiscal_years.0.year_end.status'));
    }
}
