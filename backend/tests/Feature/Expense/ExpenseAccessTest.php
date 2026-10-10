<?php

namespace Tests\Feature\Expense;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch F: permissions, segregation of duties, tenant isolation, data scope and module entitlement of expenses. */
class ExpenseAccessTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha');
    }

    private function entitle(string $module, array $attributes): void
    {
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', Module::query()->where('code', $module)->value('id'))->update($attributes);
        app(AccessCache::class)->touchTenant($this->tenant->id);
    }

    /** @return array{0:object,1:object,2:string} vendor, bank account, category id — prepared by an administrator who may do everything */
    private function world(): array
    {
        $this->signedIn($this->tenant);
        $this->putJson(self::AP.'/profile', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false])->assertOk();
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $category = $this->expenseCategory($this->tenant);
        $this->signedIn($this->tenant);

        return [$vendor, $bank, $category->id];
    }

    public function test_each_step_needs_its_own_permission(): void
    {
        [$vendor, , $category] = $this->world();
        $body = $this->payableExpenseBody($category, $vendor);

        $this->as($this->memberToken($this->tenant, ['accounting.expense.view']))->postJson(self::AP.'/expenses', $body)->assertStatus(403);
        $id = $this->as($this->memberToken($this->tenant, ['accounting.expense.create', 'accounting.expense.view']))->postJson(self::AP.'/expenses', $body)->assertCreated()->json('id');
        $uri = self::AP."/expenses/{$id}";

        $this->as($this->memberToken($this->tenant, ['accounting.expense.create']))->patchJson($uri, ['reference' => 'x'])->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.expense.update']))->patchJson($uri, ['reference' => 'x'])->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.expense.view']))->postJson("{$uri}/submit")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.expense.submit']))->postJson("{$uri}/submit")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.expense.submit', 'accounting.expense.post']))->postJson("{$uri}/approve")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.expense.approve']))->postJson("{$uri}/approve")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.expense.approve']))->postJson("{$uri}/post")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.expense.post']))->postJson("{$uri}/post")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.expense.post']))->postJson("{$uri}/reverse", ['reason' => 'x'])->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.expense.reverse']))->postJson("{$uri}/reverse", ['reason' => 'x'])->assertOk();

        // Reading expenses is not reading invoices, and the other way round.
        $this->as($this->memberToken($this->tenant, ['accounting.ap_invoice.view']))->getJson(self::AP.'/expenses')->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.expense.view']))->getJson(self::AP.'/ap-invoices')->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.expense.view']))->getJson(self::AP.'/expenses')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_a_rejected_expense_returns_to_its_preparer_and_a_cancelled_one_never_posts(): void
    {
        [$vendor, , $category] = $this->world();
        $id = $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor))->assertCreated()->json('id');
        $this->postJson(self::AP."/expenses/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/expenses/{$id}/reject", [])->assertStatus(422); // a reason is mandatory
        $this->postJson(self::AP."/expenses/{$id}/reject", ['reason' => 'Bukti kurang'])->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('reject_reason', 'Bukti kurang');
        $this->postJson(self::AP."/expenses/{$id}/post")->assertStatus(409);
        $this->postJson(self::AP."/expenses/{$id}/reopen")->assertOk()->assertJsonPath('status', 'DRAFT')->assertJsonPath('reject_reason', null);
        $this->patchJson(self::AP."/expenses/{$id}", ['supporting_document' => 'KW-002'])->assertOk();

        $this->postJson(self::AP."/expenses/{$id}/cancel", ['reason' => 'Dobel'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        foreach (['submit', 'approve', 'post'] as $action) {
            $this->postJson(self::AP."/expenses/{$id}/{$action}")->assertStatus(409);
        }
        $this->patchJson(self::AP."/expenses/{$id}", ['description' => 'x'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_NOT_DRAFT');
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'expense']));
    }

    public function test_approval_is_required_by_policy_and_segregation_follows_the_profile_not_role_names(): void
    {
        [$vendor, , $category] = $this->world();
        $preparer = $this->memberToken($this->tenant);
        $approver = $this->memberToken($this->tenant);

        // Approval required: a draft cannot be posted; the creator may not approve their own expense under the default policy.
        $this->putJson(self::AP.'/profile', ['sod_creator_not_approver' => true, 'sod_creator_not_poster' => false, 'approval_required' => true])->assertOk();
        $id = $this->as($preparer)->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor))->assertCreated()->json('id');
        $this->postJson(self::AP."/expenses/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_APPROVAL_REQUIRED');
        $this->postJson(self::AP."/expenses/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/expenses/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->getJson(self::AP."/expenses/{$id}")->assertOk()->assertJsonPath('sod.approve', false)->assertJsonPath('sod.approval_required', true);
        $this->as($approver)->postJson(self::AP."/expenses/{$id}/approve")->assertOk();
        $this->as($preparer)->postJson(self::AP."/expenses/{$id}/post")->assertOk();

        $this->as($preparer)->putJson(self::AP.'/profile', ['sod_creator_not_poster' => true])->assertOk();
        $second = $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor))->assertCreated()->json('id');
        $this->postJson(self::AP."/expenses/{$second}/submit")->assertOk();
        $this->as($approver)->postJson(self::AP."/expenses/{$second}/approve")->assertOk();
        $this->as($preparer)->postJson(self::AP."/expenses/{$second}/post")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->assertSame('APPROVED', DB::table('expenses')->where('id', $second)->value('status'));
        $this->as($approver)->postJson(self::AP."/expenses/{$second}/post")->assertOk();

        // Without the approval requirement a draft posts directly.
        $this->putJson(self::AP.'/profile', ['approval_required' => false, 'sod_creator_not_poster' => false])->assertOk();
        $third = $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor))->assertCreated()->json('id');
        $this->postJson(self::AP."/expenses/{$third}/post")->assertOk()->assertJsonPath('status', 'POSTED');
    }

    public function test_another_tenant_sees_and_changes_nothing(): void
    {
        [$vendor, $bank, $category] = $this->world();
        $posted = $this->postedExpense($this->payableExpenseBody($category, $vendor));
        $draft = $this->postJson(self::AP.'/expenses', $this->paidExpenseBody($category, $bank->id))->assertCreated()->json('id');

        $other = $this->payablesTenant('beta');
        $this->signedIn($other);
        $theirVendor = $this->vendor($other, 'V1');
        $theirBank = $this->cashAccount($other, 'BCA');
        $theirCategory = $this->expenseCategory($other, 'UTIL');
        $this->signedIn($other);

        $this->getJson(self::AP.'/expenses')->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::AP."/expenses/{$posted['id']}")->assertNotFound();
        $this->patchJson(self::AP."/expenses/{$draft}", ['reference' => 'x'])->assertNotFound();
        foreach (['submit', 'approve', 'post', 'cancel', 'reject', 'reopen'] as $action) {
            $this->postJson(self::AP."/expenses/{$draft}/{$action}", ['reason' => 'x'])->assertNotFound();
        }
        $this->postJson(self::AP."/expenses/{$posted['id']}/reverse", ['reason' => 'x'])->assertNotFound();

        // Their own expense cannot name our category, vendor, cash account or classification account.
        $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $theirVendor))->assertStatus(422)->assertJsonPath('code', 'EXPENSE_CATEGORY_INVALID');
        $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($theirCategory->id, $vendor))->assertStatus(422)->assertJsonPath('code', 'VENDOR_REQUIRED');
        $this->postJson(self::AP.'/expenses', $this->paidExpenseBody($theirCategory->id, $bank->id))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $this->postJson(self::AP.'/expenses', $this->paidExpenseBody($theirCategory->id, $theirBank->id, ['vendor_id' => $vendor->id]))->assertStatus(422)->assertJsonPath('code', 'VENDOR_NOT_FOUND');
        $this->postJson(self::AP.'/expenses', $this->paidExpenseBody($theirCategory->id, $theirBank->id, ['account_id' => $this->account($this->tenant, '6300')->id]))->assertStatus(422);
        $this->postJson(self::AP.'/expenses', $this->paidExpenseBody($theirCategory->id, $theirBank->id, ['branch_id' => (string) Str::uuid()]))->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');

        $this->assertSame(['POSTED', 'DRAFT'], DB::table('expenses')->where('tenant_id', $this->tenant->id)->orderBy('created_at')->pluck('status')->all());
        $this->assertSame(0, $this->rows('expenses', ['tenant_id' => $other->id]));
    }

    public function test_a_branch_scoped_user_sees_and_books_only_inside_their_branch(): void
    {
        $this->signedIn($this->tenant);
        $this->putJson(self::AP.'/profile', ['sod_creator_not_approver' => false])->assertOk();
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA-N', 'BANK', '1120', ['branch_id' => $north]);
        $southBank = $this->cashAccount($this->tenant, 'KAS-S', 'CASH', '1110', ['branch_id' => $south]);
        $category = $this->expenseCategory($this->tenant)->id;
        $this->signedIn($this->tenant);
        $inNorth = $this->postedExpense($this->payableExpenseBody($category, $vendor, ['branch_id' => $north]));
        $inSouth = $this->postedExpense($this->payableExpenseBody($category, $vendor, ['branch_id' => $south]));

        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.expense.view', 'accounting.expense.create', 'accounting.expense.reverse'], 'BRANCH', $north));
        $scoped->getJson(self::AP.'/expenses')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $inNorth['id']);
        $scoped->getJson(self::AP."/expenses/{$inSouth['id']}")->assertNotFound();
        $scoped->postJson(self::AP."/expenses/{$inSouth['id']}/reverse", ['reason' => 'x'])->assertNotFound();
        $scoped->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor, ['branch_id' => $south]))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED');
        $scoped->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED'); // no branch: tenant-wide users only
        $scoped->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor, ['branch_id' => $north]))->assertCreated();
        // A directly paid expense takes the branch of its cash box; a box of another branch is invisible to the scoped user.
        $scoped->postJson(self::AP.'/expenses', $this->paidExpenseBody($category, $bank->id))->assertCreated()->assertJsonPath('branch_id', $north);
        $scoped->postJson(self::AP.'/expenses', $this->paidExpenseBody($category, $southBank->id))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $scoped->postJson(self::AP."/expenses/{$inNorth['id']}/reverse", ['reason' => 'Salah cabang'])->assertOk();
    }

    public function test_the_modules_an_expense_touches_must_be_available_for_writing(): void
    {
        [$vendor, $bank, $category] = $this->world();
        $payable = $this->approvedExpense($this->payableExpenseBody($category, $vendor));
        $paid = $this->approvedExpense($this->paidExpenseBody($category, $bank->id));
        $before = $this->glFigures($this->tenant);

        // The payable path creates a payable in AP: AP read-only or disabled stops it, the directly paid path is unaffected.
        $this->entitle('ACCOUNTING_AP', ['state' => 'READ_ONLY']);
        $this->postJson(self::AP."/expenses/{$payable}/post")->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_AVAILABLE')->assertJsonPath('details.module', 'ACCOUNTING_AP');
        $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor))->assertCreated(); // drafts are the Expense module's own business
        $this->entitle('ACCOUNTING_AP', ['state' => 'DISABLED']);
        $this->postJson(self::AP."/expenses/{$payable}/post")->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_AVAILABLE');
        $this->postJson(self::AP."/expenses/{$paid}/post")->assertOk();

        // The directly paid path draws on cash and bank.
        $this->entitle('ACCOUNTING_AP', ['state' => 'ACTIVE']);
        $second = $this->approvedExpense($this->paidExpenseBody($category, $bank->id));
        $this->entitle('ACCOUNTING_CASH_BANK', ['state' => 'READ_ONLY']);
        $this->postJson(self::AP."/expenses/{$second}/post")->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_AVAILABLE')->assertJsonPath('details.module', 'ACCOUNTING_CASH_BANK');
        $this->postJson(self::AP."/expenses/{$payable}/post")->assertOk(); // payable path does not need cash and bank
        $this->entitle('ACCOUNTING_CASH_BANK', ['state' => 'ACTIVE']);
        $this->postJson(self::AP."/expenses/{$second}/post")->assertOk();

        $this->assertSame(['POSTED', 'POSTED', 'POSTED'], DB::table('expenses')->whereIn('id', [$payable, $paid, $second])->pluck('status')->all());
        $this->assertGreaterThan((int) $before->lines, (int) $this->glFigures($this->tenant)->lines);
    }

    public function test_the_expense_module_itself_closes_every_route_when_removed_and_every_mutation_when_read_only(): void
    {
        [$vendor, $bank, $category] = $this->world();
        $posted = $this->postedExpense($this->paidExpenseBody($category, $bank->id));
        $draft = $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor))->assertCreated()->json('id');

        $this->entitle('ACCOUNTING_EXPENSE', ['state' => 'READ_ONLY']);
        $this->getJson(self::AP.'/expenses')->assertOk();
        $this->getJson(self::AP."/expenses/{$posted['id']}")->assertOk();
        $this->getJson(self::AP.'/expense-categories')->assertOk();
        $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($category, $vendor))->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        $this->postJson(self::AP."/expenses/{$draft}/submit")->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        $this->postJson(self::AP."/expenses/{$posted['id']}/reverse", ['reason' => 'x'])->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        $this->postJson(self::AP.'/expense-categories', ['code' => 'NEW', 'name' => 'x'])->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');

        foreach (['SUSPENDED', 'DISABLED'] as $state) {
            $this->entitle('ACCOUNTING_EXPENSE', ['state' => $state]);
            $this->getJson(self::AP.'/expenses')->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
            $this->getJson(self::AP.'/expense-categories')->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
            $this->postJson(self::AP."/expenses/{$draft}/submit")->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
        }
        // The other OA2 modules are unaffected.
        $this->getJson(self::AP.'/ap-invoices')->assertOk();
        $this->getJson(self::AP.'/cash-bank-accounts')->assertOk();

        $this->entitle('ACCOUNTING_EXPENSE', ['state' => 'ACTIVE']);
        $this->entitle('ACCOUNTING_CORE', ['state' => 'READ_ONLY']); // the parent module: nothing may post any more
        $this->postJson(self::AP."/expenses/{$draft}/submit")->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_AVAILABLE')->assertJsonPath('details.module', 'ACCOUNTING_CORE');
        $this->postJson(self::AP."/expenses/{$posted['id']}/reverse", ['reason' => 'x'])->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_AVAILABLE');
        $this->assertSame(['DRAFT', 'POSTED'], [DB::table('expenses')->where('id', $draft)->value('status'), DB::table('expenses')->where('id', $posted['id'])->value('status')]);
    }
}
