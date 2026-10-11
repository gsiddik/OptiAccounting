<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA5 batch F: the overview, the detail and the CSV exports are one calculation; an export carries what the screen shows to the same caller and nothing else. */
class ReconciliationExportTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reconTenant();
        $this->signedIn($this->tenant);
        $vendor = $this->vendor($this->tenant, 'V1');
        $this->postedInvoice($vendor);
        $this->postedTaxedInvoice($vendor, $this->taxCode()['id']);
        $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-03-25');
    }

    /** @return list<list<string>> the rows of a CSV download without the byte order mark */
    private function csv(string $uri, ?string $token = null): array
    {
        $response = ($token ? $this->as($token) : $this)->get(self::RC.$uri)->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());

        return array_map(fn ($line) => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\n", $text), fn ($l) => $l !== '')));
    }

    /** The CSV rows a result must produce: the headline, then its components. @return list<list<string>> */
    private function expected(array $result): array
    {
        $rows = [[$result['type'], $result['as_of'], $result['status'], '', implode(' ', $result['notes']), $result['source_balance'] ?? '', $result['gl_balance'] ?? '', $result['difference'] ?? '', $result['tolerance'], $result['complete'] ? 'Ya' : 'Tidak']];
        foreach ($result['components'] as $c) {
            $rows[] = [$result['type'], $result['as_of'], $c['status'], $c['code'], $c['label'], $c['source_balance'] ?? '', $c['gl_balance'] ?? '', $c['difference'] ?? '', '', ''];
        }

        return $rows;
    }

    public function test_the_overview_export_equals_the_screen_and_is_audited(): void
    {
        $screen = $this->getJson(self::RC.'/reconciliations?as_of=2026-03-31')->assertOk()->json();
        $this->assertSame(['AP_GL', 'AR_GL', 'CASH_BANK_GL', 'FIXED_ASSET_GL', 'TAX_GL', 'FX_GL'], array_column($screen['data'], 'type'));
        $statuses = array_column($screen['data'], 'status', 'type');
        $this->assertSame(['MISMATCH', 'MATCHED', 'MATCHED', 'MATCHED', 'MATCHED', 'NOT_APPLICABLE'], array_values($statuses));
        $this->assertTrue($screen['complete']);
        $this->assertSame($screen, array_intersect_key($screen, array_flip(['as_of', 'data', 'complete'])), 'the overview has exactly these keys');

        $rows = $this->csv('/reconciliations/export?as_of=2026-03-31');
        $this->assertSame(['Jenis', 'Tanggal', 'Status', 'Komponen', 'Keterangan', 'Saldo sumber', 'Saldo buku besar', 'Selisih', 'Toleransi', 'Lengkap'], $rows[0]);
        $this->assertSame(array_merge(...array_map(fn ($r) => $this->expected($r), $screen['data'])), array_slice($rows, 1));
        $this->assertSame(['AP_GL', '2026-03-31', 'MISMATCH', '', $rows[1][4], '2110000.0000', '2115000.0000', '-5000.0000', '0.0000', 'Ya'], array_slice($rows[1], 0, 10));

        $audit = DB::table('audit_logs')->where('action', 'accounting.report.exported')->where('resource_type', 'reconciliation')->first();
        $this->assertSame('overview', $audit->resource_id);
        $this->assertSame('MISMATCH', json_decode($audit->changes, true)['after']['results'][0]['status']);
    }

    public function test_one_reconciliation_exports_exactly_its_screen(): void
    {
        $screen = $this->getJson(self::RC.'/reconciliations/ap-gl?as_of=2026-03-31')->assertOk()->json();
        $rows = $this->csv('/reconciliations/ap-gl/export?as_of=2026-03-31');
        $this->assertSame($this->expected($screen), array_slice($rows, 1));
        $this->assertSame('ap-gl', DB::table('audit_logs')->where('action', 'accounting.report.exported')->value('resource_id'));
        $this->assertSame(array_keys($screen), ['type', 'as_of', 'status', 'source_balance', 'gl_balance', 'difference', 'tolerance', 'complete', 'components', 'notes']);

        $tax = $this->csv('/reconciliations/tax-gl/export?as_of=2026-03-31');
        $this->assertSame('TAX_GL', $tax[1][0]);
        $this->assertContains('TAX_INPUT_RECOVERABLE', array_column($tax, 3));
    }

    public function test_an_export_to_a_caller_with_a_narrower_data_scope_carries_no_figures(): void
    {
        [$branch] = $this->branches($this->tenant, 'JKT');
        $scoped = $this->scopedToken($this->tenant, ['accounting.report.export', 'accounting.reconciliation.view'], 'BRANCH', $branch);

        $rows = $this->csv('/reconciliations/export?as_of=2026-03-31', $scoped);
        $this->assertSame(['INCOMPLETE'], array_values(array_unique(array_column(array_slice($rows, 1), 2))));
        $this->assertSame([''], array_values(array_unique(array_merge(...array_map(fn ($r) => array_slice($r, 5, 3), array_slice($rows, 1))))));
        $this->assertSame(['Tidak'], array_values(array_unique(array_column(array_slice($rows, 1), 9))));
        $this->assertStringNotContainsString('2115000', json_encode($rows));
    }

    public function test_another_tenants_export_contains_nothing_of_this_one(): void
    {
        $bravo = $this->reconTenant('bravo');
        $this->signedIn($bravo);

        $rows = $this->csv('/reconciliations/export?as_of=2026-03-31');
        $this->assertStringNotContainsString('2115000', json_encode($rows));
        $this->assertSame(['MATCHED', 'MATCHED', 'MATCHED', 'MATCHED', 'NOT_APPLICABLE', 'NOT_APPLICABLE'], array_column(array_filter($rows, fn ($r) => $r[3] === '' && $r[0] !== 'Jenis'), 2));
    }

    public function test_the_date_defaults_to_the_tenant_business_date_and_must_be_valid(): void
    {
        $overview = $this->getJson(self::RC.'/reconciliations')->assertOk()->json();
        $this->assertSame($this->tenant->businessDate(), $overview['as_of']);
        $this->getJson(self::RC.'/reconciliations?as_of=31-03-2026')->assertStatus(422);
        $this->getJson(self::RC.'/reconciliations?as_of=2026-02-30')->assertStatus(422);
        $this->getJson(self::RC.'/reconciliations?period_id=nope')->assertStatus(422);
    }
}
