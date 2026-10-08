<?php

namespace Tests\Feature\Accounting;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA1 batch I: the controlled opening balance and the reversal of posted journals. */
class OpeningBalanceAndReversalTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const OB = '/api/v1/app/accounting/opening-balance';

    private const J = '/api/v1/app/accounting/journals';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->accountingTenant();
    }

    private function profile(array $changes): void
    {
        DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->update($changes);
    }

    private function openingBody(string $date = '2026-01-01', array $override = []): array
    {
        return $override + ['cutover_date' => $date, 'reference' => 'SALDO-AWAL', 'lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '1000000'],
            ['account_id' => $this->account($this->tenant, '1130')->id, 'debit' => '500000.50', 'description' => 'Piutang awal'], // a control account is fine for an opening balance
            ['account_id' => $this->account($this->tenant, '3100')->id, 'credit' => '1500000.50'],
        ]];
    }

    /** A MANUAL journal posted through the API (no approval step), as the signed-in everything-user. */
    private function manualPosted(string $date = '2026-03-10', array $override = []): string
    {
        $this->profile(['approval_required' => false]);
        $client = $this->signedIn($this->tenant);
        $id = $this->draft($this->tenant, ['posting_date' => $date, 'document_date' => $date] + $override)['id'];
        $client->postJson(self::J."/{$id}/post")->assertOk();

        return $id;
    }

    // ------------------------------------------------------------------------------------------ opening balance

    public function test_the_opening_balance_is_a_draft_journal_that_changes_no_ledger_figure_until_posted(): void
    {
        $client = $this->signedIn($this->tenant);
        $client->getJson(self::OB)->assertOk()->assertJsonPath('data', null);

        $saved = $client->putJson(self::OB, $this->openingBody())->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.balanced', true)->json('data');
        $this->assertSame('1500000.5000', $saved['total_debit']);
        $this->assertSame('0.0000', $saved['difference']);
        $this->assertSame('OPENING', $saved['journal']['journal_type']);
        $this->assertSame('DRAFT', $saved['journal']['status']);
        $this->assertNull($saved['journal']['journal_number']);
        $this->assertSame('2026-01-01', $saved['journal']['posting_date']);
        $this->assertNull(DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->value('cutover_date'));

        // Saving again replaces the draft in place (same document, new lines, new date).
        $again = $client->putJson(self::OB, $this->openingBody('2026-02-01', ['lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '10'], ['account_id' => $this->account($this->tenant, '3100')->id, 'credit' => '9'],
        ]]))->assertOk()->assertJsonPath('data.balanced', false)->json('data');
        $this->assertSame($saved['id'], $again['id']);
        $this->assertSame($saved['journal']['id'], $again['journal']['id']);
        $this->assertCount(2, $again['journal']['lines']);
        $this->assertSame('1.0000', $again['difference']);
        $this->assertSame(1, DB::table('opening_balances')->count());

        $client->postJson(self::OB.'/post')->assertStatus(422)->assertJsonPath('code', 'JOURNAL_UNBALANCED');
        $this->assertSame('DRAFT', DB::table('opening_balances')->value('status'));
        $this->assertSame(0, DB::table('document_sequences')->count());
    }

    public function test_posting_initializes_the_ledger_once_and_sets_the_cutover_date(): void
    {
        $client = $this->signedIn($this->tenant);
        $client->putJson(self::OB, $this->openingBody('2026-01-01'))->assertOk();
        $posted = $client->postJson(self::OB.'/post')->assertOk()->assertJsonPath('data.status', 'POSTED')->json('data');

        $this->assertSame('OB-FY2026-000001', $posted['journal']['journal_number']);
        $this->assertSame('POSTED', $posted['journal']['status']);
        $this->assertSame('2026-01-01', DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->value('cutover_date'));
        $this->assertSame('LOCKED', DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.opening_balance.posted')->count());

        // No second initialization, no edit, no cancel; the posted journal is as immutable as any other.
        $client->postJson(self::OB.'/post')->assertStatus(409)->assertJsonPath('code', 'OPENING_BALANCE_POSTED');
        $client->putJson(self::OB, $this->openingBody())->assertStatus(409)->assertJsonPath('code', 'OPENING_BALANCE_POSTED');
        $client->postJson(self::OB.'/cancel', ['reason' => 'x'])->assertStatus(404);
        $this->assertSame(1, DB::table('opening_balances')->count());

        // Postings before the cutover are refused from now on; on or after it they are fine.
        $this->profile(['approval_required' => false]);
        $before = $this->draft($this->tenant, ['posting_date' => '2025-12-31', 'document_date' => '2025-12-31']);
        $client->postJson(self::J."/{$before['id']}/post")->assertStatus(422); // no period in 2025 at all
        $this->assertSame(1, DB::table('journal_entries')->where('status', 'POSTED')->count());
        $this->profile(['cutover_date' => '2026-03-01']);
        $march = $this->draft($this->tenant, ['posting_date' => '2026-02-15', 'document_date' => '2026-02-15']);
        $client->postJson(self::J."/{$march['id']}/post")->assertStatus(422)->assertJsonPath('code', 'POSTING_BEFORE_CUTOVER');
    }

    public function test_it_cannot_be_posted_when_journals_already_exist_before_the_cutover_date(): void
    {
        $this->manualPosted('2026-01-20');
        $client = $this->signedIn($this->tenant);

        $client->putJson(self::OB, $this->openingBody('2026-02-01'))->assertOk();
        $client->postJson(self::OB.'/post')->assertStatus(409)->assertJsonPath('code', 'OPENING_BALANCE_HISTORY_EXISTS');
        $this->assertSame('DRAFT', DB::table('opening_balances')->value('status'));

        // A cutover on or before the first posted journal is fine, and later postings stay allowed.
        $client->putJson(self::OB, $this->openingBody('2026-01-20'))->assertOk();
        $client->postJson(self::OB.'/post')->assertOk()->assertJsonPath('data.status', 'POSTED');
    }

    public function test_the_generic_journal_workflow_cannot_touch_the_opening_journal(): void
    {
        $client = $this->signedIn($this->tenant);
        $journalId = $client->putJson(self::OB, $this->openingBody())->assertOk()->json('data.journal.id');

        foreach (['submit', 'approve', 'post', 'reopen'] as $action) {
            $client->postJson(self::J."/{$journalId}/{$action}")->assertStatus(409)->assertJsonPath('code', 'JOURNAL_NOT_MANUAL');
        }
        $client->postJson(self::J."/{$journalId}/cancel", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_NOT_MANUAL');
        $client->patchJson(self::J."/{$journalId}", ['description' => 'x'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_NOT_MANUAL');
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $journalId)->value('status'));
    }

    public function test_a_draft_can_be_cancelled_and_prepared_again(): void
    {
        $client = $this->signedIn($this->tenant);
        $client->postJson(self::OB.'/cancel', ['reason' => 'x'])->assertStatus(404);
        $first = $client->putJson(self::OB, $this->openingBody())->assertOk()->json('data');
        $client->postJson(self::OB.'/cancel', [])->assertStatus(422);
        $client->postJson(self::OB.'/cancel', ['reason' => 'Salah tanggal'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame('CANCELLED', DB::table('journal_entries')->where('id', $first['journal']['id'])->value('status'));

        $second = $client->putJson(self::OB, $this->openingBody('2026-02-01'))->assertOk()->json('data');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($second['id'], $client->getJson(self::OB)->json('data.id'));
        $this->assertSame(2, DB::table('opening_balances')->count());
    }

    public function test_reversing_a_posted_opening_balance_frees_the_tenant_to_initialize_again(): void
    {
        $client = $this->signedIn($this->tenant);
        $client->putJson(self::OB, $this->openingBody())->assertOk();
        $journalId = $client->postJson(self::OB.'/post')->assertOk()->json('data.journal.id');

        $client->postJson(self::J."/{$journalId}/reverse", ['reason' => 'Saldo awal salah'])->assertCreated()->assertJsonPath('journal_type', 'REVERSAL');
        $this->assertSame('REVERSED', DB::table('opening_balances')->value('status'));
        $this->assertNull(DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->value('cutover_date'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.opening_balance.reversed')->count());

        $client->putJson(self::OB, $this->openingBody('2026-01-01', ['reference' => 'SALDO-AWAL-2']))->assertOk()->assertJsonPath('data.status', 'DRAFT');
        $this->assertSame(2, DB::table('opening_balances')->count());
    }

    public function test_the_opening_date_must_fall_in_an_open_period(): void
    {
        $client = $this->signedIn($this->tenant);
        $client->putJson(self::OB, $this->openingBody('2027-01-01'))->assertOk();
        $client->postJson(self::OB.'/post')->assertStatus(422)->assertJsonPath('code', 'PERIOD_NOT_FOUND');

        DB::table('accounting_periods')->where('tenant_id', $this->tenant->id)->where('code', '2026-01')->update(['status' => 'CLOSED', 'closed_at' => now()]);
        $client->putJson(self::OB, $this->openingBody('2026-01-15'))->assertOk();
        $client->postJson(self::OB.'/post')->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame('DRAFT', DB::table('opening_balances')->value('status'));
        $this->assertNull(DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->value('cutover_date'));
    }

    public function test_opening_balance_permissions_scope_segregation_and_tenancy(): void
    {
        [$maker] = $this->member($this->tenant);
        $this->as($this->tenantToken($maker, $this->tenant))->putJson(self::OB, $this->openingBody())->assertOk();

        $this->signedIn($this->tenant, ['accounting.opening_balance.view'])->getJson(self::OB)->assertOk();
        $this->signedIn($this->tenant, ['accounting.opening_balance.view'])->putJson(self::OB, $this->openingBody())->assertStatus(403);
        $this->signedIn($this->tenant, ['accounting.opening_balance.view', 'accounting.opening_balance.manage'])->postJson(self::OB.'/post')->assertStatus(403);
        $this->signedIn($this->tenant, ['accounting.journal.view'])->getJson(self::OB)->assertStatus(403);

        // Another tenant sees nothing of it.
        $beta = $this->accountingTenant('beta');
        $this->signedIn($beta)->getJson(self::OB)->assertOk()->assertJsonPath('data', null);

        // A branch-scoped user cannot see or post a tenant-level opening balance.
        $branch = $this->inTenant($this->tenant, fn () => app(OrganizationService::class)->createBranch($this->tenant->id, ['code' => 'N', 'name' => 'North']));
        [$user, $membership] = $this->member($this->tenant, ['accounting.opening_balance.view', 'accounting.opening_balance.post']);
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->delete();
        DB::table('data_scopes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'tenant_user_id' => $membership->id, 'scope_type' => 'BRANCH', 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $scoped = $this->as($this->tenantToken($user, $this->tenant));
        $scoped->getJson(self::OB)->assertStatus(404);
        $scoped->postJson(self::OB.'/post')->assertStatus(404);

        // Segregation of duties covers the opening balance too.
        $this->profile(['sod_creator_not_poster' => true]);
        $this->as($this->tenantToken($maker, $this->tenant))->postJson(self::OB.'/post')->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->assertSame('DRAFT', DB::table('opening_balances')->value('status'));
        $this->signedIn($this->tenant, ['accounting.opening_balance.post'])->postJson(self::OB.'/post')->assertOk();
    }

    public function test_opening_lines_obey_the_same_money_and_account_rules_as_any_journal(): void
    {
        $client = $this->signedIn($this->tenant);
        $body = $this->openingBody();
        $body['lines'][0]['debit'] = 1000.5; // float
        $client->putJson(self::OB, $body)->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');

        $body = $this->openingBody();
        $body['lines'][0]['account_id'] = $this->account($this->tenant, '1100')->id; // header account
        $client->putJson(self::OB, $body)->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_POSTABLE');

        $client->putJson(self::OB, ['cutover_date' => '2026-01-01', 'lines' => [$this->openingBody()['lines'][0]]])->assertStatus(422); // two lines minimum
        $this->assertSame(0, DB::table('opening_balances')->count());
    }

    // ------------------------------------------------------------------------------------------ reversal

    public function test_a_reversal_is_a_new_balanced_journal_with_swapped_sides_and_the_original_stays_posted(): void
    {
        $id = $this->manualPosted('2026-03-10', ['description' => 'Penjualan tunai']);
        $client = $this->signedIn($this->tenant);
        $linesOf = fn (string $journal) => DB::table('journal_lines')->where('journal_entry_id', $journal)->orderBy('line_number')->get(['account_id', 'debit', 'credit'])->map(fn ($l) => (array) $l)->all();
        $originalLines = $linesOf($id);

        $reversal = $client->postJson(self::J."/{$id}/reverse", ['reason' => 'Salah akun', 'posting_date' => '2026-03-12'])->assertCreated()->json();
        $this->assertSame('REVERSAL', $reversal['journal_type']);
        $this->assertSame('POSTED', $reversal['status']);
        $this->assertSame('RV-FY2026-000001', $reversal['journal_number']);
        $this->assertSame($id, $reversal['reverses_journal_id']);
        $this->assertSame('Salah akun', $reversal['reversal_reason']);
        $this->assertSame('2026-03-12', $reversal['posting_date']);
        $this->assertSame('100000.0000', $reversal['total_debit']);

        $swapped = array_map(fn ($l) => ['account_id' => $l['account_id'], 'debit' => $l['credit'], 'credit' => $l['debit']], $originalLines);
        $this->assertEquals($swapped, $linesOf($reversal['id']));

        $original = DB::table('journal_entries')->where('id', $id)->first();
        $this->assertSame('POSTED', $original->status);
        $this->assertSame($reversal['id'], $original->reversed_by_journal_id);
        $this->assertEquals($originalLines, $linesOf($id), 'the original lines are untouched');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.journal.reversed')->where('resource_id', $id)->count());
        $this->assertSame(['DRAFT', 'POSTED'], DB::table('journal_transitions')->where('journal_entry_id', $reversal['id'])->orderBy('occurred_at')->orderBy('id')->pluck('to_status')->all());
    }

    public function test_a_journal_is_reversed_at_most_once_and_a_reversal_is_never_reversed(): void
    {
        $id = $this->manualPosted();
        $client = $this->signedIn($this->tenant);
        $reversalId = $client->postJson(self::J."/{$id}/reverse", ['reason' => 'x'])->assertCreated()->json('id');

        $client->postJson(self::J."/{$id}/reverse", ['reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_ALREADY_REVERSED');
        $client->postJson(self::J."/{$reversalId}/reverse", ['reason' => 'undo'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_IS_REVERSAL');
        $this->assertSame(1, DB::table('journal_entries')->where('journal_type', 'REVERSAL')->count());
        $this->assertSame(1, DB::table('document_sequences')->where('sequence_code', 'JOURNAL.REVERSAL')->value('last_value'));
    }

    public function test_only_posted_journals_can_be_reversed_with_a_valid_reason_and_date(): void
    {
        $client = $this->signedIn($this->tenant);
        $draft = $this->draft($this->tenant)['id'];
        $client->postJson(self::J."/{$draft}/reverse", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_NOT_POSTED');

        $id = $this->manualPosted('2026-03-10');
        $client->postJson(self::J."/{$id}/reverse", [])->assertStatus(422);
        $client->postJson(self::J."/{$id}/reverse", ['reason' => str_repeat('x', 501)])->assertStatus(422);
        $client->postJson(self::J."/{$id}/reverse", ['reason' => 'x', 'posting_date' => '2026-03-09'])->assertStatus(422)->assertJsonPath('code', 'REVERSAL_DATE_INVALID');
        $this->assertNull(DB::table('journal_entries')->where('id', $id)->value('reversed_by_journal_id'));
    }

    public function test_a_reversal_into_a_closed_period_fails_atomically(): void
    {
        $id = $this->manualPosted('2026-03-10');
        DB::table('accounting_periods')->where('tenant_id', $this->tenant->id)->where('code', '2026-04')->update(['status' => 'CLOSED', 'closed_at' => now()]);
        $client = $this->signedIn($this->tenant);

        $client->postJson(self::J."/{$id}/reverse", ['reason' => 'x', 'posting_date' => '2026-04-05'])->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame(0, DB::table('journal_entries')->where('journal_type', 'REVERSAL')->count());
        $this->assertNull(DB::table('journal_entries')->where('id', $id)->value('reversed_by_journal_id'));
        $this->assertSame(0, DB::table('document_sequences')->where('sequence_code', 'JOURNAL.REVERSAL')->count());

        $client->postJson(self::J."/{$id}/reverse", ['reason' => 'x'])->assertCreated(); // the same month is still open
    }

    public function test_event_journals_can_be_reversed_too_and_the_database_allows_one_reversal_only(): void
    {
        $system = $this->postedJournal($this->tenant);
        $client = $this->signedIn($this->tenant);
        $reversal = $client->postJson(self::J."/{$system->id}/reverse", ['reason' => 'Dokumen sumber dibatalkan'])->assertCreated()->json();

        // The link is one-time and only to the posted reversal of exactly this journal.
        $second = $this->postedJournal($this->tenant);
        $this->assertDbRefuses(fn () => DB::table('journal_entries')->where('id', $second->id)->update(['reversed_by_journal_id' => $reversal['id']]));
        $this->assertDbRefuses(fn () => DB::table('journal_entries')->where('id', $system->id)->update(['reversed_by_journal_id' => $second->id]));
        $this->assertDbRefuses(fn () => DB::table('journal_entries')->where('id', $second->id)->update(['reversed_by_journal_id' => $second->id]));
        $this->assertSame(1, DB::selectOne("select count(*) c from pg_indexes where indexname = 'journal_entries_one_reversal'")->c);
    }

    private function assertDbRefuses(callable $work): void
    {
        try {
            DB::transaction(fn () => $work());
            $this->fail('The database accepted a change it must refuse.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0] ?? null, ['23514', '23505']);
        }
    }
}
