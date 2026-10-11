<?php

namespace Tests\Concurrency;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\RaceHelpers;
use Tests\Support\RaceRunner;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\Support\TaxFixtures;

/**
 * OA5 release gate: reconciliation against concurrent postings and against itself. Workers are separate PHP processes hitting the real
 * application. Invariants: a reconciliation computed while documents post never shows a torn (half-posted) state, two runs of one
 * (type, cut-off) leave exactly one live exception, and an exception is resolved exactly once however many people ask.
 */
class ReconciliationConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, PayablesFixtures, RaceHelpers, ReceivablesFixtures, ReconciliationFixtures, TaxFixtures;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reconTenant();
        [$user] = $this->member($this->tenant);
        $this->token = $this->tenantToken($user, $this->tenant);
        $this->as($this->token);
    }

    private function http()
    {
        return $this->as($this->token);
    }

    /** An approved (not yet posted) vendor invoice. */
    private function approved($vendor): string
    {
        $id = $this->http()->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();

        return $id;
    }

    public function test_a_reconciliation_computed_while_documents_post_never_sees_a_half_posted_state(): void
    {
        $vendor = $this->vendor($this->tenant);
        $posted = 0;
        for ($round = 0; $round < 3; $round++) {
            $ids = [$this->approved($vendor), $this->approved($vendor), $this->approved($vendor)];
            $jobs = array_map(fn ($id) => $this->job('POST', self::AP."/ap-invoices/{$id}/post"), $ids);
            foreach (range(1, 6) as $i) {
                $jobs[] = $this->job('GET', self::RC.($i % 2 ? '/reconciliations/ap-gl?as_of=2026-03-31' : '/reconciliations?as_of=2026-03-31'));
            }

            $results = (new RaceRunner)->start($jobs)->results();

            $this->assertNoServerErrors($results);
            foreach ($results as $i => $result) {
                if ($i < 3) {
                    $this->assertSame(200, $result['status'], json_encode($result['body']));
                    $posted++;

                    continue;
                }
                $this->assertSame(200, $result['status'], json_encode($result['body']));
                $ap = $result['body']['type'] ?? null ? $result['body'] : $result['body']['data'][0];
                $this->assertSame('MATCHED', $ap['status'], 'a reconciliation read during postings must not see a torn state: '.json_encode($ap));
                $this->assertSame($ap['source_balance'], $ap['gl_balance']);
            }
        }

        $final = $this->http()->getJson(self::RC.'/reconciliations/ap-gl?as_of=2026-03-31')->assertOk()->json();
        $this->assertSame(['MATCHED', $this->dec((string) (1000000 * $posted))], [$final['status'], $final['source_balance']]);
    }

    public function test_simultaneous_runs_of_one_reconciliation_leave_one_live_exception(): void
    {
        $this->http();
        $this->postedInvoice($this->vendor($this->tenant));
        $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-03-25');

        $results = (new RaceRunner)->start(array_fill(0, 4, $this->job('POST', self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-03-31'])))->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['201:' => 4], $this->outcomes($results));
        $this->assertSame(1, DB::table('reconciliation_exceptions')->where('tenant_id', $this->tenant->id)->where('status', '<>', 'RESOLVED')->count());
        $this->assertSame(4, DB::table('reconciliation_runs')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.reconciliation.exception.opened')->count());
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'accounting.reconciliation.run')->count());
        $this->assertSame(['-5000.0000'], array_unique(DB::table('reconciliation_exceptions')->pluck('difference')->map(fn ($d) => (string) $d)->all()));
    }

    public function test_simultaneous_resolve_requests_resolve_the_exception_once(): void
    {
        $this->http();
        $this->postedInvoice($this->vendor($this->tenant));
        $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-03-25');
        $id = $this->http()->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-03-31'])->assertCreated()->json('exception.id');
        $this->strayLine($this->tenant, '2110', 'DEBIT', '5000', '2026-03-26'); // the cause is corrected

        $results = (new RaceRunner)->start(array_fill(0, 3, $this->job('POST', self::RC."/reconciliation-exceptions/{$id}/resolve", ['note' => 'Dikoreksi'])))->results();

        $this->assertNoServerErrors($results);
        foreach ($results as $result) {
            $this->assertContains($result['status'], [200, 409], json_encode($result['body']));
            $result['status'] === 200 ? $this->assertSame('RESOLVED', $result['body']['status']) : $this->assertSame('RECON_EXCEPTION_RESOLVED', $result['body']['code']);
        }
        $this->assertGreaterThanOrEqual(1, count(array_filter($results, fn ($r) => $r['status'] === 200)));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.reconciliation.exception.resolved')->count());
        $row = DB::table('reconciliation_exceptions')->where('id', $id)->first();
        $this->assertSame(['RESOLVED', 'MATCHED'], [$row->status, $row->calculated_status]);
        $this->assertNotNull($row->resolved_by);
    }
}
