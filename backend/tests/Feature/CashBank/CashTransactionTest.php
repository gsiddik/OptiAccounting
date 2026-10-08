<?php

namespace Tests\Feature\CashBank;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Feature;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch H: controlled cash/bank payments and receipts through the Posting Engine. */
class CashTransactionTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_poster' => false]);
        $this->signedIn($this->tenant);
    }

    private function body(string $cashId, string $counterCode = '6900', array $override = []): array
    {
        return $override + [
            'cash_bank_account_id' => $cashId, 'counter_account_id' => $this->account($this->tenant, $counterCode)->id, 'amount' => '150000',
            'transaction_date' => '2026-03-12', 'purpose' => 'Biaya administrasi bank', 'description' => 'Biaya administrasi bulanan', 'reference' => 'ADM-03',
        ];
    }

    /** @return list<array{string,string,string}> */
    private function journalLines(string $journalId): array
    {
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journalId)->orderBy('l.line_number')
            ->get(['a.code', 'l.debit', 'l.credit'])->map(fn ($l) => [$l->code, $l->debit, $l->credit])->all();
    }

    private function closePeriod(string $code): void
    {
        $this->inTenant($this->tenant, function () use ($code) {
            $calendar = app(FiscalCalendarService::class);
            $period = AccountingPeriod::query()->where('code', $code)->firstOrFail();
            $calendar->transitionPeriod($period, AccountingPeriod::SOFT_CLOSED);
            $calendar->transitionPeriod($period, AccountingPeriod::CLOSED);
        });
    }

    private function assertRefused(callable $attempt, string $label = ''): void
    {
        try {
            DB::transaction($attempt);
            $this->fail("the database accepted: {$label}");
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0], $label.' '.$e->getMessage());
        }
    }

    public function test_a_payment_reaches_the_ledger_only_when_posted_and_moves_it_exactly_once(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $before = $this->glFigures($this->tenant);

        $id = $this->postJson(self::AP.'/cash-payments', $this->body($bank->id))->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('kind', 'PAYMENT')
            ->assertJsonPath('document_number', null)->assertJsonPath('amount', '150000.0000')->json('id');
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'cash_transaction']));

        $posted = $this->postJson(self::AP."/cash-payments/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED')->json();
        $this->assertSame('CP-FY2026-000001', $posted['document_number']);
        $this->assertSame([['6900', '150000.0000', '0.0000'], ['1120', '0.0000', '150000.0000']], $this->journalLines($posted['journal_entry_id']));
        $this->assertSame($this->account($this->tenant, '1120')->id, $posted['gl_account_id']);
        $this->assertSame(1, $this->rows('accounting_events', ['source_id' => $id, 'status' => 'POSTED', 'event_type' => 'CASH_PAYMENT']));
        $journal = DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->first();
        $this->assertSame(['cash_transaction', $id, 'SYSTEM', 'POSTED'], [$journal->source_type, $journal->source_id, $journal->journal_type, $journal->status]);
        $this->assertSame('CASH-PAYMENT', json_decode($journal->posting_snapshot, true)['rule']['code']);

        // The balance is only ever read from posted lines.
        $this->assertSame('-150000.0000', $this->glBalance($this->tenant, '1120'));
        $this->getJson(self::AP."/cash-bank-accounts/{$bank->id}")->assertOk()->assertJsonPath('book_balance', '-150000.0000');

        $after = $this->glFigures($this->tenant);
        $this->postJson(self::AP."/cash-payments/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_ALREADY_POSTED');
        $this->assertEquals($after, $this->glFigures($this->tenant));
        $this->assertSame(1, $this->rows('journal_entries', ['source_type' => 'cash_transaction', 'source_id' => $id]));
    }

    public function test_a_receipt_debits_the_cash_account_and_credits_the_source_account(): void
    {
        $cash = $this->cashAccount($this->tenant, 'KAS', 'CASH');
        $this->signedIn($this->tenant);

        $id = $this->postJson(self::AP.'/cash-receipts', $this->body($cash->id, '3100', ['amount' => '5000000', 'purpose' => 'Setoran modal pemilik']))->assertCreated()->assertJsonPath('kind', 'RECEIPT')->json('id');
        $posted = $this->postJson(self::AP."/cash-receipts/{$id}/post")->assertOk()->json();

        $this->assertSame('CR-FY2026-000001', $posted['document_number']);
        $this->assertSame([['1110', '5000000.0000', '0.0000'], ['3100', '0.0000', '5000000.0000']], $this->journalLines($posted['journal_entry_id']));
        $this->assertSame(1, $this->rows('accounting_events', ['source_id' => $id, 'status' => 'POSTED', 'event_type' => 'CASH_RECEIPT']));
        $this->assertSame('5000000.0000', $this->glBalance($this->tenant, '1110'));

        // The two kinds are separate families: numbering, lists, and ids do not cross over.
        $this->getJson(self::AP.'/cash-payments')->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::AP.'/cash-receipts')->assertOk()->assertJsonPath('total', 1);
        $this->getJson(self::AP."/cash-payments/{$id}")->assertNotFound();
        $this->postJson(self::AP."/cash-payments/{$id}/reverse", ['reason' => 'x'])->assertNotFound();
        $this->getJson(self::AP."/cash-receipts/{$id}")->assertOk();
    }

    public function test_the_counter_account_is_explicit_and_restricted(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $cash = $this->cashAccount($this->tenant, 'KAS', 'CASH');
        $this->signedIn($this->tenant);
        $uri = self::AP.'/cash-payments';

        $this->postJson($uri, $this->body($bank->id, '2110'))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_CONTROL_RESTRICTED'); // payables are paid by vendor payments
        $this->postJson($uri, $this->body($bank->id, '1130'))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_CONTROL_RESTRICTED');
        $this->postJson($uri, $this->body($bank->id, '1120'))->assertStatus(422)->assertJsonPath('code', 'CASH_TRANSACTION_COUNTER_IS_CASH_BANK'); // not itself
        $this->postJson($uri, $this->body($bank->id, '1110'))->assertStatus(422)->assertJsonPath('code', 'CASH_TRANSACTION_COUNTER_IS_CASH_BANK'); // not another cash box
        $this->postJson($uri, $this->body($bank->id, '6000'))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_POSTABLE');
        $this->postJson($uri, $this->body($bank->id, '6900', ['counter_account_id' => (string) Str::uuid()]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');
        $this->postJson($uri, $this->body($bank->id, '6900', ['counter_account_id' => null]))->assertStatus(422)->assertJsonValidationErrors('counter_account_id');
        $this->postJson(self::AP."/accounts/{$this->account($this->tenant, '6600')->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->postJson($uri, $this->body($bank->id, '6600'))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertSame(0, $this->rows('cash_transactions'));

        // A foreign tenant's account looks missing.
        $other = $this->payablesTenant('beta');
        $foreign = $this->account($other, '6900')->id;
        $this->signedIn($this->tenant);
        $this->postJson($uri, $this->body($bank->id, '6900', ['counter_account_id' => $foreign]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');

        // An account deactivated after the draft was prepared stops the posting.
        $id = $this->postJson($uri, $this->body($bank->id, '6500'))->assertCreated()->json('id');
        $this->postJson(self::AP."/accounts/{$this->account($this->tenant, '6500')->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->postJson("{$uri}/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertSame('DRAFT', DB::table('cash_transactions')->where('id', $id)->value('status'));
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'cash_transaction']));
        $this->assertNotNull($cash);
    }

    public function test_the_draft_is_validated_and_server_owned_fields_are_never_trusted(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $uri = self::AP.'/cash-payments';

        $this->postJson($uri, $this->body($bank->id, '6900', ['amount' => '0']))->assertStatus(422)->assertJsonPath('code', 'CASH_TRANSACTION_AMOUNT_INVALID');
        $this->postJson($uri, $this->body($bank->id, '6900', ['amount' => 10.5]))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, $this->body($bank->id, '6900', ['amount' => '-3']))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, $this->body($bank->id, '6900', ['amount' => '1.555']))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, $this->body($bank->id, '6900', ['purpose' => ' ']))->assertStatus(422);
        $this->postJson($uri, $this->body($bank->id, '6900', ['purpose' => 'ab']))->assertStatus(422)->assertJsonPath('code', 'CASH_TRANSACTION_PURPOSE_REQUIRED');
        $this->postJson($uri, $this->body($bank->id, '6900', ['currency' => 'USD']))->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_SUPPORTED');
        $this->postJson($uri, $this->body($bank->id, '6900', ['transaction_date' => '12/03/2026']))->assertStatus(422);
        $this->postJson($uri, $this->body((string) Str::uuid()))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $this->assertSame(0, $this->rows('cash_transactions'));

        $id = $this->postJson($uri, $this->body($bank->id, '6900', [
            'status' => 'POSTED', 'document_number' => 'CP-HACK', 'journal_entry_id' => (string) Str::uuid(), 'gl_account_id' => $this->account($this->tenant, '6900')->id,
            'kind' => 'RECEIPT', 'tenant_id' => (string) Str::uuid(), 'created_by' => (string) Str::uuid(),
        ]))->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('kind', 'PAYMENT')->assertJsonPath('document_number', null)->json('id');
        $row = DB::table('cash_transactions')->where('id', $id)->first();
        $this->assertSame([$this->tenant->id, null, null, null], [$row->tenant_id, $row->journal_entry_id, $row->document_number, $row->gl_account_id]);

        // A draft is edited and cancelled; a cancelled one never posts.
        $this->patchJson("{$uri}/{$id}", ['amount' => '175000', 'purpose' => 'Biaya transfer'])->assertOk()->assertJsonPath('amount', '175000.0000')->assertJsonPath('purpose', 'Biaya transfer');
        $this->postJson("{$uri}/{$id}/cancel", [])->assertStatus(422);
        $this->postJson("{$uri}/{$id}/cancel", ['reason' => 'Dobel'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson("{$uri}/{$id}/post")->assertStatus(409);
        $this->patchJson("{$uri}/{$id}", ['amount' => '1'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_NOT_DRAFT');
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'cash_transaction']));
    }

    public function test_a_closed_period_stops_the_posting_without_trace_or_gap_in_the_numbers(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $id = $this->postJson(self::AP.'/cash-payments', $this->body($bank->id))->assertCreated()->json('id');
        $this->closePeriod('2026-03');
        $before = $this->glFigures($this->tenant);

        $this->postJson(self::AP."/cash-payments/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame('DRAFT', DB::table('cash_transactions')->where('id', $id)->value('status'));
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'cash_transaction']));
        $this->assertSame(0, $this->rows('document_sequences', ['sequence_code' => 'CASH_PAYMENT']));

        $april = $this->postJson(self::AP.'/cash-payments', $this->body($bank->id, '6900', ['transaction_date' => '2026-04-02']))->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-payments/{$april}/post")->assertOk()->assertJsonPath('document_number', 'CP-FY2026-000001');
    }

    public function test_a_posted_transaction_is_immutable_in_the_service_and_the_database(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $id = $this->postJson(self::AP.'/cash-payments', $this->body($bank->id))->assertCreated()->json('id');
        $posted = $this->postJson(self::AP."/cash-payments/{$id}/post")->assertOk()->json();
        $other = $this->account($this->tenant, '6300')->id;

        $this->patchJson(self::AP."/cash-payments/{$id}", ['purpose' => 'Ubah'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
        $this->postJson(self::AP."/cash-payments/{$id}/cancel", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');

        foreach ([
            fn () => DB::table('cash_transactions')->where('id', $id)->update(['amount' => 1]),
            fn () => DB::table('cash_transactions')->where('id', $id)->update(['counter_account_id' => $other]),
            fn () => DB::table('cash_transactions')->where('id', $id)->update(['purpose' => 'tamper']),
            fn () => DB::table('cash_transactions')->where('id', $id)->update(['status' => 'DRAFT']),
            fn () => DB::table('cash_transactions')->where('id', $id)->update(['document_number' => 'CP-X']),
            fn () => DB::table('cash_transactions')->where('id', $id)->delete(),
            fn () => DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->update(['description' => 'tamper']),
            fn () => DB::table('journal_lines')->where('journal_entry_id', $posted['journal_entry_id'])->update(['debit' => 1]),
        ] as $i => $attempt) {
            $this->assertRefused($attempt, "tampering attempt #{$i}");
        }

        // Malformed rows never get in: a posted transaction without its journal, or whose counter account is its own cash account.
        $row = fn (array $override) => $override + [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'kind' => 'PAYMENT', 'cash_bank_account_id' => $bank->id, 'counter_account_id' => $other,
            'transaction_date' => '2026-03-12', 'posting_date' => '2026-03-12', 'currency' => 'IDR', 'amount' => 100, 'purpose' => 'x', 'description' => 'x',
            'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ];
        $this->assertRefused(fn () => DB::table('cash_transactions')->insert($row(['status' => 'POSTED'])), 'posted without journal');
        $this->assertRefused(fn () => DB::table('cash_transactions')->insert($row(['amount' => 0])), 'zero amount');
        $this->assertRefused(fn () => DB::table('cash_transactions')->insert($row(['kind' => 'TRANSFER'])), 'unknown kind');
        $this->assertRefused(fn () => DB::table('cash_transactions')->insert($row(['status' => 'POSTED', 'document_number' => 'CP-FAKE', 'posted_at' => now(), 'journal_entry_id' => $posted['journal_entry_id'],
            'gl_account_id' => $other])), 'posted against a journal that is not its own');
    }

    public function test_reversal_uses_the_shared_mechanism_and_keeps_history(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $id = $this->postJson(self::AP.'/cash-payments', $this->body($bank->id))->assertCreated()->json('id');
        $posted = $this->postJson(self::AP."/cash-payments/{$id}/post")->assertOk()->json();
        $afterPost = $this->glFigures($this->tenant);

        $this->postJson(self::AP."/cash-payments/{$id}/reverse", [])->assertStatus(422);
        $reversed = $this->postJson(self::AP."/cash-payments/{$id}/reverse", ['reason' => 'Salah akun', 'posting_date' => '2026-03-20'])->assertOk()
            ->assertJsonPath('status', 'REVERSED')->assertJsonPath('document_number', $posted['document_number'])->json();

        $original = DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->first();
        $reversal = DB::table('journal_entries')->where('id', $reversed['reversal_journal_id'])->first();
        $this->assertSame('POSTED', $original->status);
        $this->assertSame(['REVERSAL', $original->id, '2026-03-20'], [$reversal->journal_type, $reversal->reverses_journal_id, substr($reversal->posting_date, 0, 10)]);
        $this->assertSame(2 * $afterPost->lines, (int) $this->glFigures($this->tenant)->lines);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '6900'));
        $this->postJson(self::AP."/cash-payments/{$id}/reverse", ['reason' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'CASH_TRANSACTION_ALREADY_REVERSED');
        $this->assertSame(['DRAFT', 'POSTED', 'REVERSED'], DB::table('document_transitions')->where('document_id', $id)->orderBy('occurred_at')->pluck('to_status')->all());
        $this->assertSame(
            ['cash_bank.transaction.created', 'cash_bank.transaction.posted', 'cash_bank.transaction.reversed'],
            DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('resource_id', $id)->orderBy('occurred_at')->pluck('action')->all(),
        );

        // A reversal into a closed period changes nothing.
        $second = $this->postJson(self::AP.'/cash-payments', $this->body($bank->id, '6900', ['transaction_date' => '2026-02-10']))->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-payments/{$second}/post")->assertOk();
        $this->closePeriod('2026-02');
        $figures = $this->glFigures($this->tenant);
        $this->postJson(self::AP."/cash-payments/{$second}/reverse", ['reason' => 'Terlambat'])->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertEquals($figures, $this->glFigures($this->tenant));
        $this->postJson(self::AP."/cash-payments/{$second}/reverse", ['reason' => 'Periode terbuka', 'posting_date' => '2026-03-05'])->assertOk();
    }

    public function test_permissions_segregation_scope_tenant_isolation_and_module_states_apply(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $body = $this->body($bank->id);
        $uri = self::AP.'/cash-payments';

        // Each step needs its own permission; there is no approval step for cash transactions.
        $this->as($this->memberToken($this->tenant, ['accounting.cash_transaction.view']))->postJson($uri, $body)->assertStatus(403);
        $id = $this->as($this->memberToken($this->tenant, ['accounting.cash_transaction.create', 'accounting.cash_transaction.view']))->postJson($uri, $body)->assertCreated()->json('id');
        $this->as($this->memberToken($this->tenant, ['accounting.cash_transaction.create']))->postJson("{$uri}/{$id}/post")->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.cash_transaction.view']))->getJson("{$uri}/{$id}")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.expense.view']))->getJson($uri)->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.cash_transaction.post']))->postJson("{$uri}/{$id}/post")->assertOk();
        $this->as($this->memberToken($this->tenant, ['accounting.cash_transaction.post']))->postJson("{$uri}/{$id}/reverse", ['reason' => 'x'])->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.cash_transaction.reverse']))->postJson("{$uri}/{$id}/reverse", ['reason' => 'x'])->assertOk();

        // Segregation of duties is policy, not role names.
        $this->signedIn($this->tenant);
        $this->putJson(self::AP.'/profile', ['sod_creator_not_poster' => true])->assertOk();
        $preparer = $this->memberToken($this->tenant);
        $poster = $this->memberToken($this->tenant);
        $draft = $this->as($preparer)->postJson($uri, $body)->assertCreated()->json('id');
        $this->getJson("{$uri}/{$draft}")->assertJsonPath('sod.post', false);
        $this->postJson("{$uri}/{$draft}/post")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->as($poster)->postJson("{$uri}/{$draft}/post")->assertOk();

        // Data scope: a branch-restricted user sees and books only inside their branch.
        $this->signedIn($this->tenant);
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $northBank = $this->cashAccount($this->tenant, 'BCA-N', 'BANK', '1160', ['branch_id' => $north]);
        $southBox = $this->cashAccount($this->tenant, 'KAS-S', 'CASH', '1110', ['branch_id' => $south]);
        $this->signedIn($this->tenant);
        $inSouth = $this->postJson($uri, $this->body($southBox->id, '6900', ['branch_id' => $south]))->assertCreated()->json('id');
        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.cash_transaction.view', 'accounting.cash_transaction.create'], 'BRANCH', $north));
        $scoped->postJson($uri, $this->body($northBank->id))->assertCreated()->assertJsonPath('branch_id', $north);
        $scoped->getJson($uri)->assertOk()->assertJsonPath('total', 1);
        $scoped->getJson("{$uri}/{$inSouth}")->assertNotFound();
        $scoped->postJson($uri, $this->body($southBox->id))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $scoped->postJson($uri, $this->body($northBank->id, '6900', ['branch_id' => $south]))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED');

        // Another tenant sees and changes nothing, and cannot use our accounts.
        $other = $this->payablesTenant('beta');
        $this->signedIn($other);
        $theirBank = $this->cashAccount($other, 'BCA');
        $this->signedIn($other);
        $this->getJson($uri)->assertOk()->assertJsonPath('total', 0);
        $this->getJson("{$uri}/{$id}")->assertNotFound();
        $this->postJson("{$uri}/{$draft}/reverse", ['reason' => 'x'])->assertNotFound();
        $this->postJson($uri, $this->body($bank->id))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $this->postJson($uri, $this->body($theirBank->id, '6900', ['counter_account_id' => $this->account($this->tenant, '6900')->id]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');

        // Module states: READ_ONLY keeps reads and refuses every mutation; a disabled feature closes only its own family.
        $this->signedIn($this->tenant);
        $this->putJson(self::AP.'/profile', ['sod_creator_not_poster' => false])->assertOk();
        $pending = $this->postJson($uri, $body)->assertCreated()->json('id');
        $receipt = $this->postJson(self::AP.'/cash-receipts', $this->body($bank->id, '3100'))->assertCreated()->json('id');
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', DB::table('modules')->where('code', 'ACCOUNTING_CASH_BANK')->value('id'))->update(['state' => 'READ_ONLY']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->getJson($uri)->assertOk();
        $this->postJson("{$uri}/{$pending}/post")->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        $this->postJson($uri, $body)->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->update(['state' => 'ACTIVE']);
        DB::table('tenant_feature_entitlements')->where('tenant_id', $this->tenant->id)->where('feature_id', Feature::query()->where('code', 'PAYMENT')->value('id'))->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->getJson($uri)->assertStatus(403)->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
        $this->getJson(self::AP.'/cash-receipts')->assertOk();
        $this->postJson(self::AP."/cash-receipts/{$receipt}/post")->assertOk();
    }
}
