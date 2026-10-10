<?php

namespace Tests\Feature\Receivables;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\TestCase;

/** OA2 batch D: permissions, segregation of duties, tenant isolation, data scope and audit of customer receipts. */
class CustomerReceiptAccessTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->receivablesTenant('alpha');
    }

    /** @return array{0:object,1:object,2:array<string,mixed>} customer, bank account, posted invoice (prepared by an administrator who may do everything) */
    private function world(): array
    {
        $this->signedIn($this->tenant);
        $this->putJson(self::AR.'/profile', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false])->assertOk();
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);

        return [$customer, $bank, $this->postedArInvoice($customer, ['lines' => [['description' => 'Jasa', 'amount' => '1000000']]])];
    }

    public function test_each_step_needs_its_own_permission(): void
    {
        [$customer, $bank, $invoice] = $this->world();
        $body = $this->receiptBody($customer, $bank->id, [$invoice['id'] => '100000']);

        $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.view']))->postJson(self::AR.'/customer-receipts', $body)->assertStatus(403);
        $id = $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.create', 'accounting.ar_receipt.view']))->postJson(self::AR.'/customer-receipts', $body)->assertCreated()->json('id');
        $uri = self::AR."/customer-receipts/{$id}";

        $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.view']))->postJson("{$uri}/submit")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.submit']))->postJson("{$uri}/submit")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.submit', 'accounting.ar_receipt.post']))->postJson("{$uri}/approve")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.approve']))->postJson("{$uri}/approve")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.approve']))->postJson("{$uri}/post")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.post']))->postJson("{$uri}/post")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.post']))->postJson("{$uri}/reverse", ['reason' => 'x'])->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.ar_receipt.reverse']))->postJson("{$uri}/reverse", ['reason' => 'x'])->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.ar_invoice.view']))->getJson(self::AR.'/customer-receipts')->assertStatus(403);
    }

    public function test_segregation_of_duties_follows_the_profile_policy_not_role_names(): void
    {
        [$customer, $bank, $invoice] = $this->world();
        $this->putJson(self::AR.'/profile', ['sod_creator_not_approver' => true, 'sod_creator_not_poster' => false])->assertOk();
        $preparer = $this->memberToken($this->tenant);
        $approver = $this->memberToken($this->tenant);

        $id = $this->as($preparer)->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$invoice['id'] => '100000']))->assertCreated()->json('id');
        $this->postJson(self::AR."/customer-receipts/{$id}/submit")->assertOk();
        $this->postJson(self::AR."/customer-receipts/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->getJson(self::AR."/customer-receipts/{$id}")->assertOk()->assertJsonPath('sod.approve', false);
        $this->as($approver)->postJson(self::AR."/customer-receipts/{$id}/approve")->assertOk();
        $this->as($preparer)->postJson(self::AR."/customer-receipts/{$id}/post")->assertOk(); // posting by the creator is allowed under this policy

        $this->as($preparer)->putJson(self::AR.'/profile', ['sod_creator_not_poster' => true])->assertOk();
        $second = $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$invoice['id'] => '100000']))->assertCreated()->json('id');
        $this->postJson(self::AR."/customer-receipts/{$second}/submit")->assertOk();
        $this->as($approver)->postJson(self::AR."/customer-receipts/{$second}/approve")->assertOk();
        $this->as($preparer)->postJson(self::AR."/customer-receipts/{$second}/post")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->as($approver)->postJson(self::AR."/customer-receipts/{$second}/post")->assertOk();
    }

    public function test_another_tenant_sees_and_changes_nothing(): void
    {
        [$customer, $bank, $invoice] = $this->world();
        $receipt = $this->postedReceipt($customer, $bank->id, [$invoice['id'] => '100000']);
        $draft = $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$invoice['id'] => '50000']))->assertCreated()->json('id');

        $other = $this->receivablesTenant('beta');
        $this->signedIn($other);
        $theirCustomer = $this->customer($other, 'V1');
        $theirBank = $this->cashAccount($other, 'BCA');
        $this->signedIn($other);
        $theirInvoice = $this->putJson(self::AR.'/profile', ['sod_creator_not_approver' => false])->assertOk() && true ? $this->postedArInvoice($theirCustomer) : null;

        $this->getJson(self::AR.'/customer-receipts')->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::AR."/customer-receipts/{$receipt['id']}")->assertNotFound();
        $this->patchJson(self::AR."/customer-receipts/{$draft}", ['reference' => 'x'])->assertNotFound();
        foreach (['submit', 'approve', 'post', 'cancel', 'reject', 'reopen'] as $action) {
            $this->postJson(self::AR."/customer-receipts/{$draft}/{$action}", ['reason' => 'x'])->assertNotFound();
        }
        $this->postJson(self::AR."/customer-receipts/{$receipt['id']}/reverse", ['reason' => 'x'])->assertNotFound();
        $this->getJson(self::AR."/customers/{$customer->id}/open-invoices")->assertNotFound();

        // Their own receipt cannot name our customer, our bank account or our invoice.
        $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $theirBank->id, [$theirInvoice['id'] => '1000']))->assertStatus(422)->assertJsonPath('code', 'CUSTOMER_NOT_FOUND');
        $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($theirCustomer, $bank->id, [$theirInvoice['id'] => '1000']))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($theirCustomer, $theirBank->id, [$invoice['id'] => '1000']))->assertStatus(422)->assertJsonPath('code', 'AR_INVOICE_NOT_FOUND');

        $this->assertSame('POSTED', DB::table('customer_receipts')->where('id', $receipt['id'])->value('status'));
        $this->assertSame('DRAFT', DB::table('customer_receipts')->where('id', $draft)->value('status'));
    }

    public function test_a_branch_scoped_user_sees_and_pays_only_inside_their_branch(): void
    {
        $this->signedIn($this->tenant);
        $this->putJson(self::AR.'/profile', ['sod_creator_not_approver' => false])->assertOk();
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA-N', 'BANK', '1120', ['branch_id' => $north]);
        $southBank = $this->cashAccount($this->tenant, 'KAS-S', 'CASH', '1110', ['branch_id' => $south]);
        $this->signedIn($this->tenant);
        $inNorth = $this->postedArInvoice($customer, ['branch_id' => $north]);
        $inSouth = $this->postedArInvoice($customer, ['branch_id' => $south]);
        $northReceipt = $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$inNorth['id'] => '1000'], ['branch_id' => $north]))->assertCreated()->json('id');
        $southReceipt = $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $southBank->id, [$inSouth['id'] => '1000'], ['branch_id' => $south]))->assertCreated()->json('id');

        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.ar_receipt.view', 'accounting.ar_receipt.create', 'accounting.ar_receipt.submit', 'accounting.ar_invoice.view'], 'BRANCH', $north));
        $scoped->getJson(self::AR.'/customer-receipts')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $northReceipt);
        $scoped->getJson(self::AR."/customer-receipts/{$southReceipt}")->assertNotFound();
        $scoped->postJson(self::AR."/customer-receipts/{$southReceipt}/submit")->assertNotFound();
        $scoped->getJson(self::AR."/customers/{$customer->id}/open-invoices")->assertOk()->assertJsonCount(1, 'data');
        $scoped->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$inSouth['id'] => '1000'], ['branch_id' => $north]))->assertStatus(422)->assertJsonPath('code', 'AR_INVOICE_NOT_FOUND');
        $scoped->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$inNorth['id'] => '1000'], ['branch_id' => $south]))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED');
        $scoped->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$inNorth['id'] => '1000'], ['branch_id' => $north]))->assertCreated();
        $scoped->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $southBank->id, [$inNorth['id'] => '1000'], ['branch_id' => $north]))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND'); // a cash box of another branch is invisible
    }

    public function test_the_lifecycle_and_the_allocations_are_audited(): void
    {
        [$customer, $bank, $invoice] = $this->world();
        $receipt = $this->postedReceipt($customer, $bank->id, [$invoice['id'] => '100000']);
        $this->postJson(self::AR."/customer-receipts/{$receipt['id']}/reverse", ['reason' => 'Salah rekening'])->assertOk();

        $actions = DB::table('audit_logs')->where('resource_type', 'customer_receipt')->where('resource_id', $receipt['id'])->pluck('action')->all();
        foreach (['created', 'submitted', 'approved', 'posted', 'allocated', 'reversed', 'allocation_released'] as $suffix) {
            $this->assertContains("receivables.customer_receipt.{$suffix}", $actions);
        }
        $history = $this->getJson(self::AR."/customer-receipts/{$receipt['id']}")->assertOk()->json('transitions');
        $this->assertSame(['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED'], array_column($history, 'to_status'));
        $this->assertStringNotContainsString('account_number', json_encode(DB::table('audit_logs')->where('resource_id', $receipt['id'])->get()));
    }
}
