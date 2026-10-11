<?php

namespace Tests\Feature\Oa5;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountingFixtures;
use Tests\Support\BudgetFixtures;
use Tests\Support\ClosingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\Oa5Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\ReconciliationFixtures;
use Tests\Support\ReportingFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/**
 * OA5 verification (spec 72, 82): ACCOUNTING_REPORTING in every entitlement state against a representative route of EVERY family, with VALID
 * bodies, so the only possible reason for a refusal is the entitlement. The generic route sweeps (ReportingAccessMatrixTest and its siblings) send
 * placeholder bodies and accept any answer that passed the gate; here the reads must return their data, the mutations must change nothing, and the
 * same requests must succeed once the module is ACTIVE again. Also: module-aware reconciliations (each type needs its own module) and the
 * transactional core of tenants that do not hold a writable ACCOUNTING_REPORTING (OA0-OA4 behaviour, spec 72).
 */
class EntitlementFamiliesTest extends TestCase
{
    use AccountingFixtures, BudgetFixtures, ClosingFixtures, FixedAssetFixtures, Fixtures, FxFixtures, Oa5Fixtures, PayablesFixtures, ReceivablesFixtures, ReconciliationFixtures, ReportingFixtures, TaxFixtures;

    private Tenant $tenant;

    private string $admin;

    /** @var array<string,string> */
    private array $ids = [];

    /** @var list<array<string,mixed>> the lines of the draft definition as the API shows them (read once: a closed module cannot be asked) */
    private array $draftLines = [];

    private const WATCHED = [
        'report_definitions', 'report_lines', 'report_line_mappings', 'reconciliation_runs', 'reconciliation_exceptions', 'closing_settings', 'closing_policy_rules',
        'closing_checklist_templates', 'closing_checklist_items', 'closing_events', 'year_end_closings', 'accounting_periods', 'journal_entries', 'audit_logs',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->oa5Tenant();
        $this->admin = $this->memberToken($this->tenant);
        $this->marchDocuments($this->tenant);
        $this->publishedDefinitions($this->tenant);
        $this->strayLine($this->tenant, '2110', 'CREDIT', '5000', '2026-03-25');
        $this->as($this->admin);

        $this->ids['exception'] = $this->postJson(self::RC.'/reconciliations/ap-gl/run', ['as_of' => '2026-03-31'])->assertCreated()->json('exception.id');
        $active = DB::table('report_definitions')->where('tenant_id', $this->tenant->id)->where('statement_type', 'PROFIT_LOSS')->value('id');
        $this->ids['draft'] = $this->postJson(self::RP."/report-definitions/{$active}/new-version")->assertCreated()->json('id');
        $this->draftLines = $this->getJson(self::RP."/report-definitions/{$this->ids['draft']}")->assertOk()->json('lines');
        $this->ids['template'] = $this->postJson(self::CL.'/closing/checklist-templates', ['code' => 'BANK', 'title' => 'Rekening koran lengkap', 'required' => false])->assertCreated()->json('id');
        $this->ids['march'] = $this->period($this->tenant, '2026-03')->id;
        $this->ids['year'] = $this->fiscalYear($this->tenant, 'FY2026')->id;
        $this->ids['owner'] = $this->member($this->tenant)[0]->id;
        $this->hardClose($this->tenant, '2026-01');
        $this->as($this->admin);
    }

