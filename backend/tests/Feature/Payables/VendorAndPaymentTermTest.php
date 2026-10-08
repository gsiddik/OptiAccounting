<?php

namespace Tests\Feature\Payables;

use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Payables\Services\PaymentTermService;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA2 batch A: vendor master, vendor financial profile and tenant-configurable payment terms. */
class VendorAndPaymentTermTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const A = '/api/v1/app/accounting';

    public function test_a_vendor_is_created_updated_and_deactivated_with_a_normalized_code(): void
    {
        $tenant = $this->accountingTenant();
        $client = $this->asMember($tenant);

        $vendor = $client->postJson(self::A.'/vendors', ['code' => ' pln-01 ', 'name' => 'PT Listrik', 'email' => 'AR@PLN.test', 'tax_id' => '01.234.567.8-901.000', 'tax_registered' => true])
            ->assertCreated()->assertJsonPath('code', 'PLN-01')->assertJsonPath('status', 'ACTIVE')->assertJsonPath('email', 'ar@pln.test')->json();

        $client->patchJson(self::A."/vendors/{$vendor['id']}", ['name' => 'PT Listrik Negara', 'phone' => '021-555'])->assertOk()->assertJsonPath('name', 'PT Listrik Negara');
        $client->postJson(self::A."/vendors/{$vendor['id']}/status", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $client->getJson(self::A.'/vendors?status=INACTIVE')->assertOk()->assertJsonPath('total', 1);
        $client->getJson(self::A.'/vendors?status=ACTIVE')->assertOk()->assertJsonPath('total', 0);
        $client->getJson(self::A.'/vendors?q=listrik')->assertOk()->assertJsonPath('total', 1);
        $client->getJson(self::A.'/vendors?q=01.234')->assertOk()->assertJsonPath('total', 1); // tax id is searchable
        $client->getJson(self::A.'/vendors?q=%25')->assertOk()->assertJsonPath('total', 0); // wildcards are matched literally

        $client->postJson(self::A.'/vendors', ['code' => 'PLN-01', 'name' => 'Duplikat'])->assertStatus(422)->assertJsonPath('code', 'VENDOR_CODE_TAKEN');
        $client->postJson(self::A.'/vendors', ['code' => 'bad code', 'name' => 'x'])->assertStatus(422);
        $client->postJson(self::A.'/vendors', ['code' => 'X1', 'name' => 'x', 'external_source' => 'ERP'])->assertStatus(422)->assertJsonPath('code', 'VENDOR_EXTERNAL_REFERENCE_INVALID');
        $client->postJson(self::A.'/vendors', ['code' => 'X2', 'name' => 'x', 'default_currency' => 'USD'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_SUPPORTED');

        $client->deleteJson(self::A."/vendors/{$vendor['id']}")->assertNoContent();
        $this->assertSame(0, $this->rows('vendors', ['tenant_id' => $tenant->id]));
    }

    public function test_privileged_fields_in_a_vendor_payload_are_ignored(): void
    {
        $alpha = $this->accountingTenant('alpha');
        $beta = $this->tenant('beta');

        $vendor = $this->asMember($alpha)->postJson(self::A.'/vendors', [
            'code' => 'V1', 'name' => 'Vendor', 'tenant_id' => $beta->id, 'status' => 'INACTIVE', 'created_by' => 'x', 'id' => '11111111-1111-1111-1111-111111111111',
        ])->assertCreated()->json();

        $this->assertSame($alpha->id, $vendor['tenant_id']);
        $this->assertSame('ACTIVE', $vendor['status']);
        $this->assertNotSame('11111111-1111-1111-1111-111111111111', $vendor['id']);
    }

    public function test_vendors_and_terms_are_invisible_to_other_tenants_and_codes_are_scoped_per_tenant(): void
    {
        $alpha = $this->accountingTenant('alpha');
        $beta = $this->accountingTenant('beta');
        $a = $this->asMember($alpha);
        $vendor = $a->postJson(self::A.'/vendors', ['code' => 'V1', 'name' => 'Alpha vendor'])->assertCreated()->json();
        $term = $a->postJson(self::A.'/payment-terms', ['code' => 'NET30', 'name' => 'Net 30', 'term_type' => 'NET_DAYS', 'due_days' => 30])->assertCreated()->json();

        $b = $this->asMember($beta);
        $b->getJson(self::A.'/vendors')->assertOk()->assertJsonPath('total', 0);
        $b->getJson(self::A."/vendors/{$vendor['id']}")->assertNotFound();
        $b->patchJson(self::A."/vendors/{$vendor['id']}", ['name' => 'Hijack'])->assertNotFound();
        $b->postJson(self::A."/vendors/{$vendor['id']}/status", ['status' => 'INACTIVE'])->assertNotFound();
        $b->deleteJson(self::A."/vendors/{$vendor['id']}")->assertNotFound();
        $b->patchJson(self::A."/payment-terms/{$term['id']}", ['name' => 'Hijack'])->assertNotFound();
        $b->getJson(self::A.'/payment-terms')->assertOk()->assertJsonCount(0, 'data');

        // The same code is free in another tenant, and a foreign payment term cannot be attached to a vendor.
        $b->postJson(self::A.'/vendors', ['code' => 'V1', 'name' => 'Beta vendor'])->assertCreated();
        $b->postJson(self::A.'/vendors', ['code' => 'V2', 'name' => 'x', 'payment_term_id' => $term['id']])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_TERM_NOT_FOUND');
        $this->assertSame('Alpha vendor', DB::table('vendors')->where('id', $vendor['id'])->value('name'));
    }

    public function test_the_financial_profile_is_validated_against_the_tenants_own_terms_and_accounts_and_audited(): void
    {
        $alpha = $this->accountingTenant('alpha');
        $beta = $this->accountingTenant('beta');
        $client = $this->asMember($alpha);
        $term = $client->postJson(self::A.'/payment-terms', ['code' => 'NET14', 'name' => 'Net 14', 'term_type' => 'NET_DAYS', 'due_days' => 14])->assertCreated()->json('id');
        $ap = $this->account($alpha, '2110')->id;
        $vendor = $client->postJson(self::A.'/vendors', ['code' => 'V1', 'name' => 'Vendor'])->assertCreated()->json('id');

        $client->patchJson(self::A."/vendors/{$vendor}", ['payable_account_id' => $this->account($alpha, '1110')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID');
        $client->patchJson(self::A."/vendors/{$vendor}", ['payable_account_id' => $this->account($alpha, '2140')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_CONTROL');
        $client->patchJson(self::A."/vendors/{$vendor}", ['payable_account_id' => $this->account($beta, '2110')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
        $client->patchJson(self::A."/vendors/{$vendor}", ['default_expense_account_id' => $ap])->assertStatus(422); // a control account is not an expense destination
        $client->patchJson(self::A."/vendors/{$vendor}", ['default_expense_account_id' => $this->account($alpha, '6900')->id])->assertOk();

        $client->patchJson(self::A."/vendors/{$vendor}", ['payment_term_id' => $term, 'payable_account_id' => $ap])->assertOk()
            ->assertJsonPath('payment_term.code', 'NET14')->assertJsonPath('payable_account.code', '2110');

        $changed = DB::table('audit_logs')->where('tenant_id', $alpha->id)->where('action', 'payables.vendor.financial_profile_changed')->where('resource_id', $vendor)->get();
        $this->assertCount(2, $changed); // the expense hint, then the term + payable account
        $last = json_decode($changed->last()->changes, true);
        $this->assertNull($last['before']['payable_account_id']);
        $this->assertSame($ap, $last['after']['payable_account_id']);

        // A change that touches identity only leaves no financial-profile record.
        $client->patchJson(self::A."/vendors/{$vendor}", ['notes' => 'catatan'])->assertOk();
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'payables.vendor.financial_profile_changed')->where('resource_id', $vendor)->count());

        // A deactivated account is refused as a profile value.
        $client->postJson(self::A.'/accounts/'.$this->account($alpha, '6200')->id.'/status', ['status' => 'INACTIVE'])->assertOk();
        $client->patchJson(self::A."/vendors/{$vendor}", ['default_expense_account_id' => $this->account($alpha, '6200')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_every_vendor_and_term_write_is_audited(): void
    {
        $tenant = $this->accountingTenant();
        $client = $this->asMember($tenant);
        $term = $client->postJson(self::A.'/payment-terms', ['code' => 'NET7', 'name' => 'Net 7', 'term_type' => 'NET_DAYS', 'due_days' => 7])->assertCreated()->json('id');
        $client->patchJson(self::A."/payment-terms/{$term}", ['due_days' => 10])->assertOk();
        $client->postJson(self::A."/payment-terms/{$term}/status", ['status' => 'INACTIVE'])->assertOk();
        $vendor = $client->postJson(self::A.'/vendors', ['code' => 'V1', 'name' => 'V'])->assertCreated()->json('id');
        $client->postJson(self::A."/vendors/{$vendor}/status", ['status' => 'INACTIVE'])->assertOk();
        $client->deleteJson(self::A."/vendors/{$vendor}")->assertNoContent();
        $client->deleteJson(self::A."/payment-terms/{$term}")->assertNoContent();

        $actions = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'like', 'payables.%')->pluck('action')->all();
        foreach (['payment_term.created', 'payment_term.updated', 'payment_term.status_changed', 'payment_term.deleted', 'vendor.created', 'vendor.status_changed', 'vendor.deleted'] as $action) {
            $this->assertContains("payables.{$action}", $actions);
        }
        $this->assertSame(7, DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'like', 'payables.%')->whereNotNull('actor_user_id')->count());
    }

    public function test_payment_terms_derive_due_dates_deterministically_and_overrides_follow_policy(): void
    {
        $tenant = $this->accountingTenant();
        $client = $this->asMember($tenant);
        $client->postJson(self::A.'/payment-terms/defaults')->assertCreated()->assertJsonCount(7, 'created');
        $client->postJson(self::A.'/payment-terms/defaults')->assertCreated()->assertJsonCount(0, 'created'); // idempotent
        $client->postJson(self::A.'/payment-terms', ['code' => 'NET45', 'name' => 'Net 45', 'term_type' => 'NET_DAYS', 'due_days' => 45])->assertCreated(); // not limited to the examples
        $client->postJson(self::A.'/payment-terms', ['code' => 'NET45', 'name' => 'Dup', 'term_type' => 'NET_DAYS', 'due_days' => 1])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_TERM_CODE_TAKEN');
        $client->postJson(self::A.'/payment-terms', ['code' => 'BAD', 'name' => 'Bad', 'term_type' => 'NET_DAYS'])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_TERM_DAYS_INVALID');
        $client->postJson(self::A.'/payment-terms', ['code' => 'WEIRD', 'name' => 'Weird', 'term_type' => 'LUNAR', 'due_days' => 1])->assertStatus(422);

        $this->inTenant($tenant, function () {
            $service = app(PaymentTermService::class);
            $term = fn (string $code) => PaymentTerm::query()->where('code', $code)->firstOrFail();

            $this->assertSame(['2026-03-31', false], $service->dueDate($term('NET30'), '2026-03-01', null));
            $this->assertSame(['2026-03-01', false], $service->dueDate($term('COD'), '2026-03-01', null));
            $this->assertSame(['2026-03-31', false], $service->dueDate($term('NET30'), '2026-03-01', '2026-03-31')); // explicit but equal: not an override
            $this->assertSame(['2026-04-30', false], $service->dueDate($term('EOM30'), '2026-03-10', null)); // 31 March + 30 days
            $this->assertSame(['2026-03-04', false], $service->dueDate($term('NET7'), '2026-02-25', null)); // crosses month end
            $this->assertSame(['2026-05-31', true], $service->dueDate($term('CUSTOM'), '2026-03-01', '2026-05-31'));
            $this->assertSame(['2026-03-15', true], $service->dueDate(null, '2026-03-01', '2026-03-15'));

            foreach ([
                [fn () => $service->dueDate($term('CUSTOM'), '2026-03-01', null), 'DUE_DATE_REQUIRED'],
                [fn () => $service->dueDate($term('NET30'), '2026-03-01', '2026-03-10'), 'DUE_DATE_OVERRIDE_NOT_ALLOWED'],
                [fn () => $service->dueDate($term('NET30'), '2026-03-01', '2026-02-01'), 'DUE_DATE_INVALID'],
            ] as [$call, $code]) {
                try {
                    $call();
                    $this->fail("expected {$code}");
                } catch (DomainException $e) {
                    $this->assertSame($code, $e->errorCode);
                }
            }

            $term('NET30')->forceFill(['allows_due_date_override' => true])->save();
            $this->assertSame(['2026-03-10', true], $service->dueDate($term('NET30'), '2026-03-01', '2026-03-10'));
        });
    }

    public function test_the_database_refuses_inconsistent_terms_and_cross_tenant_vendor_links(): void
    {
        $alpha = $this->accountingTenant('alpha');
        $beta = $this->accountingTenant('beta');
        $term = $this->asMember($beta)->postJson(self::A.'/payment-terms', ['code' => 'NET30', 'name' => 'Net 30', 'term_type' => 'NET_DAYS', 'due_days' => 30])->assertCreated()->json('id');

        $base = ['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $alpha->id, 'code' => 'RAW', 'name' => 'Raw', 'created_at' => now(), 'updated_at' => now()];
        $this->assertDbRefuses(fn () => DB::table('payment_terms')->insert($base + ['term_type' => 'NET_DAYS', 'due_days' => null]));
        $this->assertDbRefuses(fn () => DB::table('payment_terms')->insert(['code' => 'RAW2'] + $base + ['term_type' => 'CUSTOM', 'due_days' => 5]));
        $this->assertDbRefuses(fn () => DB::table('vendors')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $alpha->id, 'code' => 'X', 'name' => 'x', 'payment_term_id' => $term, 'created_at' => now(), 'updated_at' => now()]));
        $this->assertDbRefuses(fn () => DB::table('vendors')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $alpha->id, 'code' => 'X', 'name' => 'x', 'default_currency' => 'usd', 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_view_and_manage_are_separate_permissions_and_a_missing_module_closes_the_routes(): void
    {
        $tenant = $this->accountingTenant();
        $viewer = $this->asMember($tenant, ['accounting.vendor.view']);
        $viewer->getJson(self::A.'/vendors')->assertOk();
        $viewer->getJson(self::A.'/payment-terms')->assertOk();
        $viewer->postJson(self::A.'/vendors', ['code' => 'V', 'name' => 'V'])->assertForbidden();
        $viewer->postJson(self::A.'/payment-terms/defaults')->assertForbidden();

        $this->asMember($tenant, ['accounting.profile.view'])->getJson(self::A.'/vendors')->assertForbidden();

        $bare = $this->tenant('bare', subscribed: false);
        $this->asMember($bare)->getJson(self::A.'/vendors')->assertForbidden();
    }

    private function assertDbRefuses(callable $statement): void
    {
        try {
            DB::transaction($statement);
            $this->fail('the database accepted a row it must refuse');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23514', '23503', '23505', '23502']);
        }
    }
}
