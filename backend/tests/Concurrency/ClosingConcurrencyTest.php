<?php

namespace Tests\Concurrency;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\AccountingFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\RaceHelpers;
use Tests\Support\RaceRunner;

/**
 * OA5 release gate for the closing: hard close, reopen and year end against postings and against each other, with genuinely concurrent
 * requests. Where the order matters, a second connection pauses one side inside its transaction (a row lock on the numbering row for a
 * posting, an ACCESS EXCLUSIVE lock on a table the closing reads once it holds its locks) so the interleaving is deterministic.
 *
 * What these tests prove: a posting either commits before a close and is part of it, or is refused after it; no posting slips into a CLOSED
 * period; two closes of one period and two year-end runs of one year succeed once; the ledger stays balanced; and no deadlock (a worker that
 * ends in HTTP 500 fails the test), including against the source documents that lock their period before the ledger gate.
 */
class ClosingConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, PayablesFixtures, RaceHelpers;

    private const J = '/api/v1/app/accounting/journals';

    private const C = '/api/v1/app/accounting/closing/periods';

    private const YE = '/api/v1/app/accounting/year-end';

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->yearEndTenant('alpha', ['approval_required' => false]);
        $this->token = $this->memberToken($this->tenant);
        $this->as($this->token);
    }

    private function draftId(string $date = '2026-03-10', string $amount = '100000', string $credit = '4100'): string
    {
        return $this->as($this->token)->postJson(self::J, $this->journalBody($this->tenant, [
            'document_date' => $date, 'posting_date' => $date, 'lines' => $this->lines($this->tenant, $amount, '1110', $credit),
        ]))->assertCreated()->json('id');
    }

    /** Post one journal so the numbering row exists: the holder connection pauses a posting by locking it. */
    private function primeNumbering(): void
    {
        $this->as($this->token)->postJson(self::J.'/'.$this->draftId('2026-01-10').'/post')->assertOk();
    }

    private function url(string $month, string $action): string
    {
        return self::C.'/'.$this->period($this->tenant, $month)->id."/{$action}";
    }

    private function net(string $code): string
    {
        return (string) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.tenant_id', $this->tenant->id)->where('e.status', 'POSTED')->where('a.code', $code)->selectRaw('coalesce(sum(l.debit - l.credit), 0)::numeric(20,4) as n')->value('n');
    }

    private function assertLedgerBalanced(): void
    {
        $sum = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('e.tenant_id', $this->tenant->id)->where('e.status', 'POSTED')
            ->selectRaw('coalesce(sum(l.debit), 0)::numeric(20,4) as d, coalesce(sum(l.credit), 0)::numeric(20,4) as c')->first();
        $this->assertSame($sum->d, $sum->c, 'the ledger balances: total debit equals total credit');
    }

    // ------------------------------------------------------------------------------------------ hard close vs posting

    public function test_a_hard_close_waits_for_a_posting_that_is_in_flight_and_the_posting_commits_first(): void
    {
        $this->primeNumbering();
        $id = $this->draftId();
        $period = $this->period($this->tenant, '2026-03')->id;

        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec("select 1 from document_sequences where tenant_id = '{$this->tenant->id}' for update"); // the posting pauses holding the shared ledger gate and the period lock

        $post = (new RaceRunner)->start([$this->job('POST', self::J."/{$id}/post")], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $post);
        $close = (new RaceRunner)->start([$this->job('POST', self::C."/{$period}/hard-close")], delay: 0);
        $this->waitForLockWaiters($holder, 2);

        $this->assertTrue($post->running(), 'the posting is paused inside its transaction');
        $this->assertTrue($close->running(), 'the close waits for the posting instead of bypassing it');
        $this->assertSame('OPEN', DB::table('accounting_periods')->where('id', $period)->value('status'));

        $holder->commit();
        [$posted] = $post->results();
        [$closed] = $close->results();

        $this->assertSame([200, 200], [$posted['status'], $closed['status']]);
        $this->assertSame('CLOSED', DB::table('accounting_periods')->where('id', $period)->value('status'));
        $this->assertSame('POSTED', DB::table('journal_entries')->where('id', $id)->value('status'));
        $evidence = json_decode(DB::table('closing_events')->where('event_type', 'HARD_CLOSE')->value('readiness_checks'), true);
        $this->assertSame('PASS', collect($evidence)->firstWhere('code', 'TRIAL_BALANCE')['result'], 'the readiness was evaluated after the posting had committed');
        $this->assertLedgerBalanced();
    }

    public function test_a_posting_that_starts_while_a_hard_close_is_in_flight_is_refused_after_it_commits(): void
    {
        $id = $this->draftId();
        $period = $this->period($this->tenant, '2026-03')->id;

        // The close holds the ledger gate and the period row, then stalls where it reads the checklist templates.
        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec('lock table closing_checklist_templates in access exclusive mode');
        $close = (new RaceRunner)->start([$this->job('POST', self::C."/{$period}/hard-close")], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $close);
        $post = (new RaceRunner)->start([$this->job('POST', self::J."/{$id}/post")], delay: 0);
        $this->waitForLockWaiters($holder, 2, diagnose: $post);

        $this->assertTrue($close->running() && $post->running(), 'the posting waits behind the close');
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));

        $holder->commit();
        [$closed] = $close->results();
        [$result] = $post->results();

        $this->assertSame(200, $closed['status']);
        $this->assertSame([422, 'PERIOD_CLOSED'], [$result['status'], $result['body']['code']]);
        $journal = DB::table('journal_entries')->where('id', $id)->first();
        $this->assertSame('DRAFT', $journal->status);
        $this->assertNull($journal->journal_number);
        $this->assertSame(0, DB::table('document_sequences')->where('tenant_id', $this->tenant->id)->count(), 'the refused posting consumed no number');
        $this->assertSame('CLOSED', DB::table('accounting_periods')->where('id', $this->period($this->tenant, '2026-03')->id)->value('status'));
    }

    public function test_closing_a_period_while_many_postings_race_it_never_lets_a_posting_in_afterwards(): void
    {
        $ids = array_map(fn () => $this->draftId(), range(1, 6));
        $period = $this->period($this->tenant, '2026-03')->id;
        $jobs = array_map(fn ($id) => $this->job('POST', self::J."/{$id}/post"), $ids);
        array_splice($jobs, 3, 0, [$this->job('POST', self::C."/{$period}/hard-close")]); // the close sits in the middle of the pack

        $results = (new RaceRunner)->start($jobs, delay: 3.5)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(200, $results[3]['status'], 'the close succeeds: no draft stands in its way');
        $posts = array_values(array_filter($results, fn ($r, $i) => $i !== 3, ARRAY_FILTER_USE_BOTH));
        foreach ($posts as $r) {
            $this->assertContains($r['status'].':'.($r['body']['code'] ?? ''), ['200:', '422:PERIOD_CLOSED']);
        }
        $won = count(array_filter($posts, fn ($r) => $r['status'] === 200));
        $this->assertSame('CLOSED', DB::table('accounting_periods')->where('id', $period)->value('status'));
        $this->assertSame($won, DB::table('journal_entries')->whereIn('id', $ids)->where('status', 'POSTED')->count(), 'every success is in the ledger, every refusal left a draft');
        $this->assertSame(6 - $won, DB::table('journal_entries')->whereIn('id', $ids)->where('status', 'DRAFT')->whereNull('journal_number')->count());
        $this->assertSame(1, DB::table('closing_events')->where('event_type', 'HARD_CLOSE')->count());
        $this->assertLedgerBalanced();
    }

    public function test_source_documents_that_lock_their_period_first_cannot_deadlock_with_a_hard_close(): void
    {
        $vendor = $this->vendor($this->tenant);
        $ids = [];
        foreach (range(1, 6) as $i) {
            $id = $this->as($this->token)->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => "RACE-{$i}"]))->assertCreated()->json('id');
            $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
            $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();
            $ids[] = $id;
        }
        $jobs = array_map(fn ($id) => $this->job('POST', self::AP."/ap-invoices/{$id}/post"), $ids);
        array_splice($jobs, 2, 0, [$this->job('POST', $this->url('2026-03', 'hard-close'))]);

        $results = (new RaceRunner)->start($jobs, delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(200, $results[2]['status'], 'the close wins or waits its turn, it is never starved or deadlocked: '.json_encode($results[2]['body']));
        $posts = array_values(array_filter($results, fn ($r, $i) => $i !== 2, ARRAY_FILTER_USE_BOTH));
        foreach ($posts as $r) {
            $this->assertContains($r['status'].':'.($r['body']['code'] ?? ''), ['200:', '422:PERIOD_CLOSED'], json_encode($r['body']));
        }
        $row = DB::table('accounting_periods')->where('id', $this->period($this->tenant, '2026-03')->id)->first();
        $this->assertSame('CLOSED', $row->status);
        $posted = DB::table('ap_invoices')->whereIn('id', $ids)->where('status', 'POSTED')->count();
        $this->assertSame(count(array_filter($posts, fn ($r) => $r['status'] === 200)), $posted);
        $this->assertSame(0, DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('status', 'POSTED')->where('posted_at', '>', $row->closed_at)->whereBetween('posting_date', ['2026-03-01', '2026-03-31'])->count(), 'nothing was posted into March after the close');
        $this->assertLedgerBalanced();
    }

    public function test_two_simultaneous_hard_closes_of_the_same_period_succeed_once(): void
    {
        $period = $this->period($this->tenant, '2026-03')->id;

        $results = (new RaceRunner)->start(array_fill(0, 3, $this->job('POST', self::C."/{$period}/hard-close")))->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:2026-03' => 1, '409:PERIOD_INVALID_TRANSITION' => 2], $this->outcomes($results));
        $this->assertSame(1, DB::table('closing_events')->where('event_type', 'HARD_CLOSE')->count(), 'one history row for one close');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.period.hard_closed')->count());
    }

    // ------------------------------------------------------------------------------------------ reopen vs posting

    private function reopenWhilePostingWaits(string $target): array
    {
        $id = $this->draftId();
        $period = $this->period($this->tenant, '2026-03')->id;
        $this->as($this->token)->postJson(self::C."/{$period}/hard-close")->assertOk();

        // The reopen holds the ledger gate and the period row, then stalls where it reads the closing policy.
        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec('lock table closing_settings in access exclusive mode');
        $reopen = (new RaceRunner)->start([$this->job('POST', self::C."/{$period}/reopen", ['reason' => 'Koreksi akrual yang terlewat', 'target' => $target])], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $reopen);
        $poster = $this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.journal.post']); // an ordinary user: no right to post into a soft-closed period
        $post = (new RaceRunner)->start([$this->job('POST', self::J."/{$id}/post", null, $poster)], delay: 0);
        $this->waitForLockWaiters($holder, 2, diagnose: $post);
        $this->assertSame('CLOSED', DB::table('accounting_periods')->where('id', $period)->value('status'));
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));

        $holder->commit();
        [$reopened] = $reopen->results();
        [$result] = $post->results();

        return [$reopened, $result, $id, $period];
    }

    public function test_a_posting_that_waited_behind_a_reopen_to_open_is_accepted_once_the_period_is_open(): void
    {
        [$reopened, $result, $id, $period] = $this->reopenWhilePostingWaits('OPEN');

        $this->assertSame([200, 200], [$reopened['status'], $result['status']]);
        $this->assertSame('OPEN', DB::table('accounting_periods')->where('id', $period)->value('status'));
        $this->assertSame('POSTED', DB::table('journal_entries')->where('id', $id)->value('status'));
        $this->assertSame(['HARD_CLOSE', 'REOPEN'], DB::table('closing_events')->orderBy('created_at')->pluck('event_type')->all());
        $this->assertLedgerBalanced();
    }

    public function test_a_posting_that_waited_behind_a_reopen_to_soft_closed_is_refused_for_an_ordinary_user(): void
    {
        [$reopened, $result, $id, $period] = $this->reopenWhilePostingWaits('SOFT_CLOSED');

        $this->assertSame(200, $reopened['status']);
        $this->assertSame([422, 'PERIOD_SOFT_CLOSED'], [$result['status'], $result['body']['code']]);
        $this->assertSame('SOFT_CLOSED', DB::table('accounting_periods')->where('id', $period)->value('status'));
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));
    }

    // ------------------------------------------------------------------------------------------ year end

    private function revenue(string $amount, string $date = '2026-03-10'): void
    {
        $this->postedJournal($this->tenant, ['document_date' => $date, 'posting_date' => $date, 'lines' => $this->lines($this->tenant, $amount, '1110', '4100')]);
    }

    /** @return array{debit:string,credit:string} the closing journal's lines on a revenue account and on retained earnings */
    private function closingFigures(): array
    {
        $journal = DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('source_type', 'YEAR_END_CLOSING')->value('id');
        $on = fn (string $code, string $column) => (string) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journal)->where('a.code', $code)->sum("l.{$column}");

        return ['debit' => $on('4100', 'debit'), 'credit' => $on('3200', 'credit')];
    }

    public function test_two_simultaneous_year_end_runs_post_exactly_one_closing_journal(): void
    {
        $this->revenue('500000');
        $fy = $this->fiscalYear($this->tenant, 'FY2026')->id;

        $results = (new RaceRunner)->start(array_fill(0, 4, $this->job('POST', self::YE."/{$fy}/run")), delay: 3.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['201:' => 1, '409:YEAR_END_ALREADY_POSTED' => 3], $this->outcomes($results));
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->count());
        $this->assertSame([1, 1, 1], [DB::table('year_end_closings')->where('status', 'POSTED')->count(), DB::table('closing_events')->where('event_type', 'YEAR_END_RUN')->count(), DB::table('accounting_events')->where('event_type', 'YEAR_END_CLOSING')->where('status', 'POSTED')->count()]);
        $this->assertSame('0.0000', $this->net('4100'));
        $this->assertSame('-500000.0000', $this->net('3200'));
        $this->assertLedgerBalanced();
    }

    public function test_a_posting_that_was_in_flight_when_the_year_end_started_is_part_of_the_closing(): void
    {
        $this->primeNumbering(); // a revenue of 100000 in January
        $this->revenue('500000');
        $id = $this->draftId('2026-06-10', '200000');
        $fy = $this->fiscalYear($this->tenant, 'FY2026')->id;

        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec("select 1 from document_sequences where tenant_id = '{$this->tenant->id}' for update");
        $post = (new RaceRunner)->start([$this->job('POST', self::J."/{$id}/post")], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $post);
        $run = (new RaceRunner)->start([$this->job('POST', self::YE."/{$fy}/run")], delay: 0);
        $this->waitForLockWaiters($holder, 2);
        $this->assertTrue($post->running() && $run->running(), 'the year end waits for the posting that already holds the ledger gate');

        $holder->commit();
        [$posted] = $post->results();
        [$ran] = $run->results();

        $this->assertSame([200, 201], [$posted['status'], $ran['status']]);
        $this->assertSame(['800000.0000', '800000.0000'], array_values($this->closingFigures()), 'the closing covers the posting that committed before it: 100000 + 500000 + 200000');
        $this->assertSame('0.0000', $this->net('4100'));
        $this->assertLedgerBalanced();
    }

    public function test_a_posting_that_starts_while_the_year_end_is_in_flight_lands_after_it_and_is_not_part_of_it(): void
    {
        $this->revenue('500000');
        $id = $this->draftId('2026-06-10', '200000');
        $fy = $this->fiscalYear($this->tenant, 'FY2026')->id;

        // The run holds the ledger gate, the last period and the fiscal year, has read the balances, and stalls on the run table.
        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec('lock table year_end_closings in access exclusive mode');
        $run = (new RaceRunner)->start([$this->job('POST', self::YE."/{$fy}/run")], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $run);
        $post = (new RaceRunner)->start([$this->job('POST', self::J."/{$id}/post")], delay: 0);
        $this->waitForLockWaiters($holder, 2, diagnose: $post);
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));

        $holder->commit();
        [$ran] = $run->results();
        [$posted] = $post->results();

        $this->assertSame([201, 200], [$ran['status'], $posted['status']]);
        $this->assertSame(['500000.0000', '500000.0000'], array_values($this->closingFigures()), 'the later posting is not in the closing');
        $this->assertSame('-200000.0000', $this->net('4100'), 'it stands on the revenue account the closing had emptied: a reversal and a new run would sweep it');
        $this->assertLedgerBalanced();
    }

    public function test_a_year_end_run_racing_the_hard_close_of_its_last_period_ends_consistently(): void
    {
        $this->revenue('500000');
        $fy = $this->fiscalYear($this->tenant, 'FY2026')->id;

        $results = (new RaceRunner)->start([$this->job('POST', self::YE."/{$fy}/run"), $this->job('POST', $this->url('2026-12', 'hard-close'))], delay: 3.0)->results();

        $this->assertNoServerErrors($results);
        [$run, $close] = $results;
        $this->assertSame(200, $close['status'], 'the close is never refused by the run');
        $this->assertContains($run['status'].':'.($run['body']['code'] ?? ''), ['201:', '409:YEAR_END_LAST_PERIOD_CLOSED'], 'either the run came first, or it found the period closed: '.json_encode($run['body']));
        $this->assertSame('CLOSED', DB::table('accounting_periods')->where('id', $this->period($this->tenant, '2026-12')->id)->value('status'));
        $closings = DB::table('journal_entries')->where('source_type', 'YEAR_END_CLOSING')->count();
        $this->assertSame($run['status'] === 201 ? 1 : 0, $closings);
        $this->assertSame($run['status'] === 201 ? '0.0000' : '-500000.0000', $this->net('4100'));
        $this->assertLedgerBalanced();
    }
}
