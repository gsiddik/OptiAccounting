<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\Models\Account;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA1 batches C-D: tenant-owned chart of accounts, hierarchy rules, templates, cost centers. */
class ChartOfAccountsTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const BASE = '/api/v1/app/accounting/accounts';

    public function test_template_is_copied_into_tenant_owned_accounts_with_role_mappings_once(): void
    {
        $tenant = $this->tenant();
        $client = $this->asMember($tenant);

        $client->getJson('/api/v1/app/accounting/coa-templates')->assertOk()->assertJsonPath('data.0.code', 'UMUM_ID');
        $client->postJson('/api/v1/app/accounting/coa-templates/apply', ['template' => 'NOPE'])->assertNotFound();
        $client->postJson('/api/v1/app/accounting/coa-templates/apply', ['template' => 'UMUM_ID'])->assertCreated()->assertJsonPath('accounts', 38)->assertJsonPath('mappings', 11);
        $client->postJson('/api/v1/app/accounting/coa-templates/apply', ['template' => 'UMUM_ID'])->assertStatus(409)->assertJsonPath('code', 'COA_NOT_EMPTY');

        $this->assertSame(38, $this->rows('accounts', ['tenant_id' => $tenant->id]));
        // Tenant rows are copies: no reference to the template exists on them.
        $this->assertFalse(Schema::hasColumn('accounts', 'coa_template_id'));
        $cash = $this->account($tenant, '1110');
        $this->assertSame('1100', Account::withoutGlobalScopes()->find($cash->parent_id)->code);
        $this->assertSame(1, $this->rows('account_mappings', ['tenant_id' => $tenant->id, 'account_role' => 'CASH', 'account_id' => $cash->id]));
        $this->assertTrue($this->account($tenant, '1130')->is_control);
        $this->assertSame('CREDIT', $this->account($tenant, '1290')->normal_balance); // contra asset
    }

    public function test_account_codes_are_free_form_tenant_data_and_unique_per_tenant(): void
    {
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');
        $a = $this->asMember($alpha);

        // Nothing is inferred from the code prefix: "1-001" may be a liability.
        $a->postJson(self::BASE, ['code' => '1-001', 'name' => 'Utang aneh', 'account_type' => 'LIABILITY'])->assertCreated()->assertJsonPath('normal_balance', 'CREDIT');
        $a->postJson(self::BASE, ['code' => '1-001', 'name' => 'Duplikat', 'account_type' => 'ASSET'])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_CODE_TAKEN');
        $this->asMember($beta)->postJson(self::BASE, ['code' => '1-001', 'name' => 'Beda tenant', 'account_type' => 'ASSET'])->assertCreated();

        $a->postJson(self::BASE, ['code' => 'bad code', 'name' => 'x', 'account_type' => 'ASSET'])->assertStatus(422);
        $a->postJson(self::BASE, ['code' => 'X1', 'name' => 'x', 'account_type' => 'PROFIT'])->assertStatus(422);
        $this->asMember($alpha)->postJson(self::BASE, ['code' => 'X2', 'name' => 'x', 'account_type' => 'ASSET', 'tenant_id' => $beta->id, 'status' => 'INACTIVE'])->assertCreated()
            ->assertJsonPath('tenant_id', $alpha->id)->assertJsonPath('status', 'ACTIVE');
    }

    public function test_hierarchy_rules_parent_must_be_a_same_type_header_in_the_same_tenant(): void
    {
        $alpha = $this->accountingTenant('alpha');
        $beta = $this->accountingTenant('beta');
        $client = $this->asMember($alpha);
        $assets = $this->account($alpha, '1000');
        $cash = $this->account($alpha, '1110');
        $liabilities = $this->account($alpha, '2000');

        $client->postJson(self::BASE, ['code' => '1111', 'name' => 'Kas kecil', 'account_type' => 'ASSET', 'parent_id' => $cash->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_HIERARCHY_INVALID'); // posting account as parent
        $client->postJson(self::BASE, ['code' => '1112', 'name' => 'Salah tipe', 'account_type' => 'ASSET', 'parent_id' => $liabilities->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_HIERARCHY_INVALID');
        $client->postJson(self::BASE, ['code' => '1113', 'name' => 'Induk lintas tenant', 'account_type' => 'ASSET', 'parent_id' => $this->account($beta, '1000')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_PARENT_NOT_FOUND');
        $client->postJson(self::BASE, ['code' => '1114', 'name' => 'Ok', 'account_type' => 'ASSET', 'parent_id' => $assets->id])->assertCreated()->assertJsonPath('parent_id', $assets->id);
        $this->assertSame(0, $this->rows('accounts', ['tenant_id' => $alpha->id, 'code' => '1113']));
    }

    public function test_cycles_and_self_parents_are_refused_by_the_service_and_the_database(): void
    {
        $tenant = $this->accountingTenant();
        $client = $this->asMember($tenant);
        $assets = $this->account($tenant, '1000');
        $current = $this->account($tenant, '1100'); // child of 1000

        $client->patchJson(self::BASE."/{$assets->id}", ['parent_id' => $current->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_HIERARCHY_CYCLE');
        $client->patchJson(self::BASE."/{$assets->id}", ['parent_id' => $assets->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_HIERARCHY_CYCLE');

        try { // the trigger is the second line of defence
            DB::transaction(fn () => DB::table('accounts')->where('id', $assets->id)->update(['parent_id' => $current->id]));
            $this->fail('cycle must be refused by the database');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cycle', $e->getMessage());
        }
        $this->assertNull(DB::table('accounts')->where('id', $assets->id)->value('parent_id'));
    }

    public function test_a_header_with_children_cannot_become_a_posting_account(): void
    {
        $tenant = $this->accountingTenant();
        $client = $this->asMember($tenant);
        $client->patchJson(self::BASE.'/'.$this->account($tenant, '1100')->id, ['is_postable' => true])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_HIERARCHY_INVALID');
        $client->postJson(self::BASE, ['code' => '9000', 'name' => 'Header', 'account_type' => 'ASSET', 'is_postable' => false, 'is_control' => true])->assertStatus(422); // control must be postable (database check)
    }

    public function test_status_rules_inactive_accounts_leave_posting_and_children_block_deactivation(): void
    {
        $tenant = $this->accountingTenant();
        $client = $this->asMember($tenant);
        $current = $this->account($tenant, '1100');
        $cash = $this->account($tenant, '1110');

        $client->postJson(self::BASE."/{$current->id}/status", ['status' => 'INACTIVE'])->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_HAS_ACTIVE_CHILDREN');
        $client->postJson(self::BASE."/{$cash->id}/status", ['status' => 'INACTIVE'])->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_MAPPED'); // CASH role points at it

        $petty = $client->postJson(self::BASE, ['code' => '1170', 'name' => 'Kas kecil', 'account_type' => 'ASSET', 'parent_id' => $current->id])->assertCreated()->json('id');
        $client->postJson(self::BASE."/{$petty}/status", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $client->postJson(self::BASE."/{$petty}/status", ['status' => 'ACTIVE'])->assertOk();
        $this->assertSame(2, $this->rows('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'accounting.account.status_changed']));
    }

    public function test_unused_accounts_can_be_deleted_but_used_ones_keep_their_meaning(): void
    {
        $tenant = $this->accountingTenant();
        $client = $this->asMember($tenant);
        $id = $client->postJson(self::BASE, ['code' => '7000', 'name' => 'Sementara', 'account_type' => 'EXPENSE'])->assertCreated()->json('id');
        $client->deleteJson(self::BASE."/{$id}")->assertNoContent();
        $this->assertSame(0, $this->rows('accounts', ['id' => $id]));

        $cash = $this->account($tenant, '1110');
        $this->insertPostedLine($tenant, $cash);

        $client->deleteJson(self::BASE."/{$cash->id}")->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_IN_USE');
        $client->patchJson(self::BASE."/{$cash->id}", ['account_type' => 'EXPENSE'])->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_IN_USE');
        $client->patchJson(self::BASE."/{$cash->id}", ['normal_balance' => 'CREDIT'])->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_IN_USE');
        $client->patchJson(self::BASE."/{$cash->id}", ['name' => 'Kas besar'])->assertOk()->assertJsonPath('name', 'Kas besar'); // renaming keeps history resolvable by id

        try { // and the database refuses the same change without the service
            DB::transaction(fn () => DB::table('accounts')->where('id', $cash->id)->update(['normal_balance' => 'CREDIT']));
            $this->fail('history guard');
        } catch (QueryException $e) {
            $this->assertStringContainsString('journal history', $e->getMessage());
        }
    }

    public function test_search_and_filters_use_the_tenant_scope(): void
    {
        $alpha = $this->accountingTenant('alpha');
        $this->accountingTenant('beta');
        $client = $this->asMember($alpha);

        $this->assertCount(38, $client->getJson(self::BASE)->json('data'));
        $this->assertSame(['1110'], collect($client->getJson(self::BASE.'?q=kas')->json('data'))->pluck('code')->all());
        $this->assertCount(9, collect($client->getJson(self::BASE.'?type=LIABILITY')->json('data'))->push(1)->all()); // 8 liability accounts + marker
        $client->getJson(self::BASE.'?type=BOGUS')->assertStatus(422);
    }

    public function test_cross_tenant_account_ids_are_not_found(): void
    {
        $alpha = $this->accountingTenant('alpha');
        $beta = $this->accountingTenant('beta');
        $client = $this->asMember($beta);
        $foreign = $this->account($alpha, '1110');

        $client->patchJson(self::BASE."/{$foreign->id}", ['name' => 'hijack'])->assertNotFound();
        $client->postJson(self::BASE."/{$foreign->id}/status", ['status' => 'INACTIVE'])->assertNotFound();
        $client->deleteJson(self::BASE."/{$foreign->id}")->assertNotFound();
        $this->assertSame('Kas', $this->account($alpha, '1110')->name);
    }

    public function test_cost_centers_are_tenant_owned_and_tied_to_the_tenants_own_organization(): void
    {
        $alpha = $this->tenant('alpha');
        $beta = $this->tenant('beta');
        $branch = $this->inTenant($alpha, fn () => app(OrganizationService::class)->createBranch($alpha->id, ['code' => 'JKT', 'name' => 'Jakarta']));
        $foreignBranch = $this->inTenant($beta, fn () => app(OrganizationService::class)->createBranch($beta->id, ['code' => 'SBY', 'name' => 'Surabaya']));
        $client = $this->asMember($alpha);

        $client->getJson('/api/v1/app/accounting/dimension-types')->assertOk()->assertJsonCount(3, 'data');
        $id = $client->postJson('/api/v1/app/accounting/cost-centers', ['code' => 'OPS', 'name' => 'Operasional', 'branch_id' => $branch->id])->assertCreated()->json('id');
        $client->postJson('/api/v1/app/accounting/cost-centers', ['code' => 'OPS', 'name' => 'Duplikat'])->assertStatus(422)->assertJsonPath('code', 'COST_CENTER_CODE_TAKEN');
        $client->postJson('/api/v1/app/accounting/cost-centers', ['code' => 'X', 'name' => 'Lintas tenant', 'branch_id' => $foreignBranch->id])->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');
        $client->patchJson("/api/v1/app/accounting/cost-centers/{$id}", ['name' => 'Operasional Jakarta'])->assertOk()->assertJsonPath('name', 'Operasional Jakarta');
        $client->postJson("/api/v1/app/accounting/cost-centers/{$id}/status", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->assertCount(1, $client->getJson('/api/v1/app/accounting/cost-centers')->json('data'));

        $this->asMember($beta)->patchJson("/api/v1/app/accounting/cost-centers/{$id}", ['name' => 'hijack'])->assertNotFound();
        $this->assertCount(0, $this->asMember($beta)->getJson('/api/v1/app/accounting/cost-centers')->json('data'));
    }

    public function test_coa_permissions_and_entitlement_are_enforced_by_the_gate(): void
    {
        $tenant = $this->accountingTenant();
        $viewer = $this->asMember($tenant, ['accounting.coa.view']);
        $viewer->getJson(self::BASE)->assertOk();
        $viewer->postJson(self::BASE, ['code' => 'Z', 'name' => 'z', 'account_type' => 'ASSET'])->assertStatus(403);
        $this->asMember($tenant, ['organization.view'])->getJson(self::BASE)->assertStatus(403);
    }

    /** Inserts a posted-looking line directly (the journal batch has its own service tests) so the account has history. */
    private function insertPostedLine(Tenant $tenant, Account $account): void
    {
        $user = $this->member($tenant)[0];
        $period = $this->period($tenant, '2026-01');
        $journalId = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $journalId, 'tenant_id' => $tenant->id, 'journal_type' => 'MANUAL', 'status' => 'DRAFT', 'document_date' => '2026-01-05', 'posting_date' => '2026-01-05',
            'description' => 'fixture', 'currency' => 'IDR', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $journalId, 'line_number' => 1, 'account_id' => $account->id,
            'debit' => 10, 'credit' => 0, 'transaction_currency' => 'IDR', 'transaction_debit' => 10, 'transaction_credit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        unset($period);
    }
}
