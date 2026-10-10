<?php

namespace Tests\Feature\Payables;

use App\Domain\Accounting\Services\CostCenterService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use App\Domain\Payables\Models\PaymentTerm;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch B: vendor invoice drafts, totals, validation, duplicate control and the document lifecycle. */
class ApInvoiceLifecycleTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false]);
    }

    public function test_a_draft_is_recalculated_by_the_server_and_takes_its_due_date_from_the_vendor_term(): void
    {
        $client = $this->signedIn($this->tenant);
        $term = $this->inTenant($this->tenant, fn () => PaymentTerm::query()->where('code', 'NET30')->value('id'));
        $vendor = $this->vendor($this->tenant, 'V1', ['payment_term_id' => $term]);

        $invoice = $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, [
            'vendor_invoice_number' => ' inv-0001 ', 'discount_amount' => '50000', 'tax_amount' => '110000', 'other_charges_amount' => '10000',
            'total_amount' => '1', 'subtotal_amount' => '1', 'status' => 'POSTED', 'document_number' => 'HACK-1', 'tenant_id' => 'x',
            'lines' => [
                ['description' => 'Kertas A4', 'quantity' => '10', 'unit_price' => '25000.50', 'amount' => '1'], // amount is derived: 250005.00
                ['description' => 'Jasa', 'amount' => '750000'],
            ],
        ]))->assertCreated()->json();

        $this->assertSame('DRAFT', $invoice['status']);
        $this->assertNull($invoice['document_number']);
        $this->assertSame($this->tenant->id, $invoice['tenant_id']);
        $this->assertSame('1000005.0000', $invoice['subtotal_amount']);
        $this->assertSame('1070005.0000', $invoice['total_amount']); // 1000005 - 50000 + 110000 + 10000
        $this->assertSame('2026-04-09', substr($invoice['due_date'], 0, 10)); // 10 March + 30 days
        $this->assertFalse($invoice['due_date_overridden']);
        $this->assertSame('250005.0000', $invoice['lines'][0]['amount']);
        $this->assertSame('INV-0001', DB::table('ap_invoices')->where('id', $invoice['id'])->value('vendor_invoice_key'));
        $this->assertSame(1, $this->rows('document_transitions', ['document_id' => $invoice['id'], 'to_status' => 'DRAFT']));

        // Editing replaces the lines and recomputes everything, including the due date when the document date moves.
        $updated = $client->patchJson(self::AP."/ap-invoices/{$invoice['id']}", ['document_date' => '2026-03-20', 'discount_amount' => '0', 'tax_amount' => '0', 'other_charges_amount' => '0', 'lines' => [['description' => 'Jasa', 'amount' => '500000']]])
            ->assertOk()->json();
        $this->assertSame('500000.0000', $updated['total_amount']);
        $this->assertSame('2026-04-19', substr($updated['due_date'], 0, 10));
        $this->assertCount(1, $updated['lines']);
    }

    public function test_input_is_validated_against_the_tenants_own_masters_and_accounts(): void
    {
        $client = $this->signedIn($this->tenant);
        $vendor = $this->vendor($this->tenant);
        $beta = $this->payablesTenant('beta');
        $betaVendor = $this->vendor($beta, 'B1');
        $line = fn (array $o = []) => ['lines' => [array_merge(['description' => 'x', 'amount' => '1000'], $o)]];
        $post = fn (array $o) => $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, $o));

        $post(['vendor_id' => $betaVendor->id])->assertStatus(422)->assertJsonPath('code', 'VENDOR_NOT_FOUND');
        $post(['lines' => []])->assertStatus(422);
        $post($line(['amount' => '0']))->assertStatus(422)->assertJsonPath('code', 'LINE_AMOUNT_INVALID');
        $post($line(['amount' => 100.5]))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID'); // floats are never money
        $post($line(['amount' => '1.234']))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID'); // more decimals than the currency has
        $post($line(['description' => '  ']))->assertStatus(422);
        $post($line(['quantity' => '2']))->assertStatus(422)->assertJsonPath('code', 'LINE_QUANTITY_INVALID');
        $post($line(['account_id' => $this->account($this->tenant, '1130')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_CONTROL_RESTRICTED');
        $post($line(['account_id' => $this->account($this->tenant, '2110')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID');
        $post($line(['account_id' => $this->account($beta, '6900')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
        $post($line(['account_id' => $this->account($this->tenant, '3100')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID');
        $post($line(['account_role' => 'ACCOUNTS_PAYABLE']))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_ROLE_INVALID');
        $post($line(['account_role' => 'CASH_BANK_ACCOUNT']))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_ROLE_INVALID');
        $post($line(['expense_category_id' => (string) Str::uuid()]))->assertStatus(422)->assertJsonPath('code', 'EXPENSE_CATEGORY_INVALID');
        $post(['discount_amount' => '2000'] + $line())->assertStatus(422)->assertJsonPath('code', 'AP_INVOICE_DISCOUNT_INVALID');
        $post(['due_date' => '2026-03-01'])->assertStatus(422)->assertJsonPath('code', 'DUE_DATE_INVALID');
        $post(['currency' => 'USD'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_FOUND'); // OA4: a foreign currency must be set up first
        $post(['branch_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');
        $post(['cost_center_id' => $this->costCenterOf($beta)])->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');

        $client->postJson(self::AP."/vendors/{$vendor->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $post([])->assertStatus(422)->assertJsonPath('code', 'VENDOR_INACTIVE');
        $this->assertSame(0, $this->rows('ap_invoices', ['tenant_id' => $this->tenant->id]));
    }

    public function test_duplicate_vendor_invoice_numbers_are_refused_unless_an_authorized_user_overrides_them(): void
    {
        $admin = $this->memberToken($this->tenant);
        $client = $this->as($admin);
        $vendor = $this->vendor($this->tenant);
        $other = $this->vendor($this->tenant, 'V2');
        $first = $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => 'FP-100']))->assertCreated()->json();

        // Case and spacing do not make a new number; another vendor may reuse it.
        $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => ' fp-100 ']))->assertStatus(409)->assertJsonPath('code', 'AP_INVOICE_DUPLICATE');
        $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($other, ['vendor_invoice_number' => 'FP-100']))->assertCreated();
        $client->getJson(self::AP."/ap-invoices/check-duplicate?vendor_id={$vendor->id}&vendor_invoice_number=fp-100")->assertOk()->assertJsonPath('duplicate', true);
        $client->getJson(self::AP."/ap-invoices/check-duplicate?vendor_id={$vendor->id}&vendor_invoice_number=FP-101")->assertOk()->assertJsonPath('duplicate', false);

        // Without the permission the override flag is refused; with it, a reason is mandatory and recorded.
        $this->as($this->memberToken($this->tenant, ['accounting.ap_invoice.view', 'accounting.ap_invoice.create']))
            ->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => 'FP-100', 'duplicate_override' => true, 'duplicate_override_reason' => 'Faktur pengganti']))
            ->assertStatus(403)->assertJsonPath('code', 'DUPLICATE_OVERRIDE_NOT_ALLOWED');
        $client = $this->as($admin);
        $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => 'FP-100', 'duplicate_override' => true]))->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_OVERRIDE_REASON_REQUIRED');
        $second = $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => 'FP-100', 'duplicate_override' => true, 'duplicate_override_reason' => 'Faktur pengganti']))->assertCreated()->json();
        $this->assertNotNull(DB::table('ap_invoices')->where('id', $second['id'])->value('duplicate_override_by'));
        $this->assertNull(DB::table('ap_invoices')->where('id', $first['id'])->value('duplicate_override_by'));

        // A stray override flag on a unique number records nothing.
        $plain = $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => 'FP-200', 'duplicate_override' => true, 'duplicate_override_reason' => 'x']))->assertCreated()->json();
        $this->assertNull(DB::table('ap_invoices')->where('id', $plain['id'])->value('duplicate_override_by'));

        // A cancelled invoice frees its number; same vendor, same amount and date but another number is only a warning.
        $client->postJson(self::AP."/ap-invoices/{$first['id']}/cancel", ['reason' => 'Salah input'])->assertOk();
        $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => 'FP-100', 'duplicate_override' => true, 'duplicate_override_reason' => 'x']))->assertCreated();
        $warned = $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => 'FP-300']))->assertCreated()->json();
        $this->assertNotEmpty($warned['possible_duplicates']);

        // The database enforces the same rule when the service is bypassed.
        $this->assertDbRefuses(fn () => DB::table('ap_invoices')->insert($this->rawInvoice($vendor->id, 'fp-200')));
    }

    public function test_the_document_moves_only_along_the_workflow_and_only_a_draft_can_be_edited(): void
    {
        $client = $this->signedIn($this->tenant);
        $vendor = $this->vendor($this->tenant);
        $id = $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id');
        $url = self::AP."/ap-invoices/{$id}";

        $client->postJson("{$url}/approve")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_INVALID_TRANSITION'); // not submitted
        $client->postJson("{$url}/post")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_APPROVAL_REQUIRED');
        $client->postJson("{$url}/submit")->assertOk()->assertJsonPath('status', 'SUBMITTED')->assertJsonPath('submitted_by', fn ($v) => $v !== null);
        $client->patchJson($url, ['description' => 'Ubah'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_NOT_DRAFT');
        $client->postJson("{$url}/reject", ['reason' => 'Tidak sesuai PO'])->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('reject_reason', 'Tidak sesuai PO');
        $client->postJson("{$url}/reject")->assertStatus(422); // a reason is mandatory
        $client->postJson("{$url}/reopen")->assertOk()->assertJsonPath('status', 'DRAFT')->assertJsonPath('rejected_by', null);
        $client->patchJson($url, ['description' => 'Perbaikan'])->assertOk()->assertJsonPath('description', 'Perbaikan');
        $client->postJson("{$url}/submit")->assertOk();
        $client->postJson("{$url}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');
        $client->patchJson($url, ['status' => 'POSTED', 'description' => 'x'])->assertStatus(409); // no arbitrary status write
        $client->postJson("{$url}/cancel", ['reason' => 'Dibatalkan sebelum posting'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $client->postJson("{$url}/submit")->assertStatus(409);
        $client->postJson("{$url}/reopen")->assertStatus(409);

        $history = DB::table('document_transitions')->where('document_id', $id)->orderBy('occurred_at')->pluck('to_status')->all();
        $this->assertSame(['DRAFT', 'SUBMITTED', 'REJECTED', 'DRAFT', 'SUBMITTED', 'APPROVED', 'CANCELLED'], $history);
        $this->assertSame(0, DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('source_type', 'ap_invoice')->count()); // none of it touched the ledger
    }

    public function test_segregation_of_duties_follows_the_profile_policy_not_role_names(): void
    {
        $tenant = $this->payablesTenant('sod'); // default policy: the preparer cannot approve
        $vendor = $this->vendor($tenant);
        $permissions = ['accounting.ap_invoice.view', 'accounting.ap_invoice.create', 'accounting.ap_invoice.submit', 'accounting.ap_invoice.approve', 'accounting.ap_invoice.post'];
        $preparer = $this->memberToken($tenant, $permissions);
        $id = $this->as($preparer)->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id');
        $this->as($preparer)->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->as($preparer)->postJson(self::AP."/ap-invoices/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->as($this->memberToken($tenant, $permissions))->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();

        $shown = $this->as($preparer)->getJson(self::AP."/ap-invoices/{$id}")->assertOk()->json('sod');
        $this->assertFalse($shown['approve']);

        // Tighter policy: the preparer may not post their own invoice either.
        $this->as($this->memberToken($tenant))->putJson(self::AP.'/profile', ['sod_creator_not_poster' => true])->assertOk();
        $this->as($preparer)->postJson(self::AP."/ap-invoices/{$id}/post")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
    }

    public function test_a_branch_scoped_user_works_only_inside_their_branch(): void
    {
        $org = app(OrganizationService::class);
        [$jkt, $sby] = $this->inTenant($this->tenant, fn () => [
            $org->createBranch($this->tenant->id, ['code' => 'JKT', 'name' => 'Jakarta'])->id, $org->createBranch($this->tenant->id, ['code' => 'SBY', 'name' => 'Surabaya'])->id,
        ]);
        $vendor = $this->vendor($this->tenant);
        $admin = $this->signedIn($this->tenant);
        $inJkt = $admin->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['branch_id' => $jkt]))->assertCreated()->json('id');
        $inSby = $admin->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['branch_id' => $sby]))->assertCreated()->json('id');
        $noBranch = $admin->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id');

        $permissions = ['accounting.ap_invoice.view', 'accounting.ap_invoice.create', 'accounting.ap_invoice.update', 'accounting.ap_invoice.submit'];
        [$user, $membership] = $this->member($this->tenant, $permissions);
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->delete();
        DB::table('data_scopes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'tenant_user_id' => $membership->id, 'scope_type' => 'BRANCH', 'branch_id' => $jkt, 'created_at' => now(), 'updated_at' => now()]);
        $scoped = $this->as($this->tenantToken($user, $this->tenant));

        $scoped->getJson(self::AP.'/ap-invoices')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $inJkt);
        $scoped->getJson(self::AP."/ap-invoices/{$inSby}")->assertNotFound();
        $scoped->getJson(self::AP."/ap-invoices/{$noBranch}")->assertNotFound();
        $scoped->patchJson(self::AP."/ap-invoices/{$inSby}", ['description' => 'x'])->assertNotFound();
        $scoped->postJson(self::AP."/ap-invoices/{$inSby}/submit")->assertNotFound();
        $scoped->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['branch_id' => $sby]))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED');
        $scoped->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED'); // no branch: outside any branch scope
        $scoped->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['branch_id' => $jkt]))->assertCreated();
        $scoped->postJson(self::AP."/ap-invoices/{$inJkt}/submit")->assertOk();
    }

    public function test_other_tenants_cannot_see_or_drive_an_invoice(): void
    {
        $client = $this->signedIn($this->tenant);
        $id = $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($this->vendor($this->tenant)))->assertCreated()->json('id');

        $beta = $this->payablesTenant('beta');
        $b = $this->signedIn($beta);
        $b->getJson(self::AP.'/ap-invoices')->assertOk()->assertJsonPath('total', 0);
        foreach (['', '/submit', '/approve', '/reject', '/reopen', '/cancel', '/post', '/reverse'] as $suffix) {
            $method = $suffix === '' ? 'getJson' : 'postJson';
            $b->{$method}(self::AP."/ap-invoices/{$id}{$suffix}", ['reason' => 'x'])->assertNotFound();
        }
        $b->patchJson(self::AP."/ap-invoices/{$id}", ['description' => 'x'])->assertNotFound();
        $this->assertSame('DRAFT', DB::table('ap_invoices')->where('id', $id)->value('status'));
    }

    private function costCenterOf(Tenant $tenant): string
    {
        return $this->inTenant($tenant, fn () => app(CostCenterService::class)->create(['code' => 'CC', 'name' => 'CC'])->id);
    }

    /** @return array<string,mixed> */
    private function rawInvoice(string $vendorId, string $number): array
    {
        return [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'vendor_id' => $vendorId, 'vendor_invoice_number' => $number,
            'document_date' => '2026-03-10', 'posting_date' => '2026-03-10', 'due_date' => '2026-03-10', 'currency' => 'IDR', 'description' => 'raw',
            'subtotal_amount' => 100, 'total_amount' => 100, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function assertDbRefuses(callable $statement): void
    {
        try {
            DB::transaction($statement);
            $this->fail('the database accepted a row it must refuse');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23514', '23503', '23505']);
        }
    }
}