    /** @return array<string,array{0:string,1:string}> label => [path, description]: every read family, with parameters that return data */
    private function reads(): array
    {
        $d = $this->ids['draft'];
        $range = 'from=2026-03-01&to=2026-03-31';

        return [
            'balance sheet' => ['reports/balance-sheet?as_of=2026-03-31'], 'profit and loss' => ["reports/profit-loss?{$range}"], 'cash flow' => ["reports/cash-flow?{$range}"],
            'comparative' => ["reports/comparative?statement=PROFIT_LOSS&mode=PRIOR_PERIOD&{$range}"], 'account balance' => ['reports/account-balance?as_of=2026-03-31'],
            'drilldown' => ["reports/drilldown?statement=PROFIT_LOSS&line=ACC_4100&{$range}"],
            'export balance sheet' => ['reports/balance-sheet/export?as_of=2026-03-31'], 'export profit and loss' => ["reports/profit-loss/export?{$range}"],
            'export cash flow' => ["reports/cash-flow/export?{$range}"], 'export comparative' => ["reports/comparative/export?statement=PROFIT_LOSS&mode=PRIOR_PERIOD&{$range}"],
            'export account balance' => ['reports/account-balance/export?as_of=2026-03-31'],
            'definitions' => ['report-definitions'], 'definition' => ["report-definitions/{$d}"], 'unmapped' => ["report-definitions/{$d}/unmapped-accounts"],
            'reconciliations' => ['reconciliations?as_of=2026-03-31'], 'reconciliation' => ['reconciliations/ap-gl?as_of=2026-03-31'], 'runs' => ['reconciliations/runs'],
            'export reconciliations' => ['reconciliations/export?as_of=2026-03-31'], 'export reconciliation' => ['reconciliations/ap-gl/export?as_of=2026-03-31'],
            'exceptions' => ['reconciliation-exceptions'],
            'closing overview' => ['closing/overview'], 'closing history' => ['closing/history'], 'policy' => ['closing/policy'], 'templates' => ['closing/checklist-templates'],
            'readiness' => ["closing/periods/{$this->ids['march']}/readiness"], 'checklist' => ["closing/periods/{$this->ids['march']}/checklist"],
            'year-end index' => ['year-end'], 'year-end rule status' => ['year-end/rule-status'], 'year-end year' => ["year-end/{$this->ids['year']}"],
            'year-end preview' => ["year-end/{$this->ids['year']}/preview"],
        ];
    }

    /**
     * Every mutation family with a valid body, in an order in which each succeeds on a module that is ACTIVE: [method, path, body, statuses that prove
     * it passed the gate on an ACTIVE module]. A closure supplies ids that only exist after an earlier step.
     *
     * @return array<string,array{0:string,1:callable():string,2:callable():array<string,mixed>,3:list<int>}>
     */
    private function mutations(array &$ctx): array
    {
        $i = $this->ids;
        $draft = fn () => $i['draft'];
        $detail = fn () => $this->draftLines;
        $lines = fn () => ['lines' => array_map(fn ($l) => array_intersect_key($l, array_flip(['code', 'label', 'line_type', 'parent_code', 'display_order', 'balance_side', 'cash_flow_category', 'system_key', 'operands', 'show_zero', 'emphasis', 'notes'])), $detail())];
        $mappings = fn () => ['mappings' => collect($detail())->flatMap(fn ($l) => array_map(fn ($m) => ['line_code' => $l['code'], 'account_id' => $m['account_id'], 'include_descendants' => $m['include_descendants']], $l['mappings']))->values()->all()];

        return [
            'create a definition' => ['POST', fn () => 'report-definitions', fn () => ['statement_type' => 'CASH_FLOW', 'name' => 'Arus kas uji'], [201]],
            'rename the draft' => ['PATCH', fn () => "report-definitions/{$draft()}", fn () => ['name' => 'Laba rugi revisi'], [200]],
            'replace the lines' => ['PUT', fn () => "report-definitions/{$draft()}/lines", $lines, [200]],
            'replace the mapping' => ['PUT', fn () => "report-definitions/{$draft()}/mappings", $mappings, [200]],
            'publish' => ['POST', fn () => "report-definitions/{$draft()}/publish", fn () => ['effective_from' => '2026-07-01'], [200]],
            'new version' => ['POST', fn () => "report-definitions/{$draft()}/new-version", fn () => [], [201]],
            'delete a draft' => ['DELETE', function () use (&$ctx, $draft) {
                return 'report-definitions/'.($ctx['version'] ?? $draft());
            }, fn () => [], [204]],
            'archive' => ['POST', fn () => "report-definitions/{$draft()}/archive", fn () => [], [200]],
            'run a reconciliation' => ['POST', fn () => 'reconciliations/ap-gl/run', fn () => ['as_of' => '2026-03-31'], [201]],
            'assign an exception' => ['POST', fn () => "reconciliation-exceptions/{$i['exception']}/assign", fn () => ['owner_id' => $i['owner']], [200]],
            'explain an exception' => ['POST', fn () => "reconciliation-exceptions/{$i['exception']}/explain", fn () => ['notes' => 'Jurnal manual salah akun'], [200]],
            'resolve an exception' => ['POST', fn () => "reconciliation-exceptions/{$i['exception']}/resolve", fn () => ['note' => 'Sudah dikoreksi'], [409]], // still differs: a business answer, not a refusal
            'change the policy' => ['PUT', fn () => 'closing/policy', fn () => ['settings' => ['require_soft_close_first' => false]], [200]],
            'add a checklist template' => ['POST', fn () => 'closing/checklist-templates', fn () => ['code' => 'NEW1', 'title' => 'Butir uji', 'required' => false], [201]],
            'change a template' => ['PATCH', fn () => "closing/checklist-templates/{$i['template']}", fn () => ['title' => 'Rekening koran lengkap dan cocok'], [200]],
            'delete a template' => ['DELETE', function () use (&$ctx, $i) {
                return 'closing/checklist-templates/'.($ctx['template'] ?? $i['template']);
            }, fn () => [], [200, 204]],
            'complete a checklist item' => ['POST', fn () => "closing/periods/{$i['march']}/checklist/BANK/complete", fn () => [], [200]],
            'pre-close' => ['POST', fn () => "closing/periods/{$i['march']}/pre-close", fn () => [], [200]],
            'soft close' => ['POST', fn () => "closing/periods/{$i['march']}/soft-close", fn () => ['reason' => 'Mulai penutupan'], [200]],
            'reopen' => ['POST', fn () => 'closing/periods/'.$this->period($this->tenant, '2026-01')->id.'/reopen', fn () => ['reason' => 'Koreksi akrual yang terlewat', 'target' => 'OPEN'], [200]],
            'hard close' => ['POST', fn () => 'closing/periods/'.$this->period($this->tenant, '2026-02')->id.'/hard-close', fn () => [], [200]],
            'year-end rule defaults' => ['POST', fn () => 'year-end/rule-defaults', fn () => [], [201]],
            'year-end run' => ['POST', fn () => "year-end/{$i['year']}/run", fn () => ['reason' => 'Penutupan tahun buku'], [201]],
            'year-end reverse' => ['POST', fn () => "year-end/{$i['year']}/reverse", fn () => ['reason' => 'Koreksi penutupan tahun'], [200]],
        ];
    }

