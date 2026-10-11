<?php

namespace Tests\Concurrency;

use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\RaceHelpers;
use Tests\Support\RaceRunner;
use Tests\Support\ReportingFixtures;

/**
 * OA5 release gate for the reports (spec 70): statements and the trial balance computed while postings commit, with genuinely concurrent
 * requests. Each report is one consistent snapshot of committed data: it never shows a half-posted journal, never mixes two snapshots (the
 * balance sheet balances, the cash flow reconciles, the trial balance has equal totals, the profit and loss equals its mapping), and it neither
 * waits for a posting that is in flight nor shows it. A soft close racing postings of an ordinary user lets each posting in before it or refuses it after.
 */
class Oa5ReportConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, RaceHelpers, ReportingFixtures;

    private const J = '/api/v1/app/accounting/journals';

    private const RANGE = 'from=2026-01-01&to=2026-03-31';

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reportingTenant('alpha', ['approval_required' => false]);
        $this->standardLedger($this->tenant);
        $this->publishedDefinitions($this->tenant);
        $this->token = $this->memberToken($this->tenant);
        $this->as($this->token);
    }

    /** Draft revenue journals of 1,000 x 2^i (cash against sales): any set of committed ones adds up to a number that names exactly which they are. @return list<string> */
    private function drafts(int $count): array
    {
        $ids = [];
        foreach (range(0, $count - 1) as $i) {
            $amount = (string) (1000 * (2 ** $i));
            $ids[] = $this->as($this->token)->postJson(self::J, $this->journalBody($this->tenant, [
                'document_date' => '2026-03-15', 'posting_date' => '2026-03-15', 'description' => "Penjualan {$i}", 'lines' => $this->lines($this->tenant, $amount),
            ]))->assertCreated()->json('id');
        }

        return $ids;
    }

    /** @return array<string,string> the figures every report must move together */
    private function figures(?array $pl = null, ?array $bs = null, ?array $cf = null, ?array $tb = null): array
    {
        return array_filter([
            'pl' => $pl === null ? null : $pl['integrity']['ledger_result'], 'bs_assets' => $bs === null ? null : $bs['integrity']['assets'], 'bs_result' => $bs === null ? null : $bs['integrity']['current_result'],
            'cf_result' => $cf === null ? null : $cf['integrity']['net_result'], 'cf_cash' => $cf === null ? null : $cf['integrity']['cash_closing_ledger'], 'tb' => $tb === null ? null : $tb['totals']['debit'],
        ], fn ($v) => $v !== null);
    }

    private function delta(string $now, string $base): string
    {
        return (string) BigDecimal::of($now)->minus($base)->toScale(0);
    }

    /** @return array<string,array<string,mixed>> the five reports of the period as they stand */
    private function reports(): array
    {
        return [
            'pl' => $this->statement('profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31']), 'bs' => $this->statement('balance-sheet', ['as_of' => '2026-03-31']),
            'cf' => $this->statement('cash-flow', ['from' => '2026-01-01', 'to' => '2026-03-31']),
            'tb' => $this->getJson(self::RP.'/trial-balance?'.self::RANGE)->assertOk()->json(),
        ];
    }

    public function test_every_report_computed_while_postings_commit_is_one_consistent_snapshot(): void
    {
        $base = $this->reports();
        $baseline = $this->figures($base['pl'], $base['bs'], $base['cf'], $base['tb']);
        $ids = $this->drafts(10);
        $uris = [
            'pl' => self::RP.'/reports/profit-loss?'.self::RANGE, 'bs' => self::RP.'/reports/balance-sheet?as_of=2026-03-31', 'cf' => self::RP.'/reports/cash-flow?'.self::RANGE,
            'tb' => self::RP.'/trial-balance?'.self::RANGE, 'ab' => self::RP.'/reports/account-balance?as_of=2026-03-31',
        ];

        $jobs = [];
        $kinds = [];
        foreach ($ids as $n => $id) {
            foreach ([array_keys($uris)[$n % 5], array_keys($uris)[($n + 2) % 5]] as $kind) { // two reports around every posting
                $jobs[] = $this->job('GET', $uris[$kind]);
                $kinds[] = $kind;
            }
            $jobs[] = $this->job('POST', self::J."/{$id}/post");
            $kinds[] = 'post';
        }

        $results = (new RaceRunner)->start($jobs, delay: 4.5)->results();

        $this->assertNoServerErrors($results);
        $seen = [];
        foreach ($results as $i => $r) {
            $this->assertSame(200, $r['status'], "{$kinds[$i]}: ".json_encode($r['body']));
            $body = $r['body'];
            switch ($kinds[$i]) {
                case 'pl':
                    $this->assertSame(['0.0000', true], [$body['integrity']['difference'], $body['integrity']['reliable']], 'the profit and loss equals its own mapping');
                    $seen[] = $this->delta($body['integrity']['ledger_result'], $baseline['pl']);
                    break;
                case 'bs':
                    $this->assertSame(['0.0000', true], [$body['integrity']['difference'], $body['integrity']['balanced']], 'the balance sheet balances');
                    $this->assertSame($this->delta($body['integrity']['assets'], $baseline['bs_assets']), $this->delta($body['integrity']['current_result'], $baseline['bs_result']), 'assets and result moved together');
                    $seen[] = $this->delta($body['integrity']['assets'], $baseline['bs_assets']);
                    break;
                case 'cf':
                    $this->assertSame(['0.0000', true], [$body['integrity']['difference'], $body['integrity']['reconciled']], 'the cash flow reconciles with the cash accounts');
                    $this->assertSame($this->delta($body['integrity']['net_result'], $baseline['cf_result']), $this->delta($body['integrity']['cash_closing_ledger'], $baseline['cf_cash']), 'result and cash moved together');
                    $seen[] = $this->delta($body['integrity']['net_result'], $baseline['cf_result']);
                    break;
                case 'tb':
                    $this->assertSame($body['totals']['debit'], $body['totals']['credit'], 'total debit equals total credit');
                    $this->assertTrue($body['reconciliation']['reconciled']);
                    $seen[] = $this->delta($body['totals']['debit'], $baseline['tb']);
                    break;
                case 'ab':
                    $this->assertSame('0.0000', $body['totals']['closing_net_debit_minus_credit'], 'all accounts together net to zero');
                    break;
                default:
                    $this->assertContains($r['status'], [200]);
            }
        }
        foreach ($seen as $delta) {
            $this->assertSame('0', (string) ((int) $delta % 1000), "a report showed a part of a journal: {$delta}");
            $this->assertLessThanOrEqual(1023000, (int) $delta);
            $this->assertGreaterThanOrEqual(0, (int) $delta);
        }

        // After the race every report agrees with the ledger: all ten journals, 1,023,000 more.
        $this->assertSame(10, DB::table('journal_entries')->whereIn('id', $ids)->where('status', 'POSTED')->count());
        $final = $this->reports();
        $now = $this->figures($final['pl'], $final['bs'], $final['cf'], $final['tb']);
        foreach ($baseline as $key => $value) {
            $this->assertSame('1023000', $this->delta($now[$key], $value), $key);
        }
    }

    public function test_a_report_neither_waits_for_nor_shows_a_posting_that_is_in_flight(): void
    {
        $id = $this->drafts(1)[0];
        $this->as($this->token)->postJson(self::J.'/'.$this->drafts(1)[0].'/post')->assertOk(); // the numbering row exists
        $base = $this->reports();
        $baseline = $this->figures($base['pl'], $base['bs'], $base['cf'], $base['tb']);

        $holder = $this->rawConnection();
        $holder->beginTransaction();
        $holder->exec("select 1 from document_sequences where tenant_id = '{$this->tenant->id}' for update"); // the posting pauses inside its transaction
        $post = (new RaceRunner)->start([$this->job('POST', self::J."/{$id}/post")], delay: 0);
        $this->waitForLockWaiters($holder, 1, diagnose: $post);

        $during = $this->reports(); // answered at once, while the posting is paused
        $this->assertTrue($post->running(), 'the posting is still in flight');
        $this->assertSame($baseline, $this->figures($during['pl'], $during['bs'], $during['cf'], $during['tb']), 'an uncommitted posting is invisible');
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));

        $holder->commit();
        [$posted] = $post->results();
        $this->assertSame(200, $posted['status']);
        $after = $this->reports();
        $this->assertSame('1000', $this->delta($after['bs']['integrity']['assets'], $baseline['bs_assets']));
        $this->assertSame('1000', $this->delta($after['pl']['integrity']['ledger_result'], $baseline['pl']));
    }

    public function test_a_soft_close_racing_postings_of_an_ordinary_user_lets_each_in_before_it_or_refuses_it_after(): void
    {
        $ids = $this->drafts(6);
        $period = $this->period($this->tenant, '2026-03')->id;
        $poster = $this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.journal.post']);
        $jobs = array_map(fn ($id) => $this->job('POST', self::J."/{$id}/post", null, $poster), $ids);
        array_splice($jobs, 3, 0, [$this->job('POST', self::RP."/closing/periods/{$period}/soft-close", ['reason' => 'Mulai penutupan Maret'], $this->token)]);

        $results = (new RaceRunner)->start($jobs, delay: 3.5)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(200, $results[3]['status'], 'the soft close is never starved or deadlocked: '.json_encode($results[3]['body']));
        $posts = array_values(array_filter($results, fn ($r, $i) => $i !== 3, ARRAY_FILTER_USE_BOTH));
        foreach ($posts as $r) {
            $this->assertContains($r['status'].':'.($r['body']['code'] ?? ''), ['200:', '422:PERIOD_SOFT_CLOSED'], json_encode($r['body']));
        }
        $won = count(array_filter($posts, fn ($r) => $r['status'] === 200));
        $this->assertSame('SOFT_CLOSED', DB::table('accounting_periods')->where('id', $period)->value('status'));
        $this->assertSame($won, DB::table('journal_entries')->whereIn('id', $ids)->where('status', 'POSTED')->count(), 'every success is in the ledger, every refusal left a draft');
        $closedAt = DB::table('closing_events')->where('event_type', 'SOFT_CLOSE')->value('created_at');
        $this->assertSame(0, DB::table('journal_entries')->whereIn('id', $ids)->where('status', 'POSTED')->where('posted_at', '>', $closedAt)->count(), 'nothing of the ordinary user was posted after the soft close');
        $sum = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('e.tenant_id', $this->tenant->id)->where('e.status', 'POSTED')
            ->selectRaw('coalesce(sum(l.debit), 0)::numeric(20,4) as d, coalesce(sum(l.credit), 0)::numeric(20,4) as c')->first();
        $this->assertSame($sum->d, $sum->c, 'the ledger stays balanced');
    }
}
