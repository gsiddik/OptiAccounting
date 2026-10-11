<?php

namespace Tests\Feature\Closing;

use App\Domain\Accounting\Models\PostingRule;
use App\Domain\Accounting\Services\PostingRuleService;
use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA5 batch K: the year-end closing posts one journal through the Posting Engine and moves nothing else (spec 71, 79, 81). */
class YearEndClosingTest extends TestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->yearEndTenant();
        $this->admin = $this->memberToken($this->tenant);
        $this->as($this->admin);
        $this->fakeReconciliations();
    }

    private function year(string $code = 'FY2026'): string
    {
        return $this->fiscalYear($this->tenant, $code)->id;
    }

    private function url(string $action = '', string $code = 'FY2026'): string
    {
        return self::CL.'/year-end/'.$this->year($code).($action === '' ? '' : "/{$action}");
    }

    /** A posted cash sale to a revenue account (or any pair of accounts). */
    private function revenue(string $amount, string $date = '2026-03-10', string $account = '4100', array $dimensions = []): void
    {
        $this->postedJournal($this->tenant, ['document_date' => $date, 'posting_date' => $date, 'lines' => $this->lines($this->tenant, $amount, '1110', $account, $dimensions)]);
    }

    private function expense(string $amount, string $date = '2026-04-10', string $account = '6100', array $dimensions = []): void
    {
        $this->postedJournal($this->tenant, ['document_date' => $date, 'posting_date' => $date, 'lines' => $this->lines($this->tenant, $amount, $account, '1110', $dimensions)]);
    }

    /** The posted net (debit - credit) of an account, optionally for one branch, as a four-decimal string. */
    private function net(string $code, ?string $branchId = null, ?string $upTo = null): string
    {
        return Money::str((string) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.tenant_id', $this->tenant->id)->where('e.status', 'POSTED')->where('a.code', $code)
            ->when($branchId, fn ($q) => $q->where('l.branch_id', $branchId))->when($upTo, fn ($q) => $q->where('e.posting_date', '<=', $upTo))
            ->selectRaw('coalesce(sum(l.debit - l.credit), 0) as n')->value('n'));
    }

    /** @return array<string,string> the net of every account that has one */
    private function nets(): array
    {
        return DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.tenant_id', $this->tenant->id)->where('e.status', 'POSTED')->groupBy('a.code')->orderBy('a.code')
            ->selectRaw('a.code, sum(l.debit - l.credit) as n')->pluck('n', 'code')->map(fn ($n) => Money::str((string) $n))->all();
    }

    private function runYearEnd(?string $reason = null, string $code = 'FY2026'): TestResponse
    {
        return $this->postJson($this->url('run', $code), $reason === null ? [] : ['reason' => $reason]);
    }

    private function closingJournal(int $runNo = 1, string $code = 'FY2026'): object
    {
        return DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('source_type', 'YEAR_END_CLOSING')->where('source_id', $this->year($code).":{$runNo}")->first();
    }

    // ------------------------------------------------------------------------------------------ rule defaults

    public function test_the_rule_defaults_publish_the_year_end_rule_once_and_never_overwrite_it(): void
    {
        $tenant = $this->closingTenant('bravo');
        $this->as($this->memberToken($tenant));

        $status = $this->getJson(self::CL.'/year-end/rule-status')->assertOk()->json();
        $this->assertSame([false], array_column($status['events'], 'ready'));
        $this->assertSame(['mapped' => true, 'problem' => null], ['mapped' => $status['retained_earnings']['mapped'], 'problem' => $status['retained_earnings']['problem']]);
        $this->assertSame('3200', $status['retained_earnings']['account']['code'], 'the account comes from the tenant mapping');

        $this->postJson(self::CL.'/year-end/rule-defaults', [])->assertCreated()->assertJsonPath('created', ['YEAR_END_CLOSING'])->assertJsonPath('skipped', []);
        $this->postJson(self::CL.'/year-end/rule-defaults', [])->assertCreated()->assertJsonPath('created', [])->assertJsonPath('skipped.0.reason', 'ALREADY_PUBLISHED');
        $this->getJson(self::CL.'/year-end/rule-status')->assertJsonPath('events.0.ready', true)->assertJsonPath('events.0.effective_from', '2026-01-01');

        $rule = DB::table('posting_rules')->where('tenant_id', $tenant->id)->where('event_type', 'YEAR_END_CLOSING')->first();
        $lines = DB::table('posting_rule_lines')->where('posting_rule_id', $rule->id)->orderBy('line_number')->get(['side', 'account_role', 'amount_key', 'skip_if_zero'])->map(fn ($l) => [$l->side, $l->account_role, $l->amount_key, (bool) $l->skip_if_zero])->all();
        $this->assertSame([['DEBIT', 'DOCUMENT_ACCOUNT', 'debit_close', true], ['CREDIT', 'DOCUMENT_ACCOUNT', 'credit_close', true], ['CREDIT', 'RETAINED_EARNINGS', 'profit', true], ['DEBIT', 'RETAINED_EARNINGS', 'loss', true]], $lines);
        $this->assertSame(1, DB::table('posting_rules')->where('tenant_id', $tenant->id)->where('event_type', 'YEAR_END_CLOSING')->count());
    }

    public function test_without_a_retained_earnings_mapping_the_defaults_report_it_and_nothing_is_half_created(): void
    {
        $tenant = $this->closingTenant('bravo');
        $this->as($this->memberToken($tenant));
        DB::table('account_mappings')->where('tenant_id', $tenant->id)->where('account_role', 'RETAINED_EARNINGS')->delete();

        $status = $this->getJson(self::CL.'/year-end/rule-status')->assertOk()->json();
        $this->assertSame([false, null, 'ACCOUNT_MAPPING_MISSING', ['RETAINED_EARNINGS']], [$status['retained_earnings']['mapped'], $status['retained_earnings']['account'], $status['retained_earnings']['problem'], $status['unmapped_roles']]);

        $this->postJson(self::CL.'/year-end/rule-defaults', [])->assertCreated()->assertJsonPath('created', [])->assertJsonPath('skipped.0.event_type', 'YEAR_END_CLOSING');
        $this->assertSame(0, DB::table('posting_rules')->where('tenant_id', $tenant->id)->where('event_type', 'YEAR_END_CLOSING')->count());
    }

    // ------------------------------------------------------------------------------------------ preview

    public function test_the_preview_shows_what_would_be_closed_and_writes_nothing(): void
    {
        $this->revenue('500000');
        $this->revenue('50000', account: '4200');
        $this->expense('200000');
        $before = [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('accounting_events')->count(), DB::table('closing_events')->count(), DB::table('audit_logs')->count()];

        $preview = $this->getJson($this->url('preview'))->assertOk()->json();

        $this->assertSame(['FY2026', '2026-12-31', '2026-12', 'OPEN', true, []], [$preview['fiscal_year']['code'], $preview['closing_date'], $preview['last_period']['code'], $preview['last_period']['status'], $preview['can_run'], $preview['blockers']]);
        $this->assertSame(['3200', 'Laba Ditahan'], [$preview['retained_earnings_account']['code'], $preview['retained_earnings_account']['name']]);
        $this->assertSame(['YEAR-END-CLOSING', 1], [$preview['posting_rule']['code'], $preview['posting_rule']['version']]);
        $this->assertSame(['550000.0000', '200000.0000', '350000.0000', '0.0000', '350000.0000', 'PROFIT'], array_values(array_intersect_key($preview['totals'], array_flip(['revenue', 'expense', 'profit', 'loss', 'net_result', 'result']))));
        $this->assertSame([true, '550000.0000', '550000.0000', 4], [$preview['journal']['balanced'], $preview['journal']['total_debit'], $preview['journal']['total_credit'], $preview['journal']['line_count']]);

        $accounts = array_column($preview['accounts'], null, 'code');
        $this->assertSame(['4100', '4200', '6100'], array_map('strval', array_keys($accounts)));
        $this->assertSame(['-500000.0000', 'DEBIT', '500000.0000'], [$accounts['4100']['balance'], $accounts['4100']['closes_with'], $accounts['4100']['amount']]);
        $this->assertSame(['200000.0000', 'CREDIT'], [$accounts['6100']['balance'], $accounts['6100']['closes_with']]);
        $this->assertCount(1, $preview['groups']);
        $this->assertSame(['550000.0000', '200000.0000', '350000.0000'], [$preview['groups'][0]['revenue'], $preview['groups'][0]['expense'], $preview['groups'][0]['result']]);

        $this->assertSame($before, [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('accounting_events')->count(), DB::table('closing_events')->count(), DB::table('audit_logs')->count()]);
    }

    public function test_the_preview_lists_every_blocker_and_a_run_is_refused_with_the_first_one_and_all_of_them(): void
    {
        $tenant = $this->closingTenant('bravo'); // no rule, and nothing to close yet
        $this->as($this->memberToken($tenant));
        DB::table('account_mappings')->where('tenant_id', $tenant->id)->where('account_role', 'RETAINED_EARNINGS')->delete();
        $year = $this->fiscalYear($tenant, 'FY2026')->id;

        $preview = $this->getJson(self::CL."/year-end/{$year}/preview")->assertOk()->json();

        $this->assertFalse($preview['can_run']);
        $this->assertSame(['YEAR_END_MAPPING_MISSING', 'YEAR_END_RULE_MISSING', 'YEAR_END_NOTHING_TO_CLOSE'], array_column($preview['blockers'], 'code'));
        $refused = $this->postJson(self::CL."/year-end/{$year}/run")->assertStatus(409)->assertJsonPath('code', 'YEAR_END_MAPPING_MISSING');
        $this->assertSame(['YEAR_END_MAPPING_MISSING', 'YEAR_END_RULE_MISSING', 'YEAR_END_NOTHING_TO_CLOSE'], array_column($refused->json('details.blockers'), 'code'));
        $this->assertSame(0, DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('source_type', 'YEAR_END_CLOSING')->count());
    }

    // ------------------------------------------------------------------------------------------ run

    public function test_a_profit_year_is_closed_into_retained_earnings_through_the_posting_engine(): void
    {
        $this->revenue('500000');
        $this->expense('200000');
        $before = $this->nets();
        $lineCount = DB::table('journal_lines')->count();

        $run = $this->runYearEnd('Penutupan tahun buku 2026')->assertCreated()->json();

        $journal = $this->closingJournal();
        $this->assertNotNull($journal);
        $this->assertSame(['SYSTEM', 'POSTED', 'YEAR_END_CLOSING', '2026-12-31', $journal->id], [$journal->journal_type, $journal->status, $journal->source_type, substr($journal->posting_date, 0, 10), $run['journal_entry_id']]);
        $this->assertSame($this->year().':1', $journal->source_id);
        $this->assertSame([1, 'POSTED', '300000.0000', 2, '3200'], [$run['run_no'], $run['status'], $run['net_result'], $run['closed_account_count'], $run['retained_earnings_account']['code']]);
        $this->assertSame($journal->journal_number, $run['journal_number']);

        // The posting went through the engine: an event of the catalog, the published rule, a snapshot of what was posted.
        $event = DB::table('accounting_events')->where('source_type', 'YEAR_END_CLOSING')->where('source_id', $journal->source_id)->first();
        $this->assertSame(['YEAR_END_CLOSING', 'POSTED', $journal->id], [$event->event_type, $event->status, $event->journal_entry_id]);
        $snapshot = json_decode($journal->posting_snapshot, true);
        $this->assertSame(['YEAR_END_CLOSING', 'YEAR-END-CLOSING'], [$snapshot['event_type'], $snapshot['rule']['code']]);

        // One balanced journal that closes the temporary accounts into the mapped account, nothing else.
        $this->assertSame([['3200', '0.0000', '300000.0000'], ['4100', '500000.0000', '0.0000'], ['6100', '0.0000', '200000.0000']], $this->closingLines($journal->id));
        $this->assertSame([$journal->total_debit, $journal->total_credit], ['500000.0000', '500000.0000']);
        $this->assertSame($lineCount + 3, DB::table('journal_lines')->count(), 'exactly the lines of the one closing journal were added');
        $after = $this->nets();
        $this->assertSame(['0.0000', '0.0000'], [$after['4100'], $after['6100']]);
        $this->assertSame('-300000.0000', $after['3200']);
        $this->assertSame($before['1110'], $after['1110'], 'balance sheet accounts are untouched');

        // Run record, history, audit.
        $record = DB::table('year_end_closings')->where('fiscal_year_id', $this->year())->first();
        $this->assertSame([1, 'POSTED', $journal->id, $this->account($this->tenant, '3200')->id, 2], [$record->run_no, $record->status, $record->journal_entry_id, $record->retained_earnings_account_id, $record->closed_account_count]);
        $event = DB::table('closing_events')->where('event_type', 'YEAR_END_RUN')->first();
        $this->assertSame([$this->year(), null, $journal->id, 'Penutupan tahun buku 2026', $journal->journal_number], [$event->fiscal_year_id, $event->accounting_period_id, $event->journal_entry_id, $event->reason, $event->reference]);
        $audit = json_decode(DB::table('audit_logs')->where('action', 'accounting.year_end.run')->value('changes'), true)['after'];
        $this->assertSame(['FY2026', 1, $journal->journal_number, '3200', 'PROFIT', '300000.0000'], [$audit['code'], $audit['run_no'], $audit['journal_number'], $audit['retained_earnings_account'], $audit['result'], $audit['net_result']]);
    }

    public function test_a_loss_year_debits_retained_earnings(): void
    {
        $this->revenue('100000');
        $this->expense('350000');

        $run = $this->runYearEnd()->assertCreated()->json();

        $this->assertSame(['-250000.0000'], [$run['net_result']]);
        $this->assertSame([['3200', '250000.0000', '0.0000'], ['4100', '100000.0000', '0.0000'], ['6100', '0.0000', '350000.0000']], $this->closingLines($this->closingJournal()->id));
        $this->assertSame(['0.0000', '0.0000', '250000.0000'], [$this->net('4100'), $this->net('6100'), $this->net('3200')]);
        $this->getJson($this->url('preview'))->assertJsonPath('blockers.0.code', 'YEAR_END_ALREADY_POSTED')->assertJsonPath('totals.result', 'BREAK_EVEN');
    }

    public function test_a_contra_account_is_closed_against_its_own_side(): void
    {
        $this->revenue('500000');
        $this->postedJournal($this->tenant, ['document_date' => '2026-05-10', 'posting_date' => '2026-05-10', 'lines' => $this->lines($this->tenant, '30000', '4100', '1110')]); // a sales return: debit revenue
        $this->postedJournal($this->tenant, ['document_date' => '2026-06-10', 'posting_date' => '2026-06-10', 'lines' => $this->lines($this->tenant, '5000', '1110', '6100')]); // a refund: credit expense

        $preview = $this->getJson($this->url('preview'))->assertOk()->json();
        $accounts = array_column($preview['accounts'], null, 'code');
        $this->assertSame(['470000.0000', 'DEBIT', '-5000.0000', 'DEBIT', '475000.0000'], [$accounts['4100']['amount'], $accounts['4100']['closes_with'], $accounts['6100']['balance'], $accounts['6100']['closes_with'], $preview['totals']['net_result']]);

        $this->runYearEnd()->assertCreated();

        $this->assertSame([['3200', '0.0000', '475000.0000'], ['4100', '470000.0000', '0.0000'], ['6100', '5000.0000', '0.0000']], $this->closingLines($this->closingJournal()->id));
        $this->assertSame(['0.0000', '0.0000'], [$this->net('4100'), $this->net('6100')]);
    }

    public function test_a_second_run_for_the_same_year_is_refused_and_the_database_backs_it_up(): void
    {
        $this->revenue('500000');
        $this->runYearEnd()->assertCreated();

        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_ALREADY_POSTED');
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->count());
        $this->assertSame(1, DB::table('year_end_closings')->count());

        // Even code that skipped the check cannot add a second POSTED run for the year.
        $record = (array) DB::table('year_end_closings')->first();
        $other = DB::table('journal_entries')->where('id', '<>', $record['journal_entry_id'])->value('id');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('year_end_closings_one_posted');
        DB::table('year_end_closings')->insert(['id' => (string) Str::uuid(), 'run_no' => 2, 'journal_entry_id' => $other] + $record);
    }

    public function test_the_closing_journal_is_immutable_like_any_posted_journal(): void
    {
        $this->revenue('500000');
        $this->runYearEnd()->assertCreated();
        $journal = $this->closingJournal();
        $line = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->first();

        foreach ([
            fn () => DB::table('journal_entries')->where('id', $journal->id)->update(['description' => 'diubah']),
            fn () => DB::table('journal_entries')->where('id', $journal->id)->delete(),
            fn () => DB::table('journal_lines')->where('id', $line->id)->update(['debit' => '1.0000', 'credit' => '0.0000']),
            fn () => DB::table('journal_lines')->where('id', $line->id)->delete(),
            fn () => DB::table('year_end_closings')->where('journal_entry_id', $journal->id)->delete(),
            fn () => DB::table('year_end_closings')->where('journal_entry_id', $journal->id)->update(['closing_date' => '2026-11-30']),
        ] as $i => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("attempt {$i} changed the closing");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0], "attempt {$i}");
            }
        }
    }

    public function test_the_retained_earnings_account_is_whatever_the_tenant_mapped_and_nothing_in_code(): void
    {
        $this->revenue('500000');
        $capital = $this->account($this->tenant, '3100'); // any posting account: the mapping, not its code or name, decides
        DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'RETAINED_EARNINGS')->update(['account_id' => $capital->id]);

        $this->getJson($this->url('preview'))->assertJsonPath('retained_earnings_account.code', '3100');
        $run = $this->runYearEnd()->assertCreated()->json();

        $this->assertSame([['3100', '0.0000', '500000.0000'], ['4100', '500000.0000', '0.0000']], $this->closingLines($this->closingJournal()->id));
        $this->assertSame([$capital->id, '3100'], [DB::table('year_end_closings')->value('retained_earnings_account_id'), $run['retained_earnings_account']['code']]);
        $this->assertSame('0.0000', $this->net('3200'), 'the default retained earnings account was not used');
    }

    public function test_an_unmapped_role_and_an_unpublished_rule_each_stop_the_run_with_their_own_code(): void
    {
        $this->revenue('500000');
        DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'RETAINED_EARNINGS')->update(['status' => 'INACTIVE']);
        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_MAPPING_MISSING')->assertJsonPath('details.reason', 'ACCOUNT_MAPPING_MISSING');
        DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'RETAINED_EARNINGS')->update(['status' => 'ACTIVE']);

        DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->where('event_type', 'YEAR_END_CLOSING')->update(['effective_from' => '2027-01-01']);
        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_RULE_MISSING');

        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->count());
    }

    public function test_a_rule_that_does_not_produce_a_balanced_journal_is_caught_before_posting(): void
    {
        $this->revenue('500000');
        $this->inTenant($this->tenant, function () { // a new version of the rule that forgets the profit line, so the closing cannot balance
            $rules = app(PostingRuleService::class);
            $draft = $rules->newVersion(PostingRule::query()->where('event_type', 'YEAR_END_CLOSING')->firstOrFail());
            $rules->update($draft, ['lines' => [
                ['side' => 'DEBIT', 'account_role' => 'DOCUMENT_ACCOUNT', 'amount_key' => 'debit_close'], ['side' => 'CREDIT', 'account_role' => 'DOCUMENT_ACCOUNT', 'amount_key' => 'credit_close'],
                ['side' => 'DEBIT', 'account_role' => 'RETAINED_EARNINGS', 'amount_key' => 'loss'],
            ]]);
            $rules->publish($draft, '2026-06-01');
        });

        $preview = $this->getJson($this->url('preview'))->assertOk()->json();
        $this->assertSame([false, 'YEAR_END_RULE_INVALID'], [$preview['journal']['balanced'], $preview['blockers'][0]['code']]);
        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_RULE_INVALID');
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->count());
    }

    // ------------------------------------------------------------------------------------------ preconditions

    public function test_a_pending_journal_in_the_year_stops_the_run(): void
    {
        $this->revenue('500000');
        $pending = $this->pendingJournal($this->tenant, '2026-12-15');

        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_PENDING_JOURNALS')->assertJsonPath('details.pending', 1);

        $this->postJson(self::CL."/journals/{$pending}/post")->assertOk();
        $this->runYearEnd()->assertCreated();
    }

    public function test_a_future_period_or_a_year_that_is_not_open_stops_the_run(): void
    {
        $this->revenue('500000');
        $this->forcePeriodStatus($this->tenant, '2026-11', 'FUTURE');
        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_FUTURE_PERIODS')->assertJsonPath('details.periods.0', '2026-11');
        $this->forcePeriodStatus($this->tenant, '2026-11', 'OPEN');

        DB::table('fiscal_years')->where('id', $this->year())->update(['status' => 'DRAFT']);
        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'FISCAL_YEAR_NOT_OPEN');
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->count());
    }

    public function test_a_closed_last_period_stops_the_run_but_a_closed_earlier_period_does_not(): void
    {
        $this->revenue('500000');
        $this->hardClose($this->tenant, '2026-03'); // the revenue period is closed already: the entries go to the last period
        $this->hardClose($this->tenant, '2026-12');

        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_LAST_PERIOD_CLOSED')->assertJsonPath('details.period', '2026-12');
        $this->postJson(self::CL.'/closing/periods/'.$this->period($this->tenant, '2026-12')->id.'/reopen', ['reason' => 'Dibuka untuk penutupan tahun', 'target' => 'OPEN'])->assertOk();

        $this->runYearEnd()->assertCreated();
        $this->assertSame('2026-12', DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->join('accounting_periods as p', 'p.id', '=', 'journal_entries.accounting_period_id')->value('p.code'));
    }

    public function test_a_soft_closed_last_period_takes_the_closing_as_an_audited_exceptional_posting(): void
    {
        $this->revenue('500000');
        $this->postJson(self::CL.'/closing/periods/'.$this->period($this->tenant, '2026-12')->id.'/soft-close')->assertOk();
        $runner = $this->memberToken($this->tenant, ['accounting.year_end.view', 'accounting.year_end.run']); // not allowed to post into soft-closed periods otherwise

        $this->as($runner)->postJson($this->url('run'))->assertCreated();

        $log = DB::table('audit_logs')->where('action', 'accounting.journal.posted_in_soft_closed_period')->first();
        $this->assertSame($this->closingJournal()->id, $log->resource_id);
        $this->assertSame('2026-12', json_decode($log->changes, true)['after']['period']);
        $this->assertNotNull($log->actor_user_id);
        $this->assertSame('SOFT_CLOSED', $this->periodStatus($this->tenant, '2026-12'));
    }

    public function test_an_inactive_account_that_still_has_a_balance_is_named_instead_of_failing_halfway(): void
    {
        $this->revenue('500000');
        DB::table('accounts')->where('tenant_id', $this->tenant->id)->where('code', '4100')->update(['status' => 'INACTIVE']);

        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_ACCOUNT_INACTIVE')->assertJsonPath('details.accounts', ['4100']);
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->count());
    }

    // ------------------------------------------------------------------------------------------ dimensions

    public function test_the_result_is_closed_per_branch_and_business_unit_so_dimension_reports_stay_intact(): void
    {
        [$jkt, $sby] = $this->branches($this->tenant, 'JKT', 'SBY');
        $unit = $this->inTenant($this->tenant, fn () => app(OrganizationService::class)->createBusinessUnit($this->tenant->id, ['code' => 'RETAIL', 'name' => 'Ritel'], $jkt))->id;
        $this->revenue('300000', dimensions: ['branch_id' => $jkt, 'business_unit_id' => $unit]);
        $this->revenue('100000', dimensions: ['branch_id' => $sby]);
        $this->expense('60000', dimensions: ['branch_id' => $jkt, 'business_unit_id' => $unit]);
        $this->expense('250000', dimensions: ['branch_id' => $sby]);
        $this->expense('10000'); // no dimension at all

        $preview = $this->getJson($this->url('preview'))->assertOk()->json();
        $groups = collect($preview['groups'])->keyBy(fn ($g) => ($g['branch_code'] ?? '-').'/'.($g['business_unit_code'] ?? '-'));
        $this->assertEqualsCanonicalizing(['JKT/RETAIL', 'SBY/-', '-/-'], $groups->keys()->all());
        $this->assertSame(['240000.0000', '-150000.0000', '-10000.0000'], [$groups['JKT/RETAIL']['result'], $groups['SBY/-']['result'], $groups['-/-']['result']]);
        $this->assertSame(['240000.0000', '160000.0000', '80000.0000', 'PROFIT'], [$preview['totals']['profit'], $preview['totals']['loss'], $preview['totals']['net_result'], $preview['totals']['result']]);

        $this->runYearEnd()->assertCreated();

        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $this->closingJournal()->id)
            ->get(['a.code', 'l.debit', 'l.credit', 'l.branch_id', 'l.business_unit_id'])
            ->map(fn ($l) => implode('|', [$l->code, $l->debit, $l->credit, $l->branch_id === null ? '-' : ($l->branch_id === $jkt ? 'JKT' : 'SBY'), $l->business_unit_id === null ? '-' : 'RETAIL']))->all();
        $this->assertEqualsCanonicalizing([
            '3200|0.0000|240000.0000|JKT|RETAIL', '3200|10000.0000|0.0000|-|-', '3200|150000.0000|0.0000|SBY|-',
            '4100|100000.0000|0.0000|SBY|-', '4100|300000.0000|0.0000|JKT|RETAIL',
            '6100|0.0000|10000.0000|-|-', '6100|0.0000|250000.0000|SBY|-', '6100|0.0000|60000.0000|JKT|RETAIL',
        ], $lines);

        // Every dimension view reads zero for the temporary accounts and carries the result where it was earned.
        foreach ([$jkt, $sby] as $branch) {
            $this->assertSame('0.0000', $this->net('4100', $branch));
            $this->assertSame('0.0000', $this->net('6100', $branch));
        }
        $this->assertSame(['-240000.0000', '150000.0000'], [$this->net('3200', $jkt), $this->net('3200', $sby)]);
    }

    // ------------------------------------------------------------------------------------------ the next year

    public function test_the_next_fiscal_year_starts_with_zero_temporary_accounts_and_carries_the_rest(): void
    {
        $this->revenue('500000');
        $this->expense('200000');
        $this->runYearEnd()->assertCreated();
        foreach (range(1, 12) as $n) {
            $this->hardClose($this->tenant, sprintf('2026-%02d', $n));
        }
        $this->postJson(self::CL.'/fiscal-years/'.$this->year().'/close')->assertOk();
        $next = $this->postJson(self::CL.'/fiscal-years', ['code' => 'FY2027', 'name' => 'Tahun 2027', 'start_date' => '2027-01-01'])->assertCreated()->json('id');
        $this->postJson(self::CL."/fiscal-years/{$next}/open")->assertOk();
        $this->revenue('80000', '2027-02-10');

        $tb = $this->getJson(self::CL.'/trial-balance?from=2027-01-01&to=2027-03-31&include_zero=1')->assertOk()->json();
        $rows = array_column($tb['data'], null, 'code');

        $this->assertSame(['0.0000', '0.0000'], [$rows['4100']['opening_debit'], $rows['4100']['opening_credit']], 'revenue starts the year at zero');
        $this->assertSame(['0.0000', '0.0000'], [$rows['6100']['opening_debit'], $rows['6100']['opening_credit']], 'expense starts the year at zero');
        $this->assertSame(['0.0000', '300000.0000'], [$rows['3200']['opening_debit'], $rows['3200']['opening_credit']], 'the result is in retained earnings');
        $this->assertSame('300000.0000', $rows['1110']['opening_debit'], 'balance sheet accounts carry on');
        $this->assertSame('80000.0000', $rows['4100']['credit']);
        $this->assertTrue($tb['reconciliation']['reconciled']);

        // The second year closes only what the second year earned.
        $preview = $this->getJson(self::CL."/year-end/{$next}/preview")->assertOk()->json();
        $this->assertSame(['80000.0000', '0.0000', '80000.0000'], [$preview['totals']['revenue'], $preview['totals']['expense'], $preview['totals']['net_result']]);
    }

    public function test_a_later_year_end_sweeps_an_earlier_year_that_was_never_closed_and_the_earlier_one_can_no_longer_run(): void
    {
        $this->revenue('500000');
        $next = $this->postJson(self::CL.'/fiscal-years', ['code' => 'FY2027', 'name' => 'Tahun 2027', 'start_date' => '2027-01-01'])->assertCreated()->json('id');
        $this->postJson(self::CL."/fiscal-years/{$next}/open")->assertOk();
        $this->expense('100000', '2027-03-10');

        $this->postJson(self::CL."/year-end/{$next}/run")->assertCreated()->assertJsonPath('net_result', '400000.0000');

        $this->assertSame(['0.0000', '0.0000', '-400000.0000'], [$this->net('4100'), $this->net('6100'), $this->net('3200')]);
        $this->runYearEnd()->assertStatus(409)->assertJsonPath('code', 'YEAR_END_LATER_YEAR_POSTED')->assertJsonPath('details.blockers.0.details.fiscal_year', 'FY2027');
    }

    // ------------------------------------------------------------------------------------------ listing

    public function test_the_listing_shows_every_year_with_its_status_and_whether_a_run_is_possible(): void
    {
        $this->revenue('500000');
        $this->postJson(self::CL.'/fiscal-years', ['code' => 'FY2027', 'name' => 'Tahun 2027', 'start_date' => '2027-01-01'])->assertCreated();

        $years = array_column($this->getJson(self::CL.'/year-end')->assertOk()->json('fiscal_years'), null, 'code');
        $this->assertSame(['OPEN', 'NOT_RUN', true, []], [$years['FY2026']['status'], $years['FY2026']['year_end']['status'], $years['FY2026']['can_run'], $years['FY2026']['blockers']]);
        $this->assertSame(['DRAFT', false], [$years['FY2027']['status'], $years['FY2027']['can_run']]);
        $this->assertContains('FISCAL_YEAR_NOT_OPEN', $years['FY2027']['blockers']);

        $this->runYearEnd()->assertCreated();
        $year = $this->getJson($this->url())->assertOk()->json();
        $this->assertSame(['POSTED', 1, false, ['YEAR_END_ALREADY_POSTED']], [$year['year_end']['status'], $year['year_end']['run_no'], $year['can_run'], $year['blockers']]);
        $this->assertCount(1, $year['runs']);
        $this->assertSame($this->closingJournal()->journal_number, $year['year_end']['journal_number']);
        $this->assertSame('POSTED', array_column($this->getJson(self::CL.'/closing/overview')->json('fiscal_years'), 'year_end', 'code')['FY2026']['status']);
    }
}
