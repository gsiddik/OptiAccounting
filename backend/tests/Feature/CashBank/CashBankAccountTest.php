<?php

namespace Tests\Feature\CashBank;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch G: cash and bank accounts, their GL mapping, protected bank data and book balances read from posted journals. */
class CashBankAccountTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->accountingTenant('alpha');
    }

    private function body(string $code, string $kind, string $gl, array $extra = []): array
    {
        return $extra + ['code' => $code, 'name' => "Akun {$code}", 'kind' => $kind, 'account_id' => $this->account($this->tenant, $gl)->id] + ($kind === 'BANK' ? ['bank_name' => 'Bank Contoh'] : []);
    }

    public function test_cash_and_bank_accounts_are_created_filtered_updated_and_deactivated(): void
    {
        $client = $this->signedIn($this->tenant);

        $cash = $client->postJson(self::AP.'/cash-bank-accounts', $this->body(' kas-pusat ', 'CASH', '1110'))->assertCreated()
            ->assertJsonPath('code', 'KAS-PUSAT')->assertJsonPath('status', 'ACTIVE')->assertJsonPath('currency', 'IDR')->assertJsonPath('gl_account.code', '1110')->json();
        $bank = $client->postJson(self::AP.'/cash-bank-accounts', $this->body('BCA', 'BANK', '1120', ['account_holder' => 'PT Alpha', 'account_number' => '123-456-7890']))->assertCreated()
            ->assertJsonPath('account_number_masked', '******7890')->json();

        $client->getJson(self::AP.'/cash-bank-accounts')->assertOk()->assertJsonPath('total', 2);
        $client->getJson(self::AP.'/cash-bank-accounts?kind=BANK')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $bank['id']);
        $client->getJson(self::AP.'/cash-bank-accounts?q=pusat')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $cash['id']);
        $client->getJson(self::AP.'/cash-bank-accounts?q=%25')->assertOk()->assertJsonPath('total', 0);

        $client->patchJson(self::AP."/cash-bank-accounts/{$bank['id']}", ['name' => 'BCA Operasional', 'notes' => 'rekening gaji'])->assertOk()->assertJsonPath('name', 'BCA Operasional');
        $client->postJson(self::AP."/cash-bank-accounts/{$bank['id']}/status", ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('status', 'INACTIVE');
        $client->getJson(self::AP.'/cash-bank-accounts?status=ACTIVE')->assertOk()->assertJsonPath('total', 1);
        $client->postJson(self::AP."/cash-bank-accounts/{$bank['id']}/status", ['status' => 'ACTIVE'])->assertOk()->assertJsonPath('status', 'ACTIVE');

        // The code and the kind are fixed at creation; a duplicate code is refused; the kind decides which bank fields exist.
        $client->patchJson(self::AP."/cash-bank-accounts/{$bank['id']}", ['code' => 'OTHER'])->assertStatus(422);
        $client->patchJson(self::AP."/cash-bank-accounts/{$bank['id']}", ['kind' => 'CASH'])->assertStatus(422);
        $client->postJson(self::AP.'/cash-bank-accounts', $this->body('BCA', 'BANK', '1120'))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_CODE_TAKEN');
        $client->postJson(self::AP.'/cash-bank-accounts', $this->body('KAS2', 'CASH', '1110', ['bank_name' => 'BCA']))->assertStatus(422)->assertJsonPath('code', 'CASH_ACCOUNT_HAS_NO_BANK_DATA');
        $client->postJson(self::AP.'/cash-bank-accounts', ['code' => 'BNI', 'name' => 'BNI', 'kind' => 'BANK', 'account_id' => $this->account($this->tenant, '1120')->id])->assertStatus(422)->assertJsonPath('code', 'BANK_NAME_REQUIRED');
        $client->postJson(self::AP.'/cash-bank-accounts', $this->body('X', 'CASH', '1110', ['currency' => 'USD']))->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_SUPPORTED');

        $client->deleteJson(self::AP."/cash-bank-accounts/{$cash['id']}")->assertNoContent();
        $this->assertSame(1, $this->rows('cash_bank_accounts', ['tenant_id' => $this->tenant->id]));
    }

    public function test_the_gl_mapping_must_be_a_usable_asset_account_of_the_same_tenant(): void
    {
        $other = $this->accountingTenant('beta');
        $client = $this->signedIn($this->tenant);
        $uri = self::AP.'/cash-bank-accounts';

        $client->postJson($uri, $this->body('A', 'BANK', '1120', ['account_id' => $this->account($other, '1120')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND'); // another tenant's account
        $client->postJson($uri, $this->body('B', 'BANK', '1100'))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_POSTABLE'); // header account
        $client->postJson($uri, $this->body('C', 'BANK', '2140'))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID'); // liability
        $client->postJson($uri, $this->body('D', 'BANK', '6900'))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID'); // expense
        $client->postJson($uri, $this->body('E', 'BANK', '1120', ['account_id' => (string) Str::uuid()]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
        $this->inTenant($this->tenant, fn () => $this->account($this->tenant, '1120')->forceFill(['status' => 'INACTIVE'])->save());
        $client->postJson($uri, $this->body('F', 'BANK', '1120'))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertSame(0, $this->rows('cash_bank_accounts'));

        // The database refuses the cross-tenant mapping on its own (composite foreign key), whatever wrote it.
        try {
            DB::transaction(fn () => DB::table('cash_bank_accounts')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'code' => 'RAW', 'name' => 'raw', 'kind' => 'BANK', 'status' => 'ACTIVE', 'currency' => 'IDR',
                'account_id' => $this->account($other, '1110')->id, 'bank_name' => 'X', 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('A cross-tenant GL mapping was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        }
    }

    public function test_one_active_cash_or_bank_account_per_gl_account(): void
    {
        $client = $this->signedIn($this->tenant);
        $first = $client->postJson(self::AP.'/cash-bank-accounts', $this->body('B1', 'BANK', '1120'))->assertCreated()->json('id');

        $client->postJson(self::AP.'/cash-bank-accounts', $this->body('B2', 'BANK', '1120'))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_GL_ACCOUNT_TAKEN');

        $client->postJson(self::AP."/cash-bank-accounts/{$first}/status", ['status' => 'INACTIVE'])->assertOk();
        $second = $client->postJson(self::AP.'/cash-bank-accounts', $this->body('B2', 'BANK', '1120'))->assertCreated()->json('id');
        $client->postJson(self::AP."/cash-bank-accounts/{$first}/status", ['status' => 'ACTIVE'])->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_GL_ACCOUNT_TAKEN');
        $client->patchJson(self::AP."/cash-bank-accounts/{$second}", ['account_id' => $this->account($this->tenant, '1110')->id])->assertOk()->assertJsonPath('gl_account.code', '1110');
    }

    public function test_the_full_bank_number_is_never_stored_returned_or_audited(): void
    {
        $client = $this->signedIn($this->tenant);
        $full = '9876543210123456';
        $id = $client->postJson(self::AP.'/cash-bank-accounts', $this->body('SEC', 'BANK', '1120', ['account_number' => $full]))->assertCreated()->assertJsonPath('account_number_masked', '******3456')->json('id');
        $client->patchJson(self::AP."/cash-bank-accounts/{$id}", ['account_number' => '5555 6666 7777 8888'])->assertOk()->assertJsonPath('account_number_masked', '******8888');
        $client->getJson(self::AP."/cash-bank-accounts/{$id}")->assertOk()->assertJsonMissingPath('account_number');
        $client->getJson(self::AP.'/cash-bank-accounts')->assertOk();

        $stored = json_encode(DB::table('cash_bank_accounts')->get()).json_encode(DB::table('audit_logs')->get());
        foreach ([$full, '123456', '5555666677778888', '5555 6666', '98765'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertGreaterThanOrEqual(2, DB::table('audit_logs')->where('action', 'like', 'cash_bank.account.%')->count());

        // Whatever writes the row, a run of five or more digits cannot be stored as the "masked" number; a cash account carries no bank data.
        foreach ([['account_number_masked' => '12345678'], ['account_number_masked' => 'ab 12345 cd']] as $bad) {
            try {
                DB::transaction(fn () => DB::table('cash_bank_accounts')->where('id', $id)->update($bad));
                $this->fail('A full account number was stored.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $cash = $this->cashAccount($this->tenant, 'KAS', 'CASH');
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('cash_bank_accounts')->where('id', $cash->id)->update(['bank_name' => 'BCA']));
    }

    public function test_the_book_balance_is_read_from_posted_journals_only(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA', 'BANK');
        $client = $this->signedIn($this->tenant);
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '750000', '1120', '4100'), 'posting_date' => '2026-02-10', 'document_date' => '2026-02-10']);
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '250000', '4100', '1120'), 'posting_date' => '2026-03-20', 'document_date' => '2026-03-20']);
        $client->postJson(self::AP.'/journals', $this->journalBody($this->tenant, ['lines' => $this->lines($this->tenant, '999999', '1120', '4100')]))->assertCreated(); // a draft counts for nothing

        $client->getJson(self::AP."/cash-bank-accounts/{$bank->id}")->assertOk()->assertJsonPath('book_balance', '500000.0000');
        $client->getJson(self::AP."/cash-bank-accounts/{$bank->id}?as_of=2026-02-28")->assertOk()->assertJsonPath('book_balance', '750000.0000');
        $client->getJson(self::AP.'/cash-bank-accounts')->assertOk()->assertJsonPath('data.0.book_balance', '500000.0000');
        $this->assertSame(0, DB::table('information_schema.columns')->where('table_name', 'cash_bank_accounts')->where('column_name', 'like', '%balance%')->count(), 'no stored balance exists');
    }

    public function test_accounts_are_tenant_private_and_follow_the_data_scope(): void
    {
        $other = $this->accountingTenant('beta');
        $theirs = $this->cashAccount($other, 'THEIRS', 'BANK');
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $admin = $this->signedIn($this->tenant);
        $inNorth = $admin->postJson(self::AP.'/cash-bank-accounts', $this->body('N1', 'CASH', '1110', ['branch_id' => $north]))->assertCreated()->json('id');
        $inSouth = $admin->postJson(self::AP.'/cash-bank-accounts', $this->body('S1', 'BANK', '1120', ['branch_id' => $south]))->assertCreated()->json('id');

        $admin->getJson(self::AP."/cash-bank-accounts/{$theirs->id}")->assertNotFound();
        $admin->patchJson(self::AP."/cash-bank-accounts/{$theirs->id}", ['name' => 'x'])->assertNotFound();
        $admin->deleteJson(self::AP."/cash-bank-accounts/{$theirs->id}")->assertNotFound();
        $admin->getJson(self::AP.'/cash-bank-accounts')->assertOk()->assertJsonPath('total', 2);

        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.cash_bank.view', 'accounting.cash_bank.manage'], 'BRANCH', $north));
        $scoped->getJson(self::AP.'/cash-bank-accounts')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $inNorth);
        $scoped->getJson(self::AP."/cash-bank-accounts/{$inSouth}")->assertNotFound();
        $scoped->postJson(self::AP."/cash-bank-accounts/{$inSouth}/status", ['status' => 'INACTIVE'])->assertNotFound();
        $scoped->postJson(self::AP.'/cash-bank-accounts', $this->body('S2', 'CASH', '1110', ['branch_id' => $south]))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED');
    }

    public function test_permissions_gate_each_action(): void
    {
        $viewer = $this->signedIn($this->tenant, ['accounting.cash_bank.view']);
        $viewer->getJson(self::AP.'/cash-bank-accounts')->assertOk();
        $viewer->postJson(self::AP.'/cash-bank-accounts', $this->body('A', 'CASH', '1110'))->assertStatus(403);
        $none = $this->signedIn($this->tenant, ['accounting.vendor.view']);
        $none->getJson(self::AP.'/cash-bank-accounts')->assertStatus(403);
    }
}
