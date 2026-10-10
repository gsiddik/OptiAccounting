<?php

namespace Tests\Feature\Receivables;

use App\Domain\Accounting\Services\CostCenterService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use App\Domain\Payables\Models\PaymentTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\TestCase;

/** OA2 batch B: customer invoice drafts, totals, validation, duplicate control and the document lifecycle. */
class ArInvoiceLifecycleTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->receivablesTenant('alpha', ['sod_creator_not_approver' => false]);
    }

    public function test_a_draft_is_recalculated_by_the_server_and_takes_its_due_date_from_the_customer_term(): void
    {
        $client = $this->signedIn($this->tenant);
        $term = $this->inTenant($this->tenant, fn () => PaymentTerm::query()->where('code', 'NET30')->value('id'));
        $customer = $this->customer($this->tenant, 'V1', ['payment_term_id' => $term]);

        $invoice = $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, [
            'customer_reference' => ' inv-0001 ', 'discount_amount' => '50000', 'tax_amount' => '110000', 'other_charges_amount' => '10000',
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
        $this->assertSame(1, $this->rows('document_transitions', ['document_id' => $invoice['id'], 'to_status' => 'DRAFT']));

        // Editing replaces the lines and recomputes everything, including the due date when the document date moves.
        $updated = $client->patchJson(self::AR."/ar-invoices/{$invoice['id']}", ['document_date' => '2026-03-20', 'discount_amount' => '0', 'tax_amount' => '0', 'other_charges_amount' => '0', 'lines' => [['description' => 'Jasa', 'amount' => '500000']]])
            ->assertOk()->json();
        $this->assertSame('500000.0000', $updated['total_amount']);
        $this->assertSame('2026-04-19', substr($updated['due_date'], 0, 10));
        $this->assertCount(1, $updated['lines']);
    }

    public function test_input_is_validated_against_the_tenants_own_masters_and_accounts(): void
    {
        $client = $this->signedIn($this->tenant);
        $customer = $this->customer($this->tenant);
        $beta = $this->receivablesTenant('beta');
        $betaCustomer = $this->customer($beta, 'B1');
        $line = fn (array $o = []) => ['lines' => [array_merge(['description' => 'x', 'amount' => '1000'], $o)]];
        $post = fn (array $o) => $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, $o));

        $post(['customer_id' => $betaCustomer->id])->assertStatus(422)->assertJsonPath('code', 'CUSTOMER_NOT_FOUND');
        $post(['lines' => []])->assertStatus(422);
        $post($line(['amount' => '0']))->assertStatus(422)->assertJsonPath('code', 'LINE_AMOUNT_INVALID');
        $post($line(['amount' => 100.5]))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID'); // floats are never money
        $post($line(['amount' => '1.234']))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID'); // more decimals than the currency has
        $post($line(['description' => '  ']))->assertStatus(422);
        $post($line(['quantity' => '2']))->assertStatus(422)->assertJsonPath('code', 'LINE_QUANTITY_INVALID');
        $post($line(['account_id' => $this->account($this->tenant, '1130')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID'); // the receivable control account is not a revenue destination
        $post($line(['account_id' => $this->account($this->tenant, '2110')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID');
        $post($line(['account_id' => $this->account($this->tenant, '6900')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID'); // an expense account either
        $post($line(['account_id' => $this->account($beta, '4100')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
        $post($line(['account_id' => $this->account($this->tenant, '3100')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID');
        $post($line(['account_role' => 'ACCOUNTS_RECEIVABLE']))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_ROLE_INVALID');
        $post($line(['account_role' => 'CASH_BANK_ACCOUNT']))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_ROLE_INVALID');
        $post(['discount_amount' => '2000'] + $line())->assertStatus(422)->assertJsonPath('code', 'AR_INVOICE_DISCOUNT_INVALID');
        $post(['due_date' => '2026-03-01'])->assertStatus(422)->assertJsonPath('code', 'DUE_DATE_INVALID');
        $post(['currency' => 'USD'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_SUPPORTED');
        $post(['branch_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');
        $post(['cost_center_id' => $this->costCenterOf($beta)])->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');

        $client->postJson(self::AR."/customers/{$customer->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $post([])->assertStatus(422)->assertJsonPath('code', 'CUSTOMER_INACTIVE');
        $this->assertSame(0, $this->rows('ar_invoices', ['tenant_id' => $this->tenant->id]));
    }

    public function test_a_repeated_customer_reference_is_a_warning_and_never_a_refusal(): void
    {
        $client = $this->signedIn($this->tenant);
        $customer = $this->customer($this->tenant);
        $other = $this->customer($this->tenant, 'C2');
        $first = $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['customer_reference' => 'PO-100']))->assertCreated()->json();
        $this->assertSame([], $first['possible_duplicates']);

        // The same reference (case and spacing ignored) on the same customer is allowed and warned about; another customer's invoice is not a duplicate.
        $second = $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['customer_reference' => ' po-100 ', 'lines' => [['description' => 'x', 'amount' => '5000']]]))->assertCreated()->json();
        $this->assertSame([$first['id']], array_column($second['possible_duplicates'], 'id'));
        $foreign = $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($other, ['customer_reference' => 'PO-100']))->assertCreated()->json();
        $this->assertSame([], $foreign['possible_duplicates']);

        // Same customer, same total and document date under another reference is also only a warning; a cancelled invoice stops counting.
        $twin = $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['customer_reference' => 'PO-300']))->assertCreated()->json();
        $this->assertContains($first['id'], array_column($twin['possible_duplicates'], 'id'));
        $client->postJson(self::AR."/ar-invoices/{$first['id']}/cancel", ['reason' => 'Salah input'])->assertOk();
        $this->assertNotContains($first['id'], array_column($client->getJson(self::AR."/ar-invoices/{$twin['id']}")->json('possible_duplicates'), 'id'));

        // The reference is optional and may repeat across any number of invoices; the database holds no uniqueness on it.
        $this->assertSame(3, DB::table('ar_invoices')->where('customer_id', $customer->id)->count());
        $this->assertSame(2, DB::table('ar_invoices')->where('customer_id', $customer->id)->whereRaw('upper(trim(customer_reference)) = ?', ['PO-100'])->count());
    }

    public function test_the_document_moves_only_along_the_workflow_and_only_a_draft_can_be_edited(): void
    {
        $client = $this->signedIn($this->tenant);
        $customer = $this->customer($this->tenant);
        $id = $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        $url = self::AR."/ar-invoices/{$id}";

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
        $this->assertSame(0, DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->where('source_type', 'ar_invoice')->count()); // none of it touched the ledger
    }

    public function test_segregation_of_duties_follows_the_profile_policy_not_role_names(): void
    {
        $tenant = $this->receivablesTenant('sod'); // default policy: the preparer cannot approve
        $customer = $this->customer($tenant);
        $permissions = ['accounting.ar_invoice.view', 'accounting.ar_invoice.create', 'accounting.ar_invoice.submit', 'accounting.ar_invoice.approve', 'accounting.ar_invoice.post'];
        $preparer = $this->memberToken($tenant, $permissions);
        $id = $this->as($preparer)->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        $this->as($preparer)->postJson(self::AR."/ar-invoices/{$id}/submit")->assertOk();
        $this->as($preparer)->postJson(self::AR."/ar-invoices/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->as($this->memberToken($tenant, $permissions))->postJson(self::AR."/ar-invoices/{$id}/approve")->assertOk();

        $shown = $this->as($preparer)->getJson(self::AR."/ar-invoices/{$id}")->assertOk()->json('sod');
        $this->assertFalse($shown['approve']);

        // Tighter policy: the preparer may not post their own invoice either.
        $this->as($this->memberToken($tenant))->putJson(self::AR.'/profile', ['sod_creator_not_poster' => true])->assertOk();
        $this->as($preparer)->postJson(self::AR."/ar-invoices/{$id}/post")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
    }

    public function test_a_branch_scoped_user_works_only_inside_their_branch(): void
    {
        $org = app(OrganizationService::class);
        [$jkt, $sby] = $this->inTenant($this->tenant, fn () => [
            $org->createBranch($this->tenant->id, ['code' => 'JKT', 'name' => 'Jakarta'])->id, $org->createBranch($this->tenant->id, ['code' => 'SBY', 'name' => 'Surabaya'])->id,
        ]);
        $customer = $this->customer($this->tenant);
        $admin = $this->signedIn($this->tenant);
        $inJkt = $admin->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['branch_id' => $jkt]))->assertCreated()->json('id');
        $inSby = $admin->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['branch_id' => $sby]))->assertCreated()->json('id');
        $noBranch = $admin->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');

        $permissions = ['accounting.ar_invoice.view', 'accounting.ar_invoice.create', 'accounting.ar_invoice.update', 'accounting.ar_invoice.submit'];
        [$user, $membership] = $this->member($this->tenant, $permissions);
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->delete();
        DB::table('data_scopes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'tenant_user_id' => $membership->id, 'scope_type' => 'BRANCH', 'branch_id' => $jkt, 'created_at' => now(), 'updated_at' => now()]);
        $scoped = $this->as($this->tenantToken($user, $this->tenant));

        $scoped->getJson(self::AR.'/ar-invoices')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $inJkt);
        $scoped->getJson(self::AR."/ar-invoices/{$inSby}")->assertNotFound();
        $scoped->getJson(self::AR."/ar-invoices/{$noBranch}")->assertNotFound();
        $scoped->patchJson(self::AR."/ar-invoices/{$inSby}", ['description' => 'x'])->assertNotFound();
        $scoped->postJson(self::AR."/ar-invoices/{$inSby}/submit")->assertNotFound();
        $scoped->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['branch_id' => $sby]))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED');
        $scoped->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED'); // no branch: outside any branch scope
        $scoped->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['branch_id' => $jkt]))->assertCreated();
        $scoped->postJson(self::AR."/ar-invoices/{$inJkt}/submit")->assertOk();
    }

    public function test_other_tenants_cannot_see_or_drive_an_invoice(): void
    {
        $client = $this->signedIn($this->tenant);
        $id = $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($this->customer($this->tenant)))->assertCreated()->json('id');

        $beta = $this->receivablesTenant('beta');
        $b = $this->signedIn($beta);
        $b->getJson(self::AR.'/ar-invoices')->assertOk()->assertJsonPath('total', 0);
        foreach (['', '/submit', '/approve', '/reject', '/reopen', '/cancel', '/post', '/reverse'] as $suffix) {
            $method = $suffix === '' ? 'getJson' : 'postJson';
            $b->{$method}(self::AR."/ar-invoices/{$id}{$suffix}", ['reason' => 'x'])->assertNotFound();
        }
        $b->patchJson(self::AR."/ar-invoices/{$id}", ['description' => 'x'])->assertNotFound();
        $this->assertSame('DRAFT', DB::table('ar_invoices')->where('id', $id)->value('status'));
    }

    private function costCenterOf(Tenant $tenant): string
    {
        return $this->inTenant($tenant, fn () => app(CostCenterService::class)->create(['code' => 'CC', 'name' => 'CC'])->id);
    }
}
