<?php

namespace Tests\Feature\Expense;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch F: expense categories — a tenant's own classification, validated against the chart of accounts and the account roles. */
class ExpenseCategoryTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->signedIn($this->tenant);
    }

    public function test_a_category_is_created_updated_deactivated_and_audited(): void
    {
        $created = $this->postJson(self::AP.'/expense-categories', ['code' => 'util', 'name' => 'Utilitas', 'account_id' => $this->account($this->tenant, '6300')->id])
            ->assertCreated()->assertJsonPath('code', 'UTIL')->assertJsonPath('status', 'ACTIVE')->assertJsonPath('account.code', '6300')->json();

        $this->patchJson(self::AP."/expense-categories/{$created['id']}", ['name' => 'Utilitas kantor'])->assertOk()
            ->assertJsonPath('name', 'Utilitas kantor')->assertJsonPath('account.code', '6300');
        $this->patchJson(self::AP."/expense-categories/{$created['id']}", ['account_role' => 'EXPENSE'])->assertOk()
            ->assertJsonPath('account_role', 'EXPENSE')->assertJsonPath('account_id', null); // naming a role replaces the account
        $this->postJson(self::AP."/expense-categories/{$created['id']}/status", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->getJson(self::AP.'/expense-categories?status=ACTIVE')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::AP.'/expense-categories?q=util')->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(
            ['expense.category.created', 'expense.category.updated', 'expense.category.updated', 'expense.category.status_changed'],
            DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('resource_id', $created['id'])->orderBy('occurred_at')->pluck('action')->all(),
        );
    }

    public function test_the_code_is_unique_per_tenant_and_the_standard_set_is_applied_once(): void
    {
        $this->postJson(self::AP.'/expense-categories', ['code' => 'UTIL', 'name' => 'A'])->assertCreated();
        $this->postJson(self::AP.'/expense-categories', ['code' => 'util', 'name' => 'B'])->assertStatus(422)->assertJsonPath('code', 'EXPENSE_CATEGORY_CODE_TAKEN');

        $created = $this->postJson(self::AP.'/expense-categories/defaults')->assertCreated()->json('created');
        $this->assertCount(7, $created);
        $this->assertCount(8, DB::table('expense_categories')->where('tenant_id', $this->tenant->id)->get());
        $this->postJson(self::AP.'/expense-categories/defaults')->assertCreated()->assertJsonPath('created', []);

        // Another tenant may use the same code.
        $other = $this->payablesTenant('beta');
        $this->signedIn($other);
        $this->postJson(self::AP.'/expense-categories', ['code' => 'UTIL', 'name' => 'Utilitas'])->assertCreated();
    }

    public function test_a_category_classifies_only_to_a_usable_account_or_an_allowed_role(): void
    {
        $uri = self::AP.'/expense-categories';
        // an account: tenant-owned, active, postable, of an expense/asset type, not a control account
        $this->postJson($uri, ['code' => 'A1', 'name' => 'x', 'account_id' => $this->account($this->tenant, '2110')->id])->assertStatus(422); // payable control account
        $this->postJson($uri, ['code' => 'A2', 'name' => 'x', 'account_id' => $this->account($this->tenant, '4100')->id])->assertStatus(422); // revenue
        $this->postJson($uri, ['code' => 'A3', 'name' => 'x', 'account_id' => $this->account($this->tenant, '6000')->id])->assertStatus(422); // a non-postable header
        $this->postJson($uri, ['code' => 'A4', 'name' => 'x', 'account_id' => (string) Str::uuid()])->assertStatus(422);
        // a role: mapped, active, and not one that belongs to a subledger or to cash and bank
        foreach (['ACCOUNTS_PAYABLE', 'CASH_BANK_ACCOUNT', 'DOCUMENT_ACCOUNT', 'NO_SUCH_ROLE'] as $role) {
            $this->postJson($uri, ['code' => 'R'.substr($role, 0, 3), 'name' => 'x', 'account_role' => $role])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_ROLE_INVALID');
        }
        $this->postJson($uri, ['code' => 'BOTH', 'name' => 'x', 'account_role' => 'EXPENSE', 'account_id' => $this->account($this->tenant, '6300')->id])
            ->assertStatus(422)->assertJsonPath('code', 'EXPENSE_CATEGORY_DESTINATION_AMBIGUOUS');
        $this->assertSame(0, DB::table('expense_categories')->where('tenant_id', $this->tenant->id)->count());

        $this->postJson($uri, ['code' => 'OK', 'name' => 'x', 'account_role' => 'EXPENSE'])->assertCreated();

        // A foreign tenant's account looks missing.
        $other = $this->payablesTenant('beta');
        $foreign = $this->account($other, '6300')->id;
        $this->signedIn($this->tenant);
        $this->postJson($uri, ['code' => 'FOREIGN', 'name' => 'x', 'account_id' => $foreign])->assertStatus(422);
    }

    public function test_a_used_category_is_deactivated_never_deleted_and_an_unused_one_can_go(): void
    {
        $vendor = $this->vendor($this->tenant);
        $used = $this->expenseCategory($this->tenant, 'USED');
        $free = $this->expenseCategory($this->tenant, 'FREE');
        $id = $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($used->id, $vendor))->assertCreated()->json('id');

        $this->deleteJson(self::AP."/expense-categories/{$used->id}")->assertStatus(409)->assertJsonPath('code', 'EXPENSE_CATEGORY_IN_USE');
        $this->deleteJson(self::AP."/expense-categories/{$free->id}")->assertNoContent();
        $this->assertSame(1, DB::table('expense_categories')->where('tenant_id', $this->tenant->id)->count());

        // An inactive category cannot start a new expense, and stops a draft that already uses it from being submitted.
        $this->postJson(self::AP."/expense-categories/{$used->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($used->id, $vendor))->assertStatus(422)->assertJsonPath('code', 'EXPENSE_CATEGORY_INACTIVE');
        $this->postJson(self::AP."/expenses/{$id}/submit")->assertStatus(422)->assertJsonPath('code', 'EXPENSE_CATEGORY_INACTIVE');
        $this->postJson(self::AP."/expense-categories/{$used->id}/status", ['status' => 'ACTIVE'])->assertOk();
        $this->postJson(self::AP."/expenses/{$id}/submit")->assertOk();
    }

    public function test_reading_and_managing_categories_are_separate_permissions_and_tenants_are_isolated(): void
    {
        $category = $this->expenseCategory($this->tenant, 'UTIL');
        $reader = $this->memberToken($this->tenant, ['accounting.expense.view']);
        $manager = $this->memberToken($this->tenant, ['accounting.expense_category.manage']);

        $this->as($reader)->getJson(self::AP.'/expense-categories')->assertOk()->assertJsonCount(1, 'data');
        $this->as($reader)->postJson(self::AP.'/expense-categories', ['code' => 'X', 'name' => 'x'])->assertStatus(403);
        $this->as($reader)->patchJson(self::AP."/expense-categories/{$category->id}", ['name' => 'x'])->assertStatus(403);
        $this->as($reader)->deleteJson(self::AP."/expense-categories/{$category->id}")->assertStatus(403);
        $this->as($manager)->getJson(self::AP.'/expense-categories')->assertStatus(403);
        $this->as($manager)->patchJson(self::AP."/expense-categories/{$category->id}", ['name' => 'Baru'])->assertOk();

        $other = $this->payablesTenant('beta');
        $this->signedIn($other);
        $this->getJson(self::AP.'/expense-categories')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson(self::AP."/expense-categories/{$category->id}", ['name' => 'x'])->assertNotFound();
        $this->postJson(self::AP."/expense-categories/{$category->id}/status", ['status' => 'INACTIVE'])->assertNotFound();
        $this->deleteJson(self::AP."/expense-categories/{$category->id}")->assertNotFound();
        $this->assertSame('Baru', DB::table('expense_categories')->where('id', $category->id)->value('name'));
    }
}
