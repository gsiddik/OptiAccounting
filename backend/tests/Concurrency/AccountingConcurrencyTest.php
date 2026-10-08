<?php

namespace Tests\Concurrency;

use App\Domain\Accounting\Services\PostingEngine;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\ConcurrencyTestCase;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\RaceRunner;

/**
 * OA1 release gate: the accounting invariants under genuinely concurrent requests. Workers are separate PHP processes
 * hitting the real application; where the order of events matters (close vs. posting, opening vs. posting) a second
 * connection holds a lock so the interleaving is deterministic instead of hoped for.
 *
 * What these tests prove, in the vocabulary of the brief: no double posting, no duplicate or missing journal number, no
 * duplicate reversal, no posting after the period closes, no posting slipping in before an opening balance's cutover date,
 * no partial posting, and no deadlock (a worker that ends in HTTP 500 fails the test).
 */
class AccountingConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, Fixtures;

    private const J = '/api/v1/app/accounting/journals';

    private const PERIODS = '/api/v1/app/accounting/periods';

    private const OPENING = '/api/v1/app/accounting/opening-balance';

    private const RULES = '/api/v1/app/accounting/posting-rules';

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->tenant, $this->token] = $this->tenantWithAdmin('alpha');
    }

    // ------------------------------------------------------------------------------------------ posting

    public function test_the_same_journal_posted_by_several_requests_at_once_is_posted_exactly_once(): void
    {
        $id = $this->draftId();

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::J."/{$id}/post")))->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 1, '409:JOURNAL_ALREADY_POSTED' => 5], $this->outcomes($results));
        $journal = DB::table('journal_entries')->where('id', $id)->first();
        $this->assertSame('POSTED', $journal->status);
        $this->assertSame('JV-FY2026-000001', $journal->journal_number);
        $this->assertSame(1, DB::table('journal_transitions')->where('journal_entry_id', $id)->where('to_status', 'POSTED')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.journal.posted')->count());
        $this->assertSame(2, DB::table('journal_lines')->where('journal_entry_id', $id)->count());
        $this->assertSame(1, (int) DB::table('document_sequences')->where('tenant_id', $this->tenant->id)->value('last_value'), 'one number was issued, not several');
    }

    public function test_parallel_postings_receive_unique_gapless_numbers(): void
    {
        $ids = array_map(fn () => $this->draftId(), range(1, 8));

        $results = (new RaceRunner)->start(array_map(fn ($id) => $this->job('POST', self::J."/{$id}/post"), $ids), delay: 3.5)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 8], $this->outcomes($results));
        $numbers = DB::table('journal_entries')->whereIn('id', $ids)->orderBy('journal_number')->pluck('journal_number')->all();
        $this->assertSame(array_map(fn ($n) => sprintf('JV-FY2026-%06d', $n), range(1, 8)), $numbers, 'every number issued exactly once, no gaps');
        $this->assertSame(8, (int) DB::table('document_sequences')->where('tenant_id', $this->tenant->id)->value('last_value'));
        $this->assertSame(8, DB::table('journal_entries')->where('status', 'POSTED')->count());
    }

    public function test_a_posting_that_fails_gives_its_number_back_so_the_sequence_stays_gapless(): void
    {
        $good = $this->draftId();
        $this->postOk($good);
        $bad = $this->draftId();
        DB::table('accounts')->where('tenant_id', $this->tenant->id)->where('code', '4100')->update(['status' => 'INACTIVE']); // makes the second posting fail after the number is drawn
        $other = $this->draftId(['lines' => $this->lines($this->tenant, '5', '1110', '4200')]);

        $results = (new RaceRunner)->start([$this->job('POST', self::J."/{$bad}/post"), $this->job('POST', self::J."/{$other}/post")])->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 1, '422:ACCOUNT_INACTIVE' => 1], $this->outcomes($results));
        $this->assertSame(['JV-FY2026-000001', 'JV-FY2026-000002'], DB::table('journal_entries')->where('status', 'POSTED')->orderBy('journal_number')->pluck('journal_number')->all());
        $this->assertSame(2, (int) DB::table('document_sequences')->where('tenant_id', $this->tenant->id)->value('last_value'));
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $bad)->value('status'));
        $this->assertNull(DB::table('journal_entries')->where('id', $bad)->value('journal_number'));
    }

    public function test_a_post_and_a_cancel_of_the_same_draft_leave_one_consistent_final_state(): void
    {
        $ids = array_map(fn () => $this->draftId(), range(1, 4));
        $jobs = [];
        foreach ($ids as $id) {
            $jobs[] = $this->job('POST', self::J."/{$id}/post");
            $jobs[] = $this->job('POST', self::J."/{$id}/cancel", ['reason' => 'Salah input']);
        }

        $results = (new RaceRunner)->start($jobs, delay: 3.0)->results();

        $this->assertNoServerErrors($results);
        foreach ($ids as $i => $id) {
            [$post, $cancel] = [$results[$i * 2], $results[$i * 2 + 1]];
            $this->assertSame(1, ($post['status'] === 200 ? 1 : 0) + ($cancel['status'] === 200 ? 1 : 0), 'exactly one of post and cancel wins');
            $journal = DB::table('journal_entries')->where('id', $id)->first();
            if ($post['status'] === 200) {
                $this->assertSame('POSTED', $journal->status);
                $this->assertNotNull($journal->journal_number);
            } else {
                $this->assertSame('CANCELLED', $journal->status);
                $this->assertNull($journal->journal_number, 'a cancelled journal never consumed a number');
            }
        }
        $posted = DB::table('journal_entries')->where('status', 'POSTED')->count();
        $this->assertSame($posted, (int) DB::table('document_sequences')->where('tenant_id', $this->tenant->id)->value('last_value') ?: 0);
    }

    public function test_the_same_business_fact_arriving_twice_at_once_posts_one_journal(): void
    {
        $this->publishExpenseRule();
        $fact = ['EXPENSE_RECOGNIZED', 'TEST_DOC', 'EXP-1', '2026-03-10', ['net' => '1000', 'tax' => '110', 'total' => '1110']];

        $results = (new RaceRunner)->start(array_fill(0, 5, ['mode' => 'event', 'tenant_id' => $this->tenant->id, 'event' => $fact]))->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 5], $this->outcomes($results));
        $this->assertCount(1, array_unique(array_column(array_column($results, 'body'), 'journal_entry_id')), 'every replay points at the same journal');
        $this->assertSame(1, DB::table('accounting_events')->where('source_id', 'EXP-1')->count());
        $this->assertSame(1, DB::table('journal_entries')->where('source_id', 'EXP-1')->count());
        $this->assertSame(1, (int) DB::table('document_sequences')->where('tenant_id', $this->tenant->id)->value('last_value'));
    }

    public function test_two_different_contents_for_one_business_fact_cannot_both_post(): void
    {
        $this->publishExpenseRule();
        $a = ['EXPENSE_RECOGNIZED', 'TEST_DOC', 'EXP-2', '2026-03-10', ['net' => '1000', 'tax' => '110', 'total' => '1110']];
        $b = ['EXPENSE_RECOGNIZED', 'TEST_DOC', 'EXP-2', '2026-03-10', ['net' => '2000', 'tax' => '220', 'total' => '2220']];

        $results = (new RaceRunner)->start(array_map(fn ($fact) => ['mode' => 'event', 'tenant_id' => $this->tenant->id, 'event' => $fact], [$a, $b, $a, $b]), delay: 3.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(1, DB::table('journal_entries')->where('source_id', 'EXP-2')->count(), 'one content won; the other is a conflict, not a second journal');
        $this->assertSame(1, DB::table('accounting_events')->where('source_id', 'EXP-2')->where('status', 'POSTED')->count());
        foreach ($results as $r) {
            $this->assertContains($r['status'], [200, 409]);
            if ($r['status'] === 409) {
                $this->assertSame('ACCOUNTING_EVENT_CONFLICT', $r['body']['code']);
            }
        }
    }

    // ------------------------------------------------------------------------------------------ reversal

    public function test_concurrent_reversals_of_one_journal_create_exactly_one_reversal(): void
    {
        $id = $this->draftId();
        $this->postOk($id);

        $results = (new RaceRunner)->start(array_map(fn ($n) => $this->job('POST', self::J."/{$id}/reverse", ['reason' => "Koreksi {$n}"]), range(1, 5)))->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['201:' => 1, '409:JOURNAL_ALREADY_REVERSED' => 4], $this->outcomes($results));
        $this->assertSame(1, DB::table('journal_entries')->where('reverses_journal_id', $id)->count());
        $reversal = DB::table('journal_entries')->where('reverses_journal_id', $id)->first();
        $this->assertSame($reversal->id, DB::table('journal_entries')->where('id', $id)->value('reversed_by_journal_id'));
        $this->assertSame('POSTED', $reversal->status);
        $net = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('e.status', 'POSTED')
            ->selectRaw('l.account_id, sum(l.debit) - sum(l.credit) as net')->groupBy('l.account_id')->pluck('net')->map(fn ($v) => (string) (float) $v)->unique()->all();
        $this->assertSame(['0'], $net, 'journal and its single reversal cancel out on every account');
    }

    // ------------------------------------------------------------------------------------------ period close vs posting

    public function test_a_close_waits_for_a_posting_that_already_holds_the_period_and_commits_after_it(): void
    {
        $this->postOk($this->draftId()); // makes the numbering row exist
        $id = $this->draftId();
        $period = $this->period($this->tenant, '2026-03')->id;

        // An in-flight posting is paused after it has taken the period lock: it needs a number, and we hold the numbering row.
        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec("select 1 from document_sequences where tenant_id = '{$this->tenant->id}' for update");

        $post = (new RaceRunner)->start([$this->job('POST', self::J."/{$id}/post")], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $post);
        $close = (new RaceRunner)->start([$this->job('POST', self::PERIODS."/{$period}/close")], delay: 0);
        $this->waitForLockWaiters($holder, 2);

        $this->assertTrue($post->running(), 'the posting is paused inside its transaction');
        $this->assertTrue($close->running(), 'the close waits for the posting instead of bypassing it');
        $this->assertSame('OPEN', DB::table('accounting_periods')->where('id', $period)->value('status'));
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));

        $holder->commit();
        [$posted] = $post->results();
        [$closed] = $close->results();

        $this->assertSame(200, $posted['status']);
        $this->assertSame(200, $closed['status']);
        $row = DB::table('accounting_periods')->where('id', $period)->first();
        $this->assertSame('CLOSED', $row->status);
        $this->assertSame('POSTED', DB::table('journal_entries')->where('id', $id)->value('status'));
        $this->assertLessThanOrEqual(strtotime($row->closed_at), strtotime(DB::table('journal_entries')->where('id', $id)->value('posted_at')), 'the posting committed before the close');
    }

    public function test_a_posting_that_starts_while_a_close_is_in_flight_is_refused_after_the_close_commits(): void
    {
        $id = $this->draftId();
        $period = $this->period($this->tenant, '2026-03')->id;

        $holder = $this->rawConnection(); // a close that has updated the period but not yet committed
        $holder->beginTransaction();
        $holder->exec("update accounting_periods set status = 'CLOSED', closed_at = now() where id = '{$period}'");

        $post = (new RaceRunner)->start([$this->job('POST', self::J."/{$id}/post")], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $post);
        $this->assertTrue($post->running(), 'the posting waits for the closing transaction');
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));

        $holder->commit();
        [$result] = $post->results();

        $this->assertSame(422, $result['status']);
        $this->assertSame('PERIOD_CLOSED', $result['body']['code']);
        $journal = DB::table('journal_entries')->where('id', $id)->first();
        $this->assertSame('DRAFT', $journal->status);
        $this->assertNull($journal->journal_number);
        $this->assertSame(0, DB::table('document_sequences')->where('tenant_id', $this->tenant->id)->count(), 'no number was consumed by the refused posting');
        $this->assertSame(0, DB::table('journal_transitions')->where('journal_entry_id', $id)->where('to_status', 'POSTED')->count());
    }

    public function test_closing_a_period_while_many_postings_race_it_never_lets_a_posting_in_afterwards(): void
    {
        $ids = array_map(fn () => $this->draftId(), range(1, 6));
        $period = $this->period($this->tenant, '2026-03')->id;
        $jobs = array_map(fn ($id) => $this->job('POST', self::J."/{$id}/post"), $ids);
        array_splice($jobs, 3, 0, [$this->job('POST', self::PERIODS."/{$period}/close")]); // the close sits in the middle of the pack

        $results = (new RaceRunner)->start($jobs, delay: 3.5)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(200, $results[3]['status'], 'the close succeeds: nothing was pending');
        $posts = array_values(array_filter($results, fn ($r, $i) => $i !== 3, ARRAY_FILTER_USE_BOTH));
        foreach ($posts as $r) {
            $this->assertContains($r['status'].':'.($r['body']['code'] ?? ''), ['200:', '422:PERIOD_CLOSED']);
        }
        $row = DB::table('accounting_periods')->where('id', $period)->first();
        $this->assertSame('CLOSED', $row->status);
        $won = count(array_filter($posts, fn ($r) => $r['status'] === 200));
        $this->assertSame($won, DB::table('journal_entries')->whereIn('id', $ids)->where('status', 'POSTED')->count(), 'every success is in the ledger, every refusal left a draft');
        $this->assertSame(0, DB::table('journal_entries')->whereIn('id', $ids)->where('status', 'POSTED')->where('posted_at', '>', $row->closed_at)->count(), 'nothing was posted after the close');
        $this->assertSame(6 - $won, DB::table('journal_entries')->whereIn('id', $ids)->where('status', 'DRAFT')->whereNull('journal_number')->count());
        $this->assertSame($won, (int) (DB::table('document_sequences')->where('tenant_id', $this->tenant->id)->value('last_value') ?? 0), 'refused postings consumed no number');
    }

    public function test_two_closes_of_the_same_period_succeed_once(): void
    {
        $period = $this->period($this->tenant, '2026-03')->id;

        $results = (new RaceRunner)->start([$this->job('POST', self::PERIODS."/{$period}/close"), $this->job('POST', self::PERIODS."/{$period}/close")])->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:2026-03' => 1, '409:PERIOD_INVALID_TRANSITION' => 1], $this->outcomes($results));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.period.status_changed')->where('changes->after->status', 'CLOSED')->count());
    }

    // ------------------------------------------------------------------------------------------ opening balance vs posting

    public function test_the_opening_balance_waits_for_postings_in_flight_and_postings_wait_for_the_opening_balance(): void
    {
        $this->saveOpeningDraft('2026-03-01');
        $gate = PostingEngine::ledgerGate($this->tenant->id);

        // A posting in flight holds the ledger gate shared: the opening balance (exclusive) must queue behind it.
        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec("select pg_advisory_xact_lock_shared(hashtextextended('{$gate}', 0))");
        $opening = (new RaceRunner)->start([$this->job('POST', self::OPENING.'/post')], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $opening);
        $this->assertTrue($opening->running(), 'the opening balance waits for the in-flight posting');
        $this->assertSame('DRAFT', DB::table('opening_balances')->value('status'));
        $holder->commit();
        [$posted] = $opening->results();
        $this->assertSame(200, $posted['status']);

        // The opening balance in flight holds the gate exclusively: a normal posting must queue behind it.
        $id = $this->draftId(['posting_date' => '2026-03-10', 'document_date' => '2026-03-10']);
        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec("select pg_advisory_xact_lock(hashtextextended('{$gate}', 0))");
        $post = (new RaceRunner)->start([$this->job('POST', self::J."/{$id}/post")], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $post);
        $this->assertTrue($post->running(), 'a posting waits while the opening balance holds the ledger');
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));
        $holder->commit();
        [$result] = $post->results();
        $this->assertSame(200, $result['status']);
    }

    public function test_an_opening_balance_and_an_earlier_dated_posting_racing_never_both_succeed(): void
    {
        $rounds = [];
        for ($r = 1; $r <= 4; $r++) {
            [$tenant, $token] = $this->tenantWithAdmin("race{$r}");
            $draft = $this->as($token)->postJson(self::J, $this->journalBody($tenant, ['posting_date' => '2026-02-10', 'document_date' => '2026-02-10']))->assertCreated()->json('id');
            $this->as($token)->putJson(self::OPENING, $this->openingBody($tenant, '2026-03-01'))->assertOk();
            $rounds[] = [$tenant, $token, $draft];
        }
        $jobs = [];
        foreach ($rounds as [$tenant, $token, $draft]) {
            $jobs[] = $this->job('POST', self::OPENING.'/post', null, $token);
            $jobs[] = $this->job('POST', self::J."/{$draft}/post", null, $token);
        }

        $results = (new RaceRunner)->start($jobs, delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        foreach ($rounds as $i => [$tenant]) {
            [$opening, $post] = [$results[$i * 2], $results[$i * 2 + 1]];
            $this->assertSame(1, ($opening['status'] === 200 ? 1 : 0) + ($post['status'] === 200 ? 1 : 0), "round {$i}: exactly one of them wins");
            if ($opening['status'] === 200) {
                $this->assertSame('POSTING_BEFORE_CUTOVER', $post['body']['code']);
            } else {
                $this->assertSame('OPENING_BALANCE_HISTORY_EXISTS', $opening['body']['code']);
            }
            $earlier = DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('status', 'POSTED')->where('journal_type', '!=', 'OPENING')->where('posting_date', '<', '2026-03-01')->count();
            $openingPosted = DB::table('opening_balances')->where('tenant_id', $tenant->id)->where('status', 'POSTED')->count();
            $this->assertSame(0, $earlier * $openingPosted, "round {$i}: no posting dated before the cutover coexists with a posted opening balance");
        }
    }

    // ------------------------------------------------------------------------------------------ helpers

    /** @return array{0: Tenant, 1: string} a ready tenant and a token of a member holding every permission */
    private function tenantWithAdmin(string $code): array
    {
        $tenant = $this->accountingTenant($code, profile: ['approval_required' => false]);
        [$user] = $this->member($tenant);

        return [$tenant, $this->tenantToken($user, $tenant)];
    }

    private function job(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        return ['method' => $method, 'uri' => $uri, 'body' => $body, 'token' => $token ?? $this->token];
    }

    private function draftId(array $override = []): string
    {
        return $this->as($this->token)->postJson(self::J, $this->journalBody($this->tenant, $override))->assertCreated()->json('id');
    }

    private function postOk(string $id): void
    {
        $this->as($this->token)->postJson(self::J."/{$id}/post")->assertOk();
    }

    private function openingBody(Tenant $tenant, string $cutover): array
    {
        return ['cutover_date' => $cutover, 'reference' => 'SALDO-AWAL', 'lines' => [
            ['account_id' => $this->account($tenant, '1110')->id, 'debit' => '500000'],
            ['account_id' => $this->account($tenant, '3100')->id, 'credit' => '500000'],
        ]];
    }

    private function saveOpeningDraft(string $cutover): void
    {
        $this->as($this->token)->putJson(self::OPENING, $this->openingBody($this->tenant, $cutover))->assertOk();
    }

    private function publishExpenseRule(): void
    {
        $client = $this->as($this->token);
        $rule = $client->postJson(self::RULES, ['code' => 'EXP', 'event_type' => 'EXPENSE_RECOGNIZED', 'name' => 'Beban diakui', 'lines' => [
            ['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'net'],
            ['side' => 'DEBIT', 'account_role' => 'TAX_RECEIVABLE', 'amount_key' => 'tax'],
            ['side' => 'CREDIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'total'],
        ]])->assertCreated()->json();
        $client->postJson(self::RULES."/{$rule['id']}/publish", ['effective_from' => '2026-01-01'])->assertOk();
    }

    /** @param  list<array{status:int,body:array<string,mixed>|null}>  $results */
    private function assertNoServerErrors(array $results): void
    {
        foreach ($results as $i => $r) {
            $this->assertLessThan(500, $r['status'], "worker {$i} failed with a server error (deadlock or crash?): ".json_encode($r['body']));
        }
    }

    /** @return array<string,int> "status:code" => count, sorted */
    private function outcomes(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $key = $r['status'].':'.($r['body']['code'] ?? '');
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /** Block until $count database sessions are waiting on a lock (instead of sleeping and hoping the worker got there). */
    private function waitForLockWaiters(PDO $watcher, int $count, float $timeout = 20.0, ?RaceRunner $diagnose = null): void
    {
        $deadline = microtime(true) + $timeout;
        do {
            $watcher->query('select pg_stat_clear_snapshot()'); // pg_stat_activity is cached for the rest of a transaction, and the holder is inside one
            $waiting = (int) $watcher->query("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()")->fetchColumn();
            if ($waiting >= $count) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $detail = $diagnose !== null ? ' Workers: '.$diagnose->output() : '';
        $watcher->query('select pg_stat_clear_snapshot()');
        $sessions = $watcher->query('select pid, state, wait_event_type, wait_event, left(query, 90) as query from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid()')->fetchAll(PDO::FETCH_ASSOC);
        $this->fail("Expected {$count} session(s) waiting on a lock, saw {$waiting}.{$detail} Sessions: ".json_encode($sessions));
    }
}