    private function send(string $method, string $path, array $body = []): TestResponse
    {
        return $this->as($this->admin)->json($method, self::RP.'/'.$path, $body);
    }

    private function assertRefused(TestResponse $response, string $code, string $label): void
    {
        $this->assertSame(403, $response->getStatusCode(), "{$label}: ".substr((string) $response->getContent(), 0, 200));
        $this->assertSame($code, $response->json('code'), $label);
    }

    private function remember(string $label, TestResponse $response, array &$ctx): void
    {
        if ($label === 'new version') {
            $ctx['version'] = $response->json('id');
        }
        if ($label === 'add a checklist template') {
            $ctx['template'] = $response->json('id');
        }
    }

    public function test_every_family_keeps_reading_and_refuses_every_valid_mutation_in_each_entitlement_state_and_works_again_when_active(): void
    {
        $base = $this->moduleRow($this->tenant, 'ACCOUNTING_REPORTING');
        $yesterday = Carbon::parse($this->tenant->businessDate())->subDay()->toDateString();
        $tomorrow = Carbon::parse($this->tenant->businessDate())->addDay()->toDateString();
        $states = [
            'READ_ONLY' => [['state' => 'READ_ONLY'], 'MODULE_READ_ONLY', true],
            'SUSPENDED' => [['state' => 'SUSPENDED'], 'MODULE_NOT_ENTITLED', false],
            'DISABLED' => [['state' => 'DISABLED'], 'MODULE_NOT_ENTITLED', false],
            'expired' => [['state' => 'ACTIVE', 'effective_from' => '2020-01-01', 'effective_until' => $yesterday], 'MODULE_NOT_ENTITLED', false],
            'future-effective' => [['state' => 'ACTIVE', 'effective_from' => $tomorrow, 'effective_until' => null], 'MODULE_NOT_ENTITLED', false],
        ];

        // Control: on an ACTIVE module every read answers 200 with data (the premise of the refusals below).
        foreach ($this->reads() as $label => [$path]) {
            $this->send('GET', $path)->assertOk();
        }
        $before = $this->rowHashes($this->tenant, self::WATCHED);

        $ctx = [];
        foreach ($states as $name => [$attrs, $code, $readable]) {
            $this->moduleWindow($this->tenant, 'ACCOUNTING_REPORTING', $attrs);
            foreach ($this->reads() as $label => [$path]) {
                $response = $this->send('GET', $path);
                $readable ? $response->assertOk() : $this->assertRefused($response, 'MODULE_NOT_ENTITLED', "{$name}: {$label}");
                if ($readable && str_contains($label, 'export')) {
                    $this->assertNotSame('', $response->streamedContent(), "{$name}: {$label} is an empty file");
                }
            }
            foreach ($this->mutations($ctx) as $label => [$method, $path, $body]) {
                $this->assertRefused($this->send($method, $path(), $body()), $code, "{$name}: {$label}");
            }
            $after = $this->rowHashes($this->tenant, array_diff(self::WATCHED, ['audit_logs']));
            $this->assertSame(array_diff_key($before, ['audit_logs' => 1]), $after, "{$name}: a refused mutation left a trace");
        }

        // The same requests succeed once the module is ACTIVE again: the refusals above were the entitlement and nothing else.
        $this->moduleWindow($this->tenant, 'ACCOUNTING_REPORTING', $base);
        foreach ($this->mutations($ctx) as $label => [$method, $path, $body, $ok]) {
            $response = $this->send($method, $path(), $body());
            $this->assertContains($response->getStatusCode(), $ok, "ACTIVE control {$label}: ".substr((string) $response->getContent(), 0, 300));
            $this->remember($label, $response, $ctx);
        }
    }

