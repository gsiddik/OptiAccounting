<?php

namespace Tests\Feature\Payables;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch D: permissions, segregation of duties, tenant isolation, data scope and audit of vendor payments. */
class VendorPaymentAccessTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha');
    }

    /** @return array{0:object,1:object,2:array<string,mixed>} vendor, bank account, posted invoice (prepared by an administrator who may do everything) */
    private function world(): array
    {
        $this->signedIn($this->tenant);
        $this->putJson(self::AP.'/profile', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false])->assertOk();
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);

        return [$vendor, $bank, $this->postedInvoice($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000']]])];
    }

    public function test_each_step_needs_its_own_permission(): void
    {
        [$vendor, $bank, $invoice] = $this->world();
        $body = $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000']);

        $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.view']))->postJson(self::AP.'/vendor-payments', $body)->assertStatus(403);
        $id = $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.create', 'accounting.ap_payment.view']))->postJson(self::AP.'/vendor-payments', $body)->assertCreated()->json('id');
        $uri = self::AP."/vendor-payments/{$id}";

        $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.view']))->postJson("{$uri}/submit")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.submit']))->postJson("{$uri}/submit")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.submit', 'accounting.ap_payment.post']))->postJson("{$uri}/approve")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.approve']))->postJson("{$uri}/approve")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.approve']))->postJson("{$uri}/post")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.post']))->postJson("{$uri}/post")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.post']))->postJson("{$uri}/reverse", ['reason' => 'x'])->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.ap_payment.reverse']))->postJson("{$uri}/reverse", ['reason' => 'x'])->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.ap_invoice.view']))->getJson(self::AP.'/vendor-payments')->assertStatus(403);
    }

    public function test_segregation_of_duties_follows_the_profile_policy_not_role_names(): void
    {
        [$vendor, $bank, $invoice] = $this->world();
        $this->putJson(self::AP.'/profile', ['sod_creator_not_approver' => true, 'sod_creator_not_poster' => false])->assertOk();
        $preparer = $this->memberToken($this->tenant);
        $approver = $this->memberToken($this->tenant);

        $id = $this->as($preparer)->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000']))->assertCreated()->json('id');
        $this->postJson(self::AP."/vendor-payments/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->getJson(self::AP."/vendor-payments/{$id}")->assertOk()->assertJsonPath('sod.approve', false);
        $this->as($approver)->postJson(self::AP."/vendor-payments/{$id}/approve")->assertOk();
        $this->as($preparer)->postJson(self::AP."/vendor-payments/{$id}/post")->assertOk(); // posting by the creator is allowed under this policy

        $this->as($preparer)->putJson(self::AP.'/profile', ['sod_creator_not_poster' => true])->assertOk();
        $second = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000']))->assertCreated()->json('id');
        $this->postJson(self::AP."/vendor-payments/{$second}/submit")->assertOk();
        $this->as($approver)->postJson(self::AP."/vendor-payments/{$second}/approve")->assertOk();
        $this->as($preparer)->postJson(self::AP."/vendor-payments/{$second}/post")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->as($approver)->postJson(self::AP."/vendor-payments/{$second}/post")->assertOk();
    }

    public function test_another_tenant_sees_and_changes_nothing(): void
    {
        [$vendor, $bank, $invoice] = $this->world();
        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '100000']);
        $draft = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '50000']))->assertCreated()->json('id');

        $other = $this->payablesTenant('beta');
        $this->signedIn($other);
        $theirVendor = $this->vendor($other, 'V1');
        $theirBank = $this->cashAccount($other, 'BCA');
        $this->signedIn($other);
        $theirInvoice = $this->putJson(self::AP.'/profile', ['sod_creator_not_approver' => false])->assertOk() && true ? $this->postedInvoice($theirVendor) : null;

        $this->getJson(self::AP.'/vendor-payments')->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::AP."/vendor-payments/{$payment['id']}")->assertNotFound();
        $this->patchJson(self::AP."/vendor-payments/{$draft}", ['reference' => 'x'])->assertNotFound();
        foreach (['submit', 'approve', 'post', 'cancel', 'reject', 'reopen'] as $action) {
            $this->postJson(self::AP."/vendor-payments/{$draft}/{$action}", ['reason' => 'x'])->assertNotFound();
        }
        $this->postJson(self::AP."/vendor-payments/{$payment['id']}/reverse", ['reason' => 'x'])->assertNotFound();
        $this->getJson(self::AP."/vendors/{$vendor->id}/open-invoices")->assertNotFound();

        // Their own payment cannot name our vendor, our bank account or our invoice.
        $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $theirBank->id, [$theirInvoice['id'] => '1000']))->assertStatus(422)->assertJsonPath('code', 'VENDOR_NOT_FOUND');
        $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($theirVendor, $bank->id, [$theirInvoice['id'] => '1000']))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($theirVendor, $theirBank->id, [$invoice['id'] => '1000']))->assertStatus(422)->assertJsonPath('code', 'AP_INVOICE_NOT_FOUND');

        $this->assertSame('POSTED', DB::table('vendor_payments')->where('id', $payment['id'])->value('status'));
        $this->assertSame('DRAFT', DB::table('vendor_payments')->where('id', $draft)->value('status'));
    }

    public function test_a_branch_scoped_user_sees_and_pays_only_inside_their_branch(): void
    {
        $this->signedIn($this->tenant);
        $this->putJson(self::AP.'/profile', ['sod_creator_not_approver' => false])->assertOk();
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA-N', 'BANK', '1120', ['branch_id' => $north]);
        $southBank = $this->cashAccount($this->tenant, 'KAS-S', 'CASH', '1110', ['branch_id' => $south]);
        $this->signedIn($this->tenant);
        $inNorth = $this->postedInvoice($vendor, ['branch_id' => $north]);
        $inSouth = $this->postedInvoice($vendor, ['branch_id' => $south]);
        $northPayment = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$inNorth['id'] => '1000'], ['branch_id' => $north]))->assertCreated()->json('id');
        $southPayment = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $southBank->id, [$inSouth['id'] => '1000'], ['branch_id' => $south]))->assertCreated()->json('id');

        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.ap_payment.view', 'accounting.ap_payment.create', 'accounting.ap_payment.submit', 'accounting.ap_invoice.view'], 'BRANCH', $north));
        $scoped->getJson(self::AP.'/vendor-payments')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $northPayment);
        $scoped->getJson(self::AP."/vendor-payments/{$southPayment}")->assertNotFound();
        $scoped->postJson(self::AP."/vendor-payments/{$southPayment}/submit")->assertNotFound();
        $scoped->getJson(self::AP."/vendors/{$vendor->id}/open-invoices")->assertOk()->assertJsonCount(1, 'data');
        $scoped->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$inSouth['id'] => '1000'], ['branch_id' => $north]))->assertStatus(422)->assertJsonPath('code', 'AP_INVOICE_NOT_FOUND');
        $scoped->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$inNorth['id'] => '1000'], ['branch_id' => $south]))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED');
        $scoped->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$inNorth['id'] => '1000'], ['branch_id' => $north]))->assertCreated();
        $scoped->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $southBank->id, [$inNorth['id'] => '1000'], ['branch_id' => $north]))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND'); // a cash box of another branch is invisible
    }

    public function test_the_lifecycle_and_the_allocations_are_audited(): void
    {
        [$vendor, $bank, $invoice] = $this->world();
        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '100000']);
        $this->postJson(self::AP."/vendor-payments/{$payment['id']}/reverse", ['reason' => 'Salah rekening'])->assertOk();

        $actions = DB::table('audit_logs')->where('resource_type', 'vendor_payment')->where('resource_id', $payment['id'])->pluck('action')->all();
        foreach (['created', 'submitted', 'approved', 'posted', 'allocated', 'reversed', 'allocation_released'] as $suffix) {
            $this->assertContains("payables.vendor_payment.{$suffix}", $actions);
        }
        $history = $this->getJson(self::AP."/vendor-payments/{$payment['id']}")->assertOk()->json('transitions');
        $this->assertSame(['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED'], array_column($history, 'to_status'));
        $this->assertStringNotContainsString('account_number', json_encode(DB::table('audit_logs')->where('resource_id', $payment['id'])->get()));
    }
}
