<?php

namespace Tests\Feature\Reporting;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReportingFixtures;
use Tests\TestCase;

/** OA5 batch D: the CSV exports of the reports equal the reports on screen, are audited, and need the export permission plus the report's view permission. */
class ReportExportTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReportingFixtures;

    private Tenant $tenant;

    /** @var array<string,array<string,mixed>> */
    private array $published;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->reportingTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->standardLedger($this->tenant);
        $this->published = $this->publishedDefinitions($this->tenant);
    }

    /** The export as rows keyed by the header; also returns the response for its headers. @return array{0:list<array<string,string>>,1:\Illuminate\Testing\TestResponse} */
    private function export(string $path, array $query = []): array
    {
        $response = $this->get(self::RP."/reports/{$path}/export?".http_build_query($query))->assertOk();
        $rows = $this->csvRows($response->streamedContent());
        $header = array_shift($rows);

        return [array_map(fn (array $row) => array_combine($header, array_pad($row, count($header), '')), $rows), $response];
    }

    /** @param list<array<string,string>> $rows @return array<string,array<string,string>> the rows of one kind keyed by the given column */
    private function kind(array $rows, string $kind, string $key = 'Kode baris'): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row['Jenis'] === $kind) {
                $out[$row[$key]] = $row;
            }
        }

        return $out;
    }

    private function assertStatementCsvEqualsScreen(array $screen, array $rows): void
    {
        $lines = $this->kind($rows, 'Baris');
        $this->assertSame(array_column($screen['lines'], 'code'), array_keys($lines), 'the same lines in the same order');
        foreach ($screen['lines'] as $line) {
            $this->assertSame($line['amount'] ?? '', $lines[$line['code']]['Jumlah baris'], $line['code']);
            $this->assertSame($line['label'], $lines[$line['code']]['Uraian']);
            $this->assertSame($line['line_type'], $lines[$line['code']]['Tipe baris']);
            $this->assertSame((string) $line['depth'], $lines[$line['code']]['Kedalaman']);
        }
        $accounts = [];
        foreach ($rows as $row) {
            if ($row['Jenis'] === 'Akun') {
                $accounts[$row['Kode baris'].'/'.$row['Kode akun']] = $row['Jumlah akun'];
            }
        }
        $expected = [];
        foreach ($screen['lines'] as $line) {
            foreach ($line['accounts'] as $account) {
                $expected[$line['code'].'/'.$account['code']] = $account['amount'];
            }
        }
        $this->assertSame($expected, $accounts, 'the same accounts under the same lines with the same amounts');
        $this->assertSame([], array_values(array_filter($rows, fn ($r) => $r['Jenis'] === 'Akun' && $r['Jumlah baris'] !== '')), 'an account amount is never in the line column');
    }

    // ------------------------------------------------------------------------------------------ content equals the screen

    public function test_the_balance_sheet_export_equals_the_balance_sheet_on_screen(): void
    {
        $query = ['as_of' => '2026-03-31'];
        $screen = $this->statement('balance-sheet', $query);

        [$rows, $response] = $this->export('balance-sheet', $query);

        $this->assertStatementCsvEqualsScreen($screen, $rows);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('neraca_2026-03-31.csv', $response->headers->get('Content-Disposition'));
        $control = $this->kind($rows, 'Kontrol');
        $this->assertSame(['Ya', '14100000.0000', '14100000.0000', '0.0000', 'Ya', 'Ya'], [
            $control['complete']['Jumlah baris'], $control['assets']['Jumlah baris'], $control['liabilities_and_equity']['Jumlah baris'], $control['difference']['Jumlah baris'],
            $control['balanced']['Jumlah baris'], $control['reliable']['Jumlah baris'],
        ]);
    }

    public function test_the_profit_and_loss_export_equals_the_statement_on_screen(): void
    {
        $query = ['from' => '2026-01-01', 'to' => '2026-03-31'];
        $screen = $this->statement('profit-loss', $query);

        [$rows, $response] = $this->export('profit-loss', $query);

        $this->assertStatementCsvEqualsScreen($screen, $rows);
        $this->assertStringContainsString('laba-rugi_2026-01-01_2026-03-31.csv', $response->headers->get('Content-Disposition'));
        $this->assertSame('1900000.0000', $this->kind($rows, 'Kontrol')['ledger_result']['Jumlah baris']);
        $this->assertSame('1900000.0000', $this->kind($rows, 'Baris')['NET_RESULT_CALC']['Jumlah baris']);
    }

    public function test_the_cash_flow_export_equals_the_statement_on_screen_with_its_cash_accounts(): void
    {
        $query = ['from' => '2026-01-01', 'to' => '2026-03-31'];
        $screen = $this->statement('cash-flow', $query);

        [$rows] = $this->export('cash-flow', $query);

        $this->assertStatementCsvEqualsScreen($screen, $rows);
        $control = collect($rows)->where('Jenis', 'Kontrol');
        $this->assertSame('Ya', $control->firstWhere('Kode baris', 'reconciled')['Jumlah baris']);
        $this->assertSame('11600000.0000', $control->firstWhere('Kode baris', 'cash_closing_ledger')['Jumlah baris']);
        $closing = $control->where('Kode baris', 'cash_accounts.closing')->mapWithKeys(fn ($r) => [$r['Kode akun'] => $r['Jumlah akun']])->all();
        $this->assertSame(['1110' => '7200000.0000', '1120' => '4400000.0000'], $closing);
    }

    public function test_the_comparative_export_equals_the_comparative_on_screen(): void
    {
        $query = ['statement' => 'PROFIT_LOSS', 'mode' => 'PRIOR_PERIOD', 'period_id' => $this->period($this->tenant, '2026-02')->id];
        $screen = $this->getJson(self::RP.'/reports/comparative?'.http_build_query($query))->assertOk()->json();

        [$rows, $response] = $this->export('comparative', $query);

        $lines = $this->kind($rows, 'Baris');
        $this->assertSame(array_column($screen['lines'], 'code'), array_keys($lines));
        foreach ($screen['lines'] as $line) {
            $this->assertSame([$line['current'], $line['comparison'], $line['variance'], $line['variance_pct'] ?? ''],
                [$lines[$line['code']]['Berjalan'], $lines[$line['code']]['Pembanding'], $lines[$line['code']]['Selisih'], $lines[$line['code']]['Selisih %']], $line['code']);
        }
        $rent = collect($rows)->first(fn ($r) => $r['Jenis'] === 'Akun' && $r['Kode akun'] === '6200');
        $this->assertSame(['200000.0000', '0.0000', '200000.0000', ''], [$rent['Berjalan'], $rent['Pembanding'], $rent['Selisih'], $rent['Selisih %']], 'no percentage against a zero comparison');
        $this->assertStringContainsString('perbandingan-laba-rugi-prior-period_2026-02-01_2026-02-28.csv', $response->headers->get('Content-Disposition'));
        $this->assertSame('Ya', $this->kind($rows, 'Kontrol')['complete']['Berjalan']);
        $this->assertSame('1700000.0000', $this->kind($rows, 'Kontrol')['comparison.ledger_result']['Pembanding']);
        $this->assertSame('600000.0000', $this->kind($rows, 'Kontrol')['current.ledger_result']['Berjalan']);
    }

    public function test_the_account_balance_export_equals_the_report_on_screen(): void
    {
        $asOf = ['account_id' => $this->account($this->tenant, '1100')->id, 'as_of' => '2026-03-31'];
        $screen = $this->statement('account-balance', $asOf);

        [$rows, $response] = $this->export('account-balance', $asOf);

        $byCode = collect($rows)->keyBy('Kode akun');
        foreach ($screen['data'] as $r) {
            $this->assertSame($r['balance'], $byCode[$r['code']]['Saldo'], $r['code']);
        }
        $total = collect($rows)->firstWhere('Nama akun', 'Total');
        $this->assertSame($screen['totals']['balance'], $total['Saldo'], 'the total row');
        $this->assertSame('11600000.0000', $total['Saldo']);
        $this->assertSame('Ya', collect($rows)->firstWhere('Nama akun', 'Cakupan lengkap')['Saldo']);
        $this->assertStringContainsString('saldo-akun_2026-03-31.csv', $response->headers->get('Content-Disposition'));

        $period = ['account_id' => $this->account($this->tenant, '1110')->id, 'from' => '2026-01-01', 'to' => '2026-03-31'];
        $screen = $this->statement('account-balance', $period);
        [$rows] = $this->export('account-balance', $period);
        $first = collect($rows)->firstWhere('Kode akun', '1110');
        $this->assertSame([$screen['data'][0]['opening'], $screen['data'][0]['debit'], $screen['data'][0]['credit'], $screen['data'][0]['closing']], [$first['Saldo awal'], $first['Debit'], $first['Kredit'], $first['Saldo akhir']]);
        $this->assertSame('7200000.0000', $first['Saldo akhir']);
    }

    public function test_an_unreliable_statement_cannot_pass_for_a_clean_export(): void
    {
        // A profit and loss whose layout leaves account 6200 off: the ledger result and the statement result differ.
        $draft = $this->postJson(self::RP."/report-definitions/{$this->published['PROFIT_LOSS']['id']}/new-version")->assertCreated()->json('id');
        $kept = collect($this->getJson(self::RP."/report-definitions/{$draft}")->json('lines'))->reject(fn ($l) => $l['code'] === 'ACC_6200')
            ->map(fn ($l) => array_intersect_key($l, array_flip(['code', 'label', 'line_type', 'parent_code', 'display_order', 'balance_side', 'cash_flow_category', 'system_key', 'operands', 'show_zero', 'emphasis', 'notes'])))->values()->all();
        $this->putJson(self::RP."/report-definitions/{$draft}/lines", ['lines' => $kept])->assertOk();
        $this->postJson(self::RP."/report-definitions/{$draft}/publish", ['effective_from' => '2025-01-01'])->assertOk();

        [$rows] = $this->export('profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31']);

        $control = collect($rows)->where('Jenis', 'Kontrol');
        $this->assertSame('Tidak', $control->firstWhere('Kode baris', 'reliable')['Jumlah baris']);
        $this->assertSame('-200000.0000', $control->firstWhere('Kode baris', 'difference')['Jumlah baris'], 'the ledger result less the result the layout shows');
        $this->assertSame(['6200', '200000.0000'], [$control->firstWhere('Kode baris', 'unmapped')['Kode akun'], $control->firstWhere('Kode baris', 'unmapped')['Jumlah akun']]);
        $warnings = collect($rows)->where('Jenis', 'Peringatan')->pluck('Kode baris')->all();
        $this->assertContains('UNMAPPED_ACCOUNT_WITH_BALANCE', $warnings);
    }

    public function test_text_that_a_spreadsheet_could_execute_is_neutralised(): void
    {
        $id = $this->postJson(self::RP.'/report-definitions', ['statement_type' => 'PROFIT_LOSS', 'name' => 'Formula'])->assertCreated()->json('id');
        $this->putJson(self::RP."/report-definitions/{$id}/lines", ['lines' => [
            ['code' => 'REV', 'label' => '=HYPERLINK("http://x","klik")', 'line_type' => 'ACCOUNT_GROUP', 'balance_side' => 'CREDIT'],
        ]])->assertOk();
        $this->putJson(self::RP."/report-definitions/{$id}/mappings", ['mappings' => [
            ['line_code' => 'REV', 'account_id' => $this->account($this->tenant, '4000')->id, 'include_descendants' => true],
        ]])->assertOk();

        [$rows] = $this->export('profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31', 'definition_id' => $id]);

        $this->assertSame("'=HYPERLINK(\"http://x\",\"klik\")", $this->kind($rows, 'Baris')['REV']['Uraian']);
    }

    // ------------------------------------------------------------------------------------------ audit

    public function test_every_export_is_audited_with_its_filters_and_row_count(): void
    {
        $this->assertSame(0, $this->auditCount('accounting.report.exported'));

        [$rows] = $this->export('balance-sheet', ['as_of' => '2026-03-31', 'include_zero' => '1']);
        $this->export('profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31']);
        $this->export('cash-flow', ['period_id' => $this->period($this->tenant, '2026-03')->id]);
        $this->export('comparative', ['statement' => 'BALANCE_SHEET', 'mode' => 'PRIOR_YEAR', 'as_of' => '2026-03-31']);
        $this->export('account-balance', ['as_of' => '2026-03-31']);

        $logs = DB::table('audit_logs')->where('action', 'accounting.report.exported')->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertCount(5, $logs);
        $this->assertSame(['balance_sheet', 'profit_loss', 'cash_flow', 'comparative_balance_sheet_prior_year', 'account_balance'], $logs->pluck('resource_id')->all());
        $this->assertSame($this->tenant->id, $logs[0]->tenant_id);
        $this->assertNotNull($logs[0]->actor_user_id);

        $after = json_decode($logs[0]->changes, true)['after'];
        $this->assertEquals(['as_of' => '2026-03-31', 'include_zero' => true], $after['filters']);
        $this->assertSame('2026-03-31', $after['as_of']);
        $this->assertSame(count($rows), $after['rows']);
        $this->assertTrue($after['complete']);
        $this->assertEqualsCanonicalizing(['id', 'code', 'version'], array_keys($after['definition']));
        $this->assertEquals(['from' => '2026-01-01', 'to' => '2026-03-31'], json_decode($logs[1]->changes, true)['after']['range']);
    }

    public function test_a_refused_or_invalid_export_leaves_no_audit_row_and_no_file(): void
    {
        $this->getJson(self::RP.'/reports/balance-sheet/export?as_of=not-a-date')->assertUnprocessable();
        $this->getJson(self::RP.'/reports/profit-loss/export?from=2026-01-01&to=2026-03-31&period_id='.$this->period($this->tenant, '2026-03')->id)->assertUnprocessable()->assertJsonPath('code', 'DATE_FILTER_CONFLICT');
        $this->getJson(self::RP.'/reports/comparative/export?statement=PROFIT_LOSS')->assertUnprocessable();
        $this->getJson(self::RP.'/reports/profit-loss/export?definition_id='.fake()->uuid().'&from=2026-01-01&to=2026-03-31')->assertStatus(404);

        $token = $this->memberToken($this->tenant, ['accounting.reporting.pnl.view']);
        $this->as($token)->getJson(self::RP.'/reports/profit-loss/export?from=2026-01-01&to=2026-03-31')->assertForbidden();

        $this->assertSame(0, $this->auditCount('accounting.report.exported'));
    }

    // ------------------------------------------------------------------------------------------ permission

    public function test_an_export_needs_the_export_permission_and_the_view_permission_of_the_report(): void
    {
        $cases = [
            ['balance-sheet', ['as_of' => '2026-03-31'], 'accounting.reporting.balance_sheet.view'],
            ['profit-loss', ['from' => '2026-01-01', 'to' => '2026-03-31'], 'accounting.reporting.pnl.view'],
            ['cash-flow', ['from' => '2026-01-01', 'to' => '2026-03-31'], 'accounting.reporting.cash_flow.view'],
            ['comparative', ['statement' => 'CASH_FLOW', 'mode' => 'PRIOR_YEAR', 'from' => '2026-01-01', 'to' => '2026-03-31'], 'accounting.reporting.cash_flow.view'],
            ['account-balance', ['as_of' => '2026-03-31'], 'accounting.gl.view'],
        ];
        foreach ($cases as [$path, $query, $view]) {
            $url = self::RP."/reports/{$path}/export?".http_build_query($query);
            // The export permission alone opens no report ...
            $this->as($this->memberToken($this->tenant, ['accounting.report.export']))->getJson($url)->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED')->assertJsonPath('details.permission', $view);
            // ... the view permission alone exports nothing ...
            $this->as($this->memberToken($this->tenant, [$view]))->getJson($url)->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
            // ... both together do.
            $this->as($this->memberToken($this->tenant, [$view, 'accounting.report.export']))->get($url)->assertOk();
        }
        // The comparative of a statement a user cannot view stays closed even though the route gate (profit and loss) is open to them.
        $pnl = $this->memberToken($this->tenant, ['accounting.reporting.pnl.view', 'accounting.report.export']);
        $this->as($pnl)->getJson(self::RP.'/reports/comparative/export?'.http_build_query(['statement' => 'BALANCE_SHEET', 'mode' => 'PRIOR_YEAR', 'as_of' => '2026-03-31']))
            ->assertForbidden()->assertJsonPath('details.permission', 'accounting.reporting.balance_sheet.view');
    }

    public function test_exports_follow_the_module_entitlement(): void
    {
        $moduleId = DB::table('modules')->where('code', 'ACCOUNTING_REPORTING')->value('id');
        $url = self::RP.'/reports/profit-loss/export?from=2026-01-01&to=2026-03-31';

        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', $moduleId)->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->signedIn($this->tenant);
        $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENTITLED');

        // Exporting is reading: a read-only module still answers.
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', $moduleId)->update(['state' => 'READ_ONLY']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->get($url)->assertOk();
    }
}