    public function test_an_overdue_subscription_keeps_the_reads_and_refuses_the_mutations_of_every_family(): void
    {
        DB::table('subscriptions')->where('tenant_id', $this->tenant->id)->update(['status' => 'PAST_DUE']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $before = $this->rowHashes($this->tenant, array_diff(self::WATCHED, ['audit_logs']));

        foreach ($this->reads() as $label => [$path]) {
            $this->send('GET', $path)->assertOk();
        }
        $ctx = [];
        foreach ($this->mutations($ctx) as $label => [$method, $path, $body]) {
            $this->assertRefused($this->send($method, $path(), $body()), 'SUBSCRIPTION_READ_ONLY', $label);
        }
        $this->assertSame($before, $this->rowHashes($this->tenant, array_diff(self::WATCHED, ['audit_logs'])));
    }

    public function test_each_reconciliation_needs_its_own_module_whatever_the_reporting_module_says(): void
    {
        $modules = ['ap-gl' => 'ACCOUNTING_AP', 'ar-gl' => 'ACCOUNTING_AR', 'cash-bank-gl' => 'ACCOUNTING_CASH_BANK', 'fixed-asset-gl' => 'ACCOUNTING_FIXED_ASSET', 'tax-gl' => 'ACCOUNTING_TAX', 'fx-gl' => 'ACCOUNTING_MULTI_CURRENCY'];
        $yesterday = Carbon::parse($this->tenant->businessDate())->subDay()->toDateString();
        $tomorrow = Carbon::parse($this->tenant->businessDate())->addDay()->toDateString();
        $removed = [
            'SUSPENDED' => ['state' => 'SUSPENDED'], 'DISABLED' => ['state' => 'DISABLED'],
            'expired' => ['state' => 'ACTIVE', 'effective_from' => '2020-01-01', 'effective_until' => $yesterday], 'future-effective' => ['state' => 'ACTIVE', 'effective_from' => $tomorrow, 'effective_until' => null],
        ];
        $march = "closing/periods/{$this->ids['march']}/readiness";
        $checkOf = ['ap-gl' => 'AP_RECONCILIATION', 'ar-gl' => 'AR_RECONCILIATION', 'cash-bank-gl' => 'CASH_BANK_RECONCILIATION', 'fixed-asset-gl' => 'FIXED_ASSET_RECONCILIATION', 'tax-gl' => 'TAX_RECONCILIATION', 'fx-gl' => 'FX_RECONCILIATION'];

        foreach ($modules as $slug => $module) {
            $base = $this->moduleRow($this->tenant, $module);
            foreach ($removed as $name => $attrs) {
                $this->moduleWindow($this->tenant, $module, $attrs);
                foreach ([['GET', "reconciliations/{$slug}?as_of=2026-03-31", []], ['GET', "reconciliations/{$slug}/export?as_of=2026-03-31", []], ['POST', "reconciliations/{$slug}/run", ['as_of' => '2026-03-31']]] as [$method, $path, $body]) {
                    $this->assertRefused($this->send($method, $path, $body), 'MODULE_NOT_ENTITLED', "{$slug} {$name} {$path}");
                }
                $types = array_column($this->send('GET', 'reconciliations?as_of=2026-03-31')->assertOk()->json('data'), 'type');
                $this->assertNotContains(strtoupper(str_replace('-', '_', $slug)), $types, "{$slug} {$name}: the overview still lists it");
                $this->assertSame('SKIPPED', collect($this->send('GET', $march)->assertOk()->json('checks'))->firstWhere('code', $checkOf[$slug])['result'], "{$slug} {$name}: close readiness must not require it");
                $other = array_key_first(array_diff_key($modules, [$slug => 1]));
                $this->send('GET', "reconciliations/{$other}?as_of=2026-03-31")->assertOk();
            }
            $this->moduleWindow($this->tenant, $module, $base);
            $this->send('GET', "reconciliations/{$slug}?as_of=2026-03-31")->assertOk();
        }
    }

    public function test_the_ledger_module_underneath_also_closes_the_closing_actions(): void
    {
        // ACCOUNTING_CORE read-only while ACCOUNTING_REPORTING is still active: a closing action changes the period of the core ledger.
        $this->moduleWindow($this->tenant, 'ACCOUNTING_CORE', ['state' => 'READ_ONLY']);
        $periods = $this->rowHashes($this->tenant, ['accounting_periods', 'closing_events']);

        foreach (['soft-close', 'hard-close', 'pre-close'] as $action) {
            $this->send('POST', "closing/periods/{$this->ids['march']}/{$action}")->assertStatus(403);
        }
        $this->send('POST', 'closing/periods/'.$this->period($this->tenant, '2026-01')->id.'/reopen', ['reason' => 'Koreksi akrual yang terlewat', 'target' => 'OPEN'])->assertStatus(403);
        $this->send('POST', "year-end/{$this->ids['year']}/run")->assertStatus(403);
        $this->assertSame($periods, $this->rowHashes($this->tenant, ['accounting_periods', 'closing_events']));
    }

    public function test_the_transactional_core_keeps_working_without_a_writable_reporting_module(): void
    {
        $yesterday = Carbon::parse($this->tenant->businessDate())->subDay()->toDateString();
        $states = [
            'READ_ONLY' => ['state' => 'READ_ONLY'], 'SUSPENDED' => ['state' => 'SUSPENDED'], 'DISABLED' => ['state' => 'DISABLED'],
            'expired' => ['state' => 'ACTIVE', 'effective_from' => '2020-01-01', 'effective_until' => $yesterday],
        ];
        $vendor = $this->vendor($this->tenant, 'V9');
        $customer = $this->customer($this->tenant, 'C9');

        foreach ($states as $name => $attrs) {
            $this->moduleWindow($this->tenant, 'ACCOUNTING_REPORTING', $attrs);
            $this->as($this->admin);

            // Journal workflow, AP, AR: the OA0-OA4 behaviour, unchanged.
            $journal = $this->draft($this->tenant, ['document_date' => '2026-04-10', 'posting_date' => '2026-04-10']);
            foreach (['submit', 'approve', 'post'] as $step) {
                $this->postJson(self::RP."/journals/{$journal['id']}/{$step}")->assertOk();
            }
            $this->postedInvoice($vendor, ['vendor_invoice_number' => "T-{$name}", 'document_date' => '2026-04-10', 'posting_date' => '2026-04-10']);
            $this->postedArInvoice($customer, ['customer_reference' => "T-{$name}", 'document_date' => '2026-04-10', 'posting_date' => '2026-04-10']);

            // Legacy ledger reports and reconciliations stay on their own modules.
            $tb = $this->getJson(self::RP.'/trial-balance?from=2026-04-01&to=2026-04-30')->assertOk()->json();
            $this->assertSame($tb['totals']['debit'], $tb['totals']['credit'], $name);
            $this->getJson(self::RP.'/general-ledger?from=2026-04-01&to=2026-04-30')->assertOk();
            $this->getJson(self::RP.'/reconciliation/ap?as_of=2026-04-30')->assertOk()->assertJsonPath('status', 'MISMATCH'); // the stray line of setUp is still visible
            $this->getJson(self::RP.'/reconciliation/ar?as_of=2026-04-30')->assertOk()->assertJsonPath('status', 'MATCHED');

            // The OA5 families are closed or read-only, never silently open.
            $this->getJson(self::RP.'/reports/balance-sheet?as_of=2026-04-30')->assertStatus($name === 'READ_ONLY' ? 200 : 403);
        }
        $this->assertSame(4 * 3, DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->whereBetween('posting_date', ['2026-04-01', '2026-04-30'])->where('status', 'POSTED')->count());
    }
}
