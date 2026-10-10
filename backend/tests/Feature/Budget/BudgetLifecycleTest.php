<?php

namespace Tests\Feature\Budget;

use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\BudgetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA4 batch A: budget lifecycle, versions (history is never rewritten), lines, and the rules the database repeats. */
class BudgetLifecycleTest extends TestCase
{
    use AccountingFixtures, BudgetFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->budgetTenant();
        $this->signedIn($this->tenant);
    }

    public function test_a_budget_is_created_as_a_draft_in_the_functional_currency_and_its_code_is_unique(): void
    {
        $b = $this->postJson(self::BG.'/budgets', ['code' => 'OPEX-26', 'name' => 'Beban operasional 2026', 'fiscal_year_id' => $this->yearId($this->tenant), 'description' => 'Rencana tahunan'])
            ->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('currency', 'IDR')->assertJsonPath('fiscal_year.code', 'FY2026')->json();
        $this->assertSame('OPEX-26', $b['code']);

        $this->postJson(self::BG.'/budgets', ['code' => 'OPEX-26', 'name' => 'Lain', 'fiscal_year_id' => $this->yearId($this->tenant)])->assertStatus(422)->assertJsonPath('code', 'BUDGET_CODE_TAKEN');
        $this->postJson(self::BG.'/budgets', ['code' => 'bad code!', 'name' => 'X', 'fiscal_year_id' => $this->yearId($this->tenant)])->assertStatus(422)->assertJsonPath('code', 'BUDGET_CODE_INVALID');
        $this->postJson(self::BG.'/budgets', ['code' => 'NOFY', 'name' => 'X', 'fiscal_year_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'FISCAL_YEAR_NOT_FOUND');

        $this->assertSame(['budget.created'], DB::table('audit_logs')->where('resource_type', 'budget')->where('resource_id', $b['id'])->pluck('action')->all());
        $this->assertSame(['DRAFT'], DB::table('document_transitions')->where('document_type', 'budget')->where('document_id', $b['id'])->pluck('to_status')->all());
    }

    public function test_the_budget_lifecycle_moves_only_along_its_graph_and_a_budget_with_an_approved_version_cannot_be_cancelled(): void
    {
        $b = $this->newBudget($this->tenant);
        $this->postJson(self::BG."/budgets/{$b['id']}/close")->assertStatus(409)->assertJsonPath('code', 'BUDGET_INVALID_TRANSITION');
        $this->postJson(self::BG."/budgets/{$b['id']}/open")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->patchJson(self::BG."/budgets/{$b['id']}", ['name' => 'Nama baru', 'description' => 'Catatan'])->assertOk()->assertJsonPath('name', 'Nama baru');

        $v = $this->newVersion($b['id']);
        $this->putLines($v['id'], [$this->bline($this->tenant, '6100', '2026-01', '5000000')]);
        $this->approveVersion($v['id']);
        $this->postJson(self::BG."/budgets/{$b['id']}/cancel", ['reason' => 'salah'])->assertStatus(409)->assertJsonPath('code', 'BUDGET_HAS_APPROVED_VERSION');

        $this->postJson(self::BG."/budgets/{$b['id']}/close")->assertOk()->assertJsonPath('status', 'CLOSED');
        $this->postJson(self::BG."/budgets/{$b['id']}/open")->assertStatus(409);
        $this->patchJson(self::BG."/budgets/{$b['id']}", ['name' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'BUDGET_NOT_EDITABLE');
        $this->postJson(self::BG."/budgets/{$b['id']}/versions", ['label' => 'Revisi'])->assertStatus(409);
        $this->postJson(self::BG."/budget-versions/{$v['id']}/activate")->assertStatus(409)->assertJsonPath('code', 'BUDGET_NOT_ACTIVE');

        $this->assertSame(['budget.created', 'budget.activated', 'budget.updated', 'budget.closed'], DB::table('audit_logs')->where('resource_type', 'budget')->where('resource_id', $b['id'])->orderBy('occurred_at')->pluck('action')->all());
    }

    public function test_cancelling_a_budget_without_approved_versions_cancels_its_open_drafts(): void
    {
        $b = $this->newBudget($this->tenant);
        $v = $this->newVersion($b['id']);
        $this->postJson(self::BG."/budgets/{$b['id']}/cancel", ['reason' => 'Tidak jadi'])->assertOk()->assertJsonPath('status', 'CANCELLED')->assertJsonPath('cancel_reason', 'Tidak jadi');
        $this->assertSame('CANCELLED', DB::table('budget_versions')->where('id', $v['id'])->value('status'));
        $this->postJson(self::BG."/budgets/{$b['id']}/open")->assertStatus(409);
    }

    public function test_versions_are_numbered_per_budget_copy_lines_and_keep_their_labels_free_text(): void
    {
        $b = $this->newBudget($this->tenant);
        $v1 = $this->newVersion($b['id'], ['label' => 'Original']);
        $this->assertSame(1, $v1['version_number']);
        $this->putLines($v1['id'], [$this->bline($this->tenant, '6100', '2026-01', '1000000'), $this->bline($this->tenant, '6200', '2026-01', '2000000')]);

        $v2 = $this->newVersion($b['id'], ['label' => 'Revisi tengah tahun', 'copy_from_version_id' => $v1['id']]);
        $this->assertSame(2, $v2['version_number']);
        $this->assertSame($v1['id'], $v2['base_version_id']);
        $this->assertCount(2, $v2['lines']);
        $this->assertSame('3000000.0000', $v2['lines_total']);
        $this->assertNotEquals(array_column($v1['lines'] ?? [], 'id'), array_column($v2['lines'], 'id'));

        $other = $this->newBudget($this->tenant);
        $this->postJson(self::BG."/budgets/{$other['id']}/versions", ['copy_from_version_id' => $v1['id']])->assertStatus(422)->assertJsonPath('code', 'BUDGET_VERSION_NOT_FOUND');
        $this->assertSame(1, $this->newVersion($other['id'])['version_number']);

        $this->patchJson(self::BG."/budget-versions/{$v2['id']}", ['label' => 'Revisi 1'])->assertOk()->assertJsonPath('label', 'Revisi 1');
        $this->patchJson(self::BG."/budget-versions/{$v2['id']}", ['label' => ' '])->assertStatus(422);
    }

    public function test_the_version_workflow_enforces_segregation_of_duties_and_history_and_empty_versions_cannot_be_submitted(): void
    {
        $strict = $this->budgetTenant('strict', ['sod_creator_not_approver' => true]);
        $this->signedIn($strict);
        $b = $this->newBudget($strict);
        $this->postJson(self::BG."/budgets/{$b['id']}/open")->assertOk();
        $v = $this->newVersion($b['id']);

        $this->postJson(self::BG."/budget-versions/{$v['id']}/submit")->assertStatus(422)->assertJsonPath('code', 'BUDGET_VERSION_EMPTY');
        $this->putLines($v['id'], [$this->bline($strict, '6100', '2026-02', '750000')]);
        $this->postJson(self::BG."/budget-versions/{$v['id']}/submit")->assertOk()->assertJsonPath('status', 'SUBMITTED');
        $this->postJson(self::BG."/budget-versions/{$v['id']}/approve")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');

        $this->signedIn($strict); // another member
        $this->postJson(self::BG."/budget-versions/{$v['id']}/reject", ['reason' => 'Terlalu rendah'])->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->putJson(self::BG."/budget-versions/{$v['id']}/lines", ['lines' => []])->assertStatus(409)->assertJsonPath('code', 'BUDGET_VERSION_NOT_DRAFT');
        $this->postJson(self::BG."/budget-versions/{$v['id']}/reopen")->assertOk()->assertJsonPath('status', 'DRAFT');
        $this->postJson(self::BG."/budget-versions/{$v['id']}/submit")->assertOk();
        $this->signedIn($strict); // a third person approves: neither the preparer nor the submitter may
        $this->postJson(self::BG."/budget-versions/{$v['id']}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson(self::BG."/budget-versions/{$v['id']}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('effective_from', '2026-01-01');

        $this->assertSame(['DRAFT', 'SUBMITTED', 'REJECTED', 'DRAFT', 'SUBMITTED', 'APPROVED', 'ACTIVE'], DB::table('document_transitions')
            ->where('document_type', 'budget_version')->where('document_id', $v['id'])->orderBy('occurred_at')->pluck('to_status')->all());
        $this->assertContains('budget.version.activated', DB::table('audit_logs')->where('resource_id', $v['id'])->pluck('action')->all());
    }

    public function test_activating_a_revision_supersedes_the_previous_version_without_rewriting_it(): void
    {
        $first = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '6100', '2026-01', '1000000'), $this->bline($this->tenant, '6100', '2026-06', '1000000')]);
        $budgetId = $first['budget']['id'];
        $v1 = $first['version'];
        $this->assertSame('2026-01-01', $v1['effective_from']);
        $this->assertNull($v1['effective_until']);
        $linesBefore = DB::table('budget_lines')->where('budget_version_id', $v1['id'])->orderBy('id')->get()->map(fn ($l) => (array) $l)->all();

        $v2 = $this->newVersion($budgetId, ['label' => 'Revisi 1', 'copy_from_version_id' => $v1['id']]);
        $this->putLines($v2['id'], [$this->bline($this->tenant, '6100', '2026-01', '1000000'), $this->bline($this->tenant, '6100', '2026-06', '1500000')]);
        $this->approveVersion($v2['id']);
        $this->assertSame('ACTIVE', DB::table('budget_versions')->where('id', $v1['id'])->value('status')); // approval alone changes nothing

        $this->postJson(self::BG."/budget-versions/{$v2['id']}/activate", ['effective_from' => '2026-01-01'])->assertStatus(422)->assertJsonPath('code', 'BUDGET_VERSION_WINDOW_INVALID');
        $this->postJson(self::BG."/budget-versions/{$v2['id']}/activate", ['effective_from' => '2027-01-01'])->assertStatus(422)->assertJsonPath('code', 'BUDGET_VERSION_WINDOW_INVALID');
        $this->assertSame('ACTIVE', DB::table('budget_versions')->where('id', $v1['id'])->value('status'));

        $this->activateVersion($v2['id'], '2026-06-01');
        $old = DB::table('budget_versions')->where('id', $v1['id'])->first();
        $this->assertSame(['SUPERSEDED', '2026-01-01', '2026-05-31', $v2['id']], [$old->status, $old->effective_from, $old->effective_until, $old->superseded_by_version_id]);
        $this->assertSame('ACTIVE', DB::table('budget_versions')->where('id', $v2['id'])->value('status'));
        $this->assertEquals($linesBefore, DB::table('budget_lines')->where('budget_version_id', $v1['id'])->orderBy('id')->get()->map(fn ($l) => (array) $l)->all());
        $this->assertSame(['DRAFT', 'SUBMITTED', 'APPROVED', 'ACTIVE', 'SUPERSEDED'], DB::table('document_transitions')->where('document_type', 'budget_version')->where('document_id', $v1['id'])->orderBy('occurred_at')->pluck('to_status')->all());
        $this->assertContains('budget.version.superseded', DB::table('audit_logs')->where('resource_id', $v1['id'])->pluck('action')->all());

        $this->postJson(self::BG."/budget-versions/{$v2['id']}/activate")->assertStatus(409)->assertJsonPath('code', 'BUDGET_VERSION_ALREADY_ACTIVE');
        $this->postJson(self::BG."/budget-versions/{$v1['id']}/cancel", ['reason' => 'x'])->assertStatus(409); // history cannot be cancelled away
        $this->putJson(self::BG."/budget-versions/{$v1['id']}/lines", ['lines' => []])->assertStatus(409);
    }

    public function test_the_database_keeps_one_active_version_with_non_overlapping_windows_and_freezes_approved_history(): void
    {
        $made = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '6100', '2026-01', '1000000')]);
        $v1 = $made['version']['id'];
        $budgetId = $made['budget']['id'];

        $this->assertDbRefuses(fn () => DB::table('budget_versions')->where('id', $v1)->update(['label' => 'Diam-diam diubah']));
        $this->assertDbRefuses(fn () => DB::table('budget_versions')->where('id', $v1)->update(['effective_from' => '2026-02-01']));
        $this->assertDbRefuses(fn () => DB::table('budget_versions')->where('id', $v1)->update(['status' => 'DRAFT', 'effective_from' => null]));
        $this->assertDbRefuses(fn () => DB::table('budget_versions')->where('id', $v1)->delete());
        $line = DB::table('budget_lines')->where('budget_version_id', $v1)->first();
        $this->assertDbRefuses(fn () => DB::table('budget_lines')->where('id', $line->id)->update(['amount' => '1.0000']));
        $this->assertDbRefuses(fn () => DB::table('budget_lines')->where('id', $line->id)->delete());
        $this->assertDbRefuses(fn () => DB::table('budget_lines')->insert((array) $line + ['id' => (string) Str::uuid()] + []));

        // Constraints stand on their own: with the guard trigger off, a second active version or an overlapping window is still refused.
        DB::unprepared('ALTER TABLE budget_versions DISABLE TRIGGER budget_versions_guard');
        $second = $this->newVersionRow($budgetId, 2);
        $this->assertDbRefuses(fn () => DB::table('budget_versions')->where('id', $second)->update(['status' => 'ACTIVE', 'effective_from' => '2026-07-01']), 'one_active');
        $this->assertDbRefuses(fn () => DB::table('budget_versions')->where('id', $second)->update(['status' => 'SUPERSEDED', 'effective_from' => '2026-07-01', 'effective_until' => '2026-08-01']), 'no_overlap');
        $this->assertDbRefuses(fn () => DB::table('budget_versions')->where('id', $second)->update(['status' => 'SUPERSEDED', 'effective_from' => '2026-08-01', 'effective_until' => '2026-07-01']), 'window_check');
        DB::unprepared('ALTER TABLE budget_versions ENABLE TRIGGER budget_versions_guard');
    }

    public function test_budget_lines_validate_account_period_dimensions_and_amount_inside_the_tenant(): void
    {
        $b = $this->newBudget($this->tenant);
        $v = $this->newVersion($b['id']);
        $line = $this->bline($this->tenant, '6100', '2026-03', '1000000');
        $path = self::BG."/budget-versions/{$v['id']}/lines";

        $this->postJson($path, ['amount' => '-5'] + $line)->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($path, ['amount' => '10.555'] + $line)->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID'); // beyond the currency scale
        $this->postJson($path, ['account_id' => (string) Str::uuid()] + $line)->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
        $this->postJson($path, ['accounting_period_id' => (string) Str::uuid()] + $line)->assertStatus(422)->assertJsonPath('code', 'BUDGET_PERIOD_INVALID');
        $this->postJson($path, ['branch_id' => (string) Str::uuid()] + $line)->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');

        // A period of another fiscal year is refused by the service and by the database.
        $this->inTenant($this->tenant, function () {
            $calendar = app(FiscalCalendarService::class);
            $calendar->createFiscalYear(['code' => 'FY2027', 'name' => 'Tahun 2027', 'start_date' => '2027-01-01']);
        });
        $next = $this->period($this->tenant, '2027-01')->id;
        $this->postJson($path, ['accounting_period_id' => $next] + $line)->assertStatus(422)->assertJsonPath('code', 'BUDGET_PERIOD_INVALID');
        $this->assertDbRefuses(fn () => DB::table('budget_lines')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'budget_id' => $b['id'], 'budget_version_id' => $v['id'],
            'account_id' => $line['account_id'], 'accounting_period_id' => $next, 'amount' => '1.0000', 'created_at' => now(), 'updated_at' => now(),
        ]), 'fiscal year');

        $added = $this->postJson($path, $line + ['description' => 'Gaji Maret'])->assertCreated()->json();
        $this->assertSame('1000000.0000', $added['lines_total']);
        $this->assertSame('Gaji Maret', $added['lines'][0]['description']);
        $this->postJson($path, $line)->assertStatus(422)->assertJsonPath('code', 'BUDGET_LINE_OVERLAP');

        $id = $added['lines'][0]['id'];
        $this->patchJson("{$path}/{$id}", ['amount' => '1200000.50'])->assertOk()->assertJsonPath('lines_total', '1200000.5000');
        $this->deleteJson("{$path}/{$id}")->assertOk()->assertJsonPath('lines_total', '0.0000');
        $this->assertSame(['budget.version.created', 'budget.version.line_added', 'budget.version.line_updated', 'budget.version.line_removed'], DB::table('audit_logs')->where('resource_id', $v['id'])->orderBy('occurred_at')->pluck('action')->all());
    }

    public function test_lines_of_one_version_never_overlap_by_account_group_or_dimension(): void
    {
        $b = $this->newBudget($this->tenant);
        $v = $this->newVersion($b['id']);
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');

        // A header account (6000 Beban Operasional) covers its descendants: 6100 may not be planned again for the same period/dimensions.
        $group = $this->bline($this->tenant, '6000', '2026-03', '9000000');
        $this->putLines($v['id'], [$group]);
        $this->postJson(self::BG."/budget-versions/{$v['id']}/lines", $this->bline($this->tenant, '6100', '2026-03', '1000000'))->assertStatus(422)->assertJsonPath('code', 'BUDGET_LINE_OVERLAP');
        $this->postJson(self::BG."/budget-versions/{$v['id']}/lines", $this->bline($this->tenant, '6100', '2026-04', '1000000'))->assertCreated(); // another period is fine
        $this->putJson(self::BG."/budget-versions/{$v['id']}/lines", ['lines' => [$group, $this->bline($this->tenant, '6200', '2026-03', '1')]])->assertStatus(422)->assertJsonPath('code', 'BUDGET_LINE_OVERLAP');

        // Same account and period: two different branches coexist, an unsplit line overlaps both.
        $this->putLines($v['id'], [$this->bline($this->tenant, '6100', '2026-03', '10', ['branch_id' => $north]), $this->bline($this->tenant, '6100', '2026-03', '20', ['branch_id' => $south])]);
        $this->postJson(self::BG."/budget-versions/{$v['id']}/lines", $this->bline($this->tenant, '6100', '2026-03', '30'))->assertStatus(422)->assertJsonPath('code', 'BUDGET_LINE_OVERLAP');
        $this->postJson(self::BG."/budget-versions/{$v['id']}/lines", $this->bline($this->tenant, '6100', '2026-03', '30', ['branch_id' => $north]))->assertStatus(422)->assertJsonPath('code', 'BUDGET_LINE_OVERLAP');
        $this->assertDbRefuses(fn () => DB::table('budget_lines')->insert(['id' => (string) Str::uuid()] + (array) DB::table('budget_lines')->where('budget_version_id', $v['id'])->first()), 'natural_unique');
    }

    public function test_a_tenant_cannot_use_accounts_dimensions_budgets_or_versions_of_another_tenant(): void
    {
        $other = $this->budgetTenant('beta');
        $this->signedIn($other);
        $foreignBudget = $this->newBudget($other);
        $foreignVersion = $this->newVersion($foreignBudget['id']);
        [$foreignBranch] = $this->branches($other, 'X');
        $foreignAccount = $this->account($other, '6100')->id;
        $foreignYear = $this->yearId($other);

        $this->signedIn($this->tenant);
        $b = $this->newBudget($this->tenant);
        $v = $this->newVersion($b['id']);

        $this->postJson(self::BG."/budget-versions/{$v['id']}/lines", ['account_id' => $foreignAccount] + $this->bline($this->tenant, '6100', '2026-03', '1'))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
        $this->postJson(self::BG."/budget-versions/{$v['id']}/lines", ['branch_id' => $foreignBranch] + $this->bline($this->tenant, '6100', '2026-03', '1'))->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');
        $this->postJson(self::BG.'/budgets', ['code' => 'FOREIGN', 'name' => 'X', 'fiscal_year_id' => $foreignYear])->assertStatus(422)->assertJsonPath('code', 'FISCAL_YEAR_NOT_FOUND');
        $this->getJson(self::BG."/budgets/{$foreignBudget['id']}")->assertNotFound();
        $this->getJson(self::BG."/budget-versions/{$foreignVersion['id']}")->assertNotFound();
        $this->postJson(self::BG."/budgets/{$foreignBudget['id']}/versions", [])->assertNotFound();
        $this->postJson(self::BG."/budget-versions/{$foreignVersion['id']}/submit")->assertNotFound();
        $this->postJson(self::BG."/budgets/{$b['id']}/versions", ['copy_from_version_id' => $foreignVersion['id']])->assertStatus(422)->assertJsonPath('code', 'BUDGET_VERSION_NOT_FOUND');
        $this->assertCount(1, $this->getJson(self::BG.'/budgets')->assertOk()->json('data'));

        // The database refuses a cross-tenant relation even when the service is bypassed.
        $this->assertDbRefuses(fn () => DB::table('budget_lines')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'budget_id' => $b['id'], 'budget_version_id' => $v['id'],
            'account_id' => $foreignAccount, 'accounting_period_id' => $this->periodId($this->tenant, '2026-03'), 'amount' => '1.0000', 'created_at' => now(), 'updated_at' => now(),
        ]), 'foreign key');
    }

    public function test_a_budget_never_creates_or_changes_a_journal(): void
    {
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '250000', '6100', '1110')]);
        $before = [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('accounting_events')->count(), (string) DB::table('journal_lines')->sum('debit'), DB::table('document_sequences')->count()];

        $made = $this->activeBudget($this->tenant, [$this->bline($this->tenant, '6100', '2026-03', '9999999')]);
        $rev = $this->newVersion($made['budget']['id'], ['copy_from_version_id' => $made['version']['id']]);
        $this->approveVersion($rev['id']);
        $this->activateVersion($rev['id'], '2026-04-01');
        $this->postJson(self::BG."/budgets/{$made['budget']['id']}/close")->assertOk();

        $after = [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('accounting_events')->count(), (string) DB::table('journal_lines')->sum('debit'), DB::table('document_sequences')->count()];
        $this->assertSame($before, $after);
    }

    private function newVersionRow(string $budgetId, int $number): string
    {
        $id = (string) Str::uuid();
        DB::table('budget_versions')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'budget_id' => $budgetId, 'version_number' => $number, 'label' => 'Raw', 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function assertDbRefuses(callable $statement, ?string $contains = null): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            $this->assertSame(true, in_array($e->errorInfo[0] ?? '', ['23514', '23505', '23503', '23P01'], true), $e->getMessage());
            if ($contains !== null) {
                $this->assertStringContainsString($contains, $e->getMessage());
            }

            return;
        }
        $this->fail('The database accepted a statement that must be refused.');
    }
}
