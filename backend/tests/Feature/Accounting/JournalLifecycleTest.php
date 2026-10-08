<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\Services\DocumentNumbering;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use App\Domain\Shared\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA1 batches E-F: manual journal drafts, lifecycle, approval, segregation of duties, numbering, immutability. */
class JournalLifecycleTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const J = '/api/v1/app/accounting/journals';

    private const MAKER = ['accounting.journal.view', 'accounting.journal.create', 'accounting.journal.update', 'accounting.journal.submit'];

    private const CHECKER = ['accounting.journal.view', 'accounting.journal.approve', 'accounting.journal.post'];

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

    /** Draft -> submitted -> approved by a second person; returns the journal id. */
    private function approved(array $override = []): string
    {
        $maker = $this->signedIn($this->tenant, self::MAKER);
        $id = $this->draft($this->tenant, $override)['id'];
        $maker->postJson(self::J."/{$id}/submit")->assertOk();
        $this->signedIn($this->tenant, self::CHECKER)->postJson(self::J."/{$id}/approve")->assertOk();

        return $id;
    }

    public function test_totals_and_status_are_computed_by_the_server_whatever_the_client_sends(): void
    {
        $this->signedIn($this->tenant);
        $body = $this->journalBody($this->tenant) + ['total_debit' => '1', 'total_credit' => '2', 'status' => 'POSTED', 'journal_number' => 'HACK-1', 'created_by' => 'x', 'tenant_id' => (string) Str::uuid()];
        $journal = $this->postJson(self::J, $body)->assertCreated()->json();

        $this->assertSame('DRAFT', $journal['status']);
        $this->assertSame('100000.0000', $journal['total_debit']);
        $this->assertSame('100000.0000', $journal['total_credit']);
        $this->assertNull($journal['journal_number']);
        $this->assertSame($this->tenant->id, $journal['tenant_id']);
        $this->assertSame('MANUAL', $journal['journal_type']);
        $this->assertSame('IDR', $journal['currency']);
        $this->assertSame([1, 2], array_column($journal['lines'], 'line_number'));
        $this->assertSame('1110', $journal['lines'][0]['account']['code']);
    }

    public function test_line_amounts_are_exact_decimals_with_one_positive_side(): void
    {
        $this->signedIn($this->tenant);
        $cash = $this->account($this->tenant, '1110')->id;
        $revenue = $this->account($this->tenant, '4100')->id;
        $post = fn (array $lines) => $this->postJson(self::J, $this->journalBody($this->tenant, ['lines' => $lines]));

        $post([['account_id' => $cash, 'debit' => 100.5], ['account_id' => $revenue, 'credit' => '100.50']])->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID'); // JSON float
        $post([['account_id' => $cash, 'debit' => '100.555'], ['account_id' => $revenue, 'credit' => '100.555']])->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID'); // beyond IDR scale 2
        $post([['account_id' => $cash, 'debit' => '-5'], ['account_id' => $revenue, 'credit' => '5']])->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $post([['account_id' => $cash, 'debit' => '5', 'credit' => '5'], ['account_id' => $revenue, 'credit' => '5']])->assertStatus(422)->assertJsonPath('code', 'LINE_AMOUNT_INVALID');
        $post([['account_id' => $cash, 'debit' => '0'], ['account_id' => $revenue, 'credit' => '5']])->assertStatus(422)->assertJsonPath('code', 'LINE_AMOUNT_INVALID')->assertJsonPath('details.line', 1);
        $post([['account_id' => $cash, 'debit' => '1e3'], ['account_id' => $revenue, 'credit' => '5']])->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');

        $ok = $post([['account_id' => $cash, 'debit' => '0.10'], ['account_id' => $cash, 'debit' => '0.20'], ['account_id' => $revenue, 'credit' => '0.30']])->assertCreated()->json();
        $this->assertSame('0.3000', $ok['total_debit']); // 0.1 + 0.2 is exactly 0.3
        $this->assertSame(0, $this->rows('journal_entries', ['description' => 'x']));
    }

    public function test_accounts_must_be_active_tenant_owned_posting_accounts(): void
    {
        $this->signedIn($this->tenant);
        $other = $this->accountingTenant('beta');
        $revenue = $this->account($this->tenant, '4100')->id;
        $line = fn (string $account) => [['account_id' => $account, 'debit' => '10'], ['account_id' => $revenue, 'credit' => '10']];
        $post = fn (array $lines) => $this->postJson(self::J, $this->journalBody($this->tenant, ['lines' => $lines]));

        $post($line($this->account($this->tenant, '1100')->id))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_POSTABLE'); // header
        $post($line($this->account($this->tenant, '1130')->id))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_CONTROL_RESTRICTED'); // control account, manual
        $post($line($this->account($other, '1110')->id))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND'); // another tenant
        $post($line((string) Str::uuid()))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');

        $this->inTenant($this->tenant, fn () => DB::table('accounts')->where('id', $this->account($this->tenant, '6500')->id)->update(['status' => 'INACTIVE']));
        $post($line($this->account($this->tenant, '6500')->id))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertSame(0, $this->rows('journal_entries', ['tenant_id' => $this->tenant->id]));
    }

    public function test_dimensions_must_belong_to_the_tenant_and_be_active(): void
    {
        $org = app(OrganizationService::class);
        $branch = $this->inTenant($this->tenant, fn () => $org->createBranch($this->tenant->id, ['code' => 'JKT', 'name' => 'Jakarta']));
        $other = $this->accountingTenant('beta');
        $foreignBranch = $this->inTenant($other, fn () => $org->createBranch($other->id, ['code' => 'SBY', 'name' => 'Surabaya']));
        $center = $this->asMember($this->tenant)->postJson('/api/v1/app/accounting/cost-centers', ['code' => 'OPS', 'name' => 'Ops'])->json('id');
        $foreignCenter = $this->asMember($other)->postJson('/api/v1/app/accounting/cost-centers', ['code' => 'OPS', 'name' => 'Ops'])->json('id');

        $this->signedIn($this->tenant);
        $with = fn (array $extra) => $this->postJson(self::J, $this->journalBody($this->tenant, ['lines' => $this->lines($this->tenant, '10', dimensions: $extra)]));

        $created = $with(['branch_id' => $branch->id, 'cost_center_id' => $center])->assertCreated();
        $this->assertSame('JKT', $created->json('lines.0.branch.code'));
        $this->assertSame('OPS', $created->json('lines.1.cost_center.code'));
        $with(['branch_id' => $foreignBranch->id])->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');
        $with(['cost_center_id' => $foreignCenter])->assertStatus(422)->assertJsonPath('code', 'DIMENSION_NOT_FOUND');
        $with(['dimensions' => [['type' => 'VEHICLE', 'reference_id' => 'B-1234']]])->assertStatus(422)->assertJsonPath('code', 'DIMENSION_INVALID'); // not registered yet (OA6)

        // A registered external dimension type is stored by reference on the line, without any schema change.
        DB::table('dimension_types')->insert(['code' => 'VEHICLE', 'name' => 'Kendaraan', 'kind' => 'EXTERNAL', 'status' => 'ACTIVE', 'sort_order' => 90, 'created_at' => now(), 'updated_at' => now()]);
        $vehicle = $with(['dimensions' => [['type' => 'VEHICLE', 'reference_id' => 'B-1234', 'reference_label' => 'Truk 12']]])->assertCreated();
        $this->assertSame('B-1234', $vehicle->json('lines.0.dimensions.0.reference_id'));
        $with(['dimensions' => [['type' => 'VEHICLE', 'reference_id' => 'A'], ['type' => 'VEHICLE', 'reference_id' => 'B']]])->assertStatus(422)->assertJsonPath('code', 'DIMENSION_INVALID');
    }

    public function test_draft_can_be_edited_and_cancelled_and_every_move_is_recorded(): void
    {
        $this->signedIn($this->tenant);
        $id = $this->draft($this->tenant)['id'];

        $edited = $this->patchJson(self::J."/{$id}", ['description' => 'Revisi', 'lines' => $this->lines($this->tenant, '250000.50')])->assertOk()->json();
        $this->assertSame('Revisi', $edited['description']);
        $this->assertSame('250000.5000', $edited['total_debit']);
        $this->assertCount(2, $edited['lines']);
        $this->assertSame(2, $this->rows('journal_lines', ['journal_entry_id' => $id]));

        $this->postJson(self::J."/{$id}/cancel", [])->assertStatus(422);
        $this->postJson(self::J."/{$id}/cancel", ['reason' => 'Salah input'])->assertOk()->assertJsonPath('status', 'CANCELLED')->assertJsonPath('cancel_reason', 'Salah input');
        $this->patchJson(self::J."/{$id}", ['description' => 'x'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_NOT_DRAFT');
        $this->postJson(self::J."/{$id}/submit")->assertStatus(409)->assertJsonPath('code', 'JOURNAL_INVALID_TRANSITION');

        $this->assertSame([[null, 'DRAFT'], ['DRAFT', 'CANCELLED']], DB::table('journal_transitions')->where('journal_entry_id', $id)->orderBy('occurred_at')->orderBy('id')->get(['from_status', 'to_status'])->map(fn ($r) => [$r->from_status, $r->to_status])->all());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'accounting.journal.posted')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.journal.cancelled')->count());
    }

    public function test_submit_validates_the_balance_and_reject_reopen_resubmit_works(): void
    {
        $maker = $this->signedIn($this->tenant, self::MAKER);
        $unbalanced = $this->draft($this->tenant, ['lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '100'], ['account_id' => $this->account($this->tenant, '4100')->id, 'credit' => '90'],
        ]]);
        $this->assertSame('100.0000', $unbalanced['total_debit']);
        $maker->postJson(self::J."/{$unbalanced['id']}/submit")->assertStatus(422)->assertJsonPath('code', 'JOURNAL_UNBALANCED');
        $oneLine = $this->draft($this->tenant, ['lines' => [['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '100']]]);
        $maker->postJson(self::J."/{$oneLine['id']}/submit")->assertStatus(422)->assertJsonPath('code', 'JOURNAL_TOO_FEW_LINES');
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $unbalanced['id'])->value('status'));

        $id = $this->draft($this->tenant)['id'];
        $maker->postJson(self::J."/{$id}/submit")->assertOk()->assertJsonPath('status', 'SUBMITTED');
        $maker->patchJson(self::J."/{$id}", ['description' => 'x'])->assertStatus(409);

        $checker = $this->signedIn($this->tenant, self::CHECKER);
        $checker->postJson(self::J."/{$id}/reject", [])->assertStatus(422);
        $checker->postJson(self::J."/{$id}/reject", ['reason' => 'Lampiran kurang'])->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('reject_reason', 'Lampiran kurang');
        $checker->postJson(self::J."/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'JOURNAL_NOT_POSTABLE');

        $maker = $this->signedIn($this->tenant, self::MAKER);
        $maker->postJson(self::J."/{$id}/reopen")->assertOk()->assertJsonPath('status', 'DRAFT');
        $maker->patchJson(self::J."/{$id}", ['reference' => 'Lampiran ok'])->assertOk();
        $maker->postJson(self::J."/{$id}/submit")->assertOk();
    }

    public function test_full_approval_flow_posts_with_a_number_and_a_complete_trail(): void
    {
        $id = $this->approved();
        $poster = $this->signedIn($this->tenant, self::CHECKER);

        $posted = $poster->postJson(self::J."/{$id}/post")->assertOk()->json();
        $this->assertSame('POSTED', $posted['status']);
        $this->assertSame('JV-FY2026-000001', $posted['journal_number']);
        $this->assertSame('2026-03', DB::table('accounting_periods')->where('id', $posted['accounting_period_id'])->value('code'));
        $this->assertNotNull($posted['posted_at']);
        $this->assertSame(['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED'], DB::table('journal_transitions')->where('journal_entry_id', $id)->orderBy('occurred_at')->orderBy('id')->pluck('to_status')->all());
        $this->assertSame('LOCKED', DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.journal.posted')->where('resource_id', $id)->count());

        $poster->postJson(self::J."/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'JOURNAL_ALREADY_POSTED');
        $this->assertSame(1, DB::table('journal_entries')->where('status', 'POSTED')->count());
    }

    public function test_segregation_of_duties_follows_the_profile_policy_not_role_names(): void
    {
        $everything = $this->signedIn($this->tenant);
        $id = $this->draft($this->tenant)['id'];
        $everything->postJson(self::J."/{$id}/submit")->assertOk();
        $everything->postJson(self::J."/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION'); // creator may not approve
        $this->assertSame('SUBMITTED', DB::table('journal_entries')->where('id', $id)->value('status'));

        $this->profile(['sod_creator_not_approver' => false]);
        $everything->postJson(self::J."/{$id}/approve")->assertOk();
        $everything->postJson(self::J."/{$id}/post")->assertOk(); // creator posting is allowed by default

        $id2 = $this->draft($this->tenant)['id'];
        $this->profile(['sod_creator_not_poster' => true, 'sod_creator_not_approver' => false]);
        $everything->postJson(self::J."/{$id2}/submit")->assertOk();
        $everything->postJson(self::J."/{$id2}/approve")->assertOk();
        $everything->postJson(self::J."/{$id2}/post")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->signedIn($this->tenant, self::CHECKER)->postJson(self::J."/{$id2}/post")->assertOk();

        $everything = $this->signedIn($this->tenant); // signedIn() returns the same client, so sign in again as the maker
        $id3 = $this->draft($this->tenant)['id'];
        $this->profile(['sod_creator_not_poster' => false, 'sod_approver_not_poster' => true]);
        $everything->postJson(self::J."/{$id3}/submit")->assertOk();
        $everything->postJson(self::J."/{$id3}/approve")->assertOk();
        $everything->postJson(self::J."/{$id3}/post")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
    }

    public function test_draft_posts_directly_only_when_the_policy_has_no_approval_step(): void
    {
        $client = $this->signedIn($this->tenant);
        $id = $this->draft($this->tenant)['id'];
        $client->postJson(self::J."/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'JOURNAL_APPROVAL_REQUIRED');

        $this->profile(['approval_required' => false]);
        $client->postJson(self::J."/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED');
        $this->assertSame(['DRAFT', 'POSTED'], DB::table('journal_transitions')->where('journal_entry_id', $id)->orderBy('occurred_at')->orderBy('id')->pluck('to_status')->all());
    }

    public function test_an_unbalanced_draft_can_never_post_not_even_without_approval(): void
    {
        $this->profile(['approval_required' => false]);
        $client = $this->signedIn($this->tenant);
        $id = $this->draft($this->tenant, ['lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '100'], ['account_id' => $this->account($this->tenant, '4100')->id, 'credit' => '99.99'],
        ]])['id'];

        $client->postJson(self::J."/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'JOURNAL_UNBALANCED');
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('journal_entries')->where('id', $id)->value('journal_number'));
        $this->assertSame(0, DB::table('document_sequences')->count()); // no number was consumed
    }

    public function test_posted_journals_are_immutable_in_the_service_and_in_the_database(): void
    {
        $id = $this->approved();
        $client = $this->signedIn($this->tenant, [...self::CHECKER, 'accounting.journal.update', 'accounting.journal.submit']);
        $client->postJson(self::J."/{$id}/post")->assertOk();

        $client->patchJson(self::J."/{$id}", ['description' => 'x'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_IMMUTABLE');
        $client->postJson(self::J."/{$id}/cancel", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'JOURNAL_IMMUTABLE');
        $client->postJson(self::J."/{$id}/reopen")->assertStatus(409);
        $client->postJson(self::J."/{$id}/submit")->assertStatus(409);
        $client->deleteJson(self::J."/{$id}")->assertStatus(405);

        $line = DB::table('journal_lines')->where('journal_entry_id', $id)->first();
        $attempts = [
            fn () => DB::table('journal_entries')->where('id', $id)->update(['description' => 'tampered']),
            fn () => DB::table('journal_entries')->where('id', $id)->update(['status' => 'DRAFT']),
            fn () => DB::table('journal_entries')->where('id', $id)->update(['total_debit' => 1, 'total_credit' => 1]),
            fn () => DB::table('journal_entries')->where('id', $id)->update(['journal_number' => 'JV-FY2026-999999']),
            fn () => DB::table('journal_entries')->where('id', $id)->delete(),
            fn () => DB::table('journal_lines')->where('id', $line->id)->update(['debit' => 1, 'credit' => 0]),
            fn () => DB::table('journal_lines')->where('id', $line->id)->delete(),
            fn () => DB::table('journal_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $line->tenant_id, 'journal_entry_id' => $id, 'line_number' => 9, 'account_id' => $line->account_id,
                'debit' => 5, 'credit' => 0, 'transaction_currency' => 'IDR', 'transaction_debit' => 5, 'transaction_credit' => 0, 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('journal_transitions')->where('journal_entry_id', $id)->delete(),
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("database accepted tampering #{$i}");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0], "attempt #{$i}: ".$e->getMessage());
            }
        }

        $this->assertSame('Penjualan tunai', DB::table('journal_entries')->where('id', $id)->value('description'));
        $this->assertSame(2, $this->rows('journal_lines', ['journal_entry_id' => $id]));
    }

    public function test_the_database_alone_refuses_an_unbalanced_or_invalid_posting(): void
    {
        $this->signedInTenant();
        $id = $this->draft($this->tenant)['id'];
        $period = $this->period($this->tenant, '2026-03');
        $post = fn (array $extra = []) => DB::transaction(fn () => DB::table('journal_entries')->where('id', $id)->update($extra + [
            'status' => 'POSTED', 'journal_number' => 'JV-FY2026-000777', 'posted_at' => now(), 'fiscal_year_id' => $period->fiscal_year_id, 'accounting_period_id' => $period->id,
        ]));

        // The totals check on the header is satisfied (the draft total is right) but the lines are changed to be unbalanced first.
        DB::table('journal_lines')->where('journal_entry_id', $id)->where('line_number', 2)->update(['credit' => 99000, 'transaction_credit' => 99000]);
        $this->assertDbRefuses($post);
        DB::table('journal_lines')->where('journal_entry_id', $id)->where('line_number', 2)->update(['credit' => 100000, 'transaction_credit' => 100000]);

        $this->assertDbRefuses(fn () => $post(['status' => 'APPROVED'])); // DRAFT -> APPROVED is not a legal move
        $closed = $this->period($this->tenant, '2026-01');
        $this->assertDbRefuses(fn () => DB::transaction(fn () => DB::table('journal_entries')->where('id', $id)->update([ // period does not cover the posting date
            'status' => 'POSTED', 'journal_number' => 'JV-FY2026-000778', 'posted_at' => now(), 'fiscal_year_id' => $closed->fiscal_year_id, 'accounting_period_id' => $closed->id,
        ])));
        $this->assertDbRefuses(fn () => $post(['total_debit' => 5])); // header totals must equal the lines

        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));
        $this->assertSame(1, $post());
        $this->assertSame('POSTED', DB::table('journal_entries')->where('id', $id)->value('status'));
    }

    public function test_closed_and_soft_closed_periods_reject_posting_atomically(): void
    {
        $id = $this->approved(['posting_date' => '2026-03-20']);
        $id2 = $this->approved(['posting_date' => '2026-03-21']);
        $march = $this->period($this->tenant, '2026-03');
        $poster = $this->signedIn($this->tenant, self::CHECKER);

        $this->inTenant($this->tenant, fn () => app(FiscalCalendarService::class)->transitionPeriod($march, 'SOFT_CLOSED'));
        $poster->postJson(self::J."/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_SOFT_CLOSED');
        $this->signedIn($this->tenant, [...self::CHECKER, 'accounting.journal.post_soft_closed'])->postJson(self::J."/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED');

        // The period is closed behind the application's back (another process / manual SQL): posting still refuses.
        DB::table('accounting_periods')->where('id', $march->id)->update(['status' => 'CLOSED']);
        $this->signedIn($this->tenant, [...self::CHECKER, 'accounting.journal.post_soft_closed'])->postJson(self::J."/{$id2}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame('APPROVED', DB::table('journal_entries')->where('id', $id2)->value('status'));
        $this->assertSame(1, DB::table('journal_entries')->where('status', 'POSTED')->count());
        $this->assertSame(1, (int) DB::table('document_sequences')->value('last_value')); // the failed attempt consumed no number
    }

    public function test_a_period_with_pending_journals_cannot_be_hard_closed_and_a_missing_period_rejects_posting(): void
    {
        $id = $this->approved(['posting_date' => '2026-04-10']);
        $april = $this->period($this->tenant, '2026-04');
        $this->inTenant($this->tenant, function () use ($april) {
            try {
                app(FiscalCalendarService::class)->transitionPeriod($april, 'CLOSED');
                $this->fail('pending journals must block closing');
            } catch (DomainException $e) {
                $this->assertSame('PERIOD_HAS_PENDING_JOURNALS', $e->errorCode);
            }
        });

        $this->signedIn($this->tenant, self::MAKER);
        $future = $this->draft($this->tenant, ['posting_date' => '2027-02-01', 'document_date' => '2027-02-01'])['id'];
        $this->signedIn($this->tenant, self::MAKER)->postJson(self::J."/{$future}/submit")->assertStatus(422)->assertJsonPath('code', 'PERIOD_NOT_FOUND');
        unset($id);
    }

    public function test_journal_numbers_are_sequential_per_type_and_fiscal_year_and_never_reused(): void
    {
        $a = $this->approved();
        $b = $this->approved();
        $poster = $this->signedIn($this->tenant, self::CHECKER);
        $this->assertSame('JV-FY2026-000001', $poster->postJson(self::J."/{$a}/post")->assertOk()->json('journal_number'));
        $this->assertSame('JV-FY2026-000002', $poster->postJson(self::J."/{$b}/post")->assertOk()->json('journal_number'));

        // A rolled-back posting returns its number (gapless); a committed one is never reissued.
        try {
            DB::transaction(function () {
                $this->inTenant($this->tenant, fn () => app(DocumentNumbering::class)->issue('JOURNAL.MANUAL', 'k', 'JV', 'FY2026'));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, (int) DB::table('document_sequences')->where('sequence_code', 'JOURNAL.MANUAL')->where('scope_key', DB::table('fiscal_years')->value('id'))->value('last_value'));
        $this->assertSame(0, DB::table('document_sequences')->where('scope_key', 'k')->count());

        // Other journal types have their own sequence; a new fiscal year restarts numbering.
        $this->assertSame('SJ-FY2026-000001', $this->postedJournal($this->tenant)->journal_number);
        $this->inTenant($this->tenant, function () {
            $calendar = app(FiscalCalendarService::class);
            $calendar->openFiscalYear($calendar->createFiscalYear(['code' => 'FY2027', 'name' => '2027', 'start_date' => '2027-01-01']));
        });
        $this->assertSame('SJ-FY2027-000001', $this->postedJournal($this->tenant, ['posting_date' => '2027-01-05', 'document_date' => '2027-01-05'])->journal_number);
    }

    public function test_a_tenant_never_sees_or_touches_another_tenants_journals(): void
    {
        $this->signedIn($this->tenant);
        $id = $this->draft($this->tenant)['id'];
        $other = $this->accountingTenant('beta');
        $client = $this->signedIn($other);

        $client->getJson(self::J."/{$id}")->assertNotFound();
        $client->patchJson(self::J."/{$id}", ['description' => 'x'])->assertNotFound();
        $client->postJson(self::J."/{$id}/submit")->assertNotFound();
        $client->postJson(self::J."/{$id}/approve")->assertNotFound();
        $client->postJson(self::J."/{$id}/post")->assertNotFound();
        $client->postJson(self::J."/{$id}/cancel", ['reason' => 'x'])->assertNotFound();
        $client->postJson(self::J."/{$id}/reverse", ['reason' => 'x'])->assertNotFound();
        $this->assertSame([], $client->getJson(self::J)->json('data'));
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $id)->value('status'));
    }

    public function test_journal_permissions_are_checked_per_action(): void
    {
        $this->signedInTenant();
        $id = $this->draft($this->tenant)['id'];
        $this->signedIn($this->tenant, ['accounting.journal.view'])->getJson(self::J."/{$id}")->assertOk();
        foreach (['submit', 'approve', 'post', 'reopen'] as $action) {
            $this->signedIn($this->tenant, ['accounting.journal.view'])->postJson(self::J."/{$id}/{$action}")->assertStatus(403);
        }
        $this->signedIn($this->tenant, ['accounting.journal.view'])->postJson(self::J, $this->journalBody($this->tenant))->assertStatus(403);
        $this->signedIn($this->tenant, ['accounting.journal.view'])->patchJson(self::J."/{$id}", ['description' => 'x'])->assertStatus(403);
        $this->signedIn($this->tenant, ['accounting.journal.view'])->postJson(self::J."/{$id}/reverse", ['reason' => 'x'])->assertStatus(403);
        $this->signedIn($this->tenant, ['organization.view'])->getJson(self::J)->assertStatus(403);
    }

    public function test_listing_filters_and_paginates_within_the_tenant(): void
    {
        $client = $this->signedIn($this->tenant);
        $this->draft($this->tenant, ['description' => 'Alpha satu', 'posting_date' => '2026-02-01', 'document_date' => '2026-02-01']);
        $this->draft($this->tenant, ['description' => 'Beta dua', 'reference' => 'REF-BETA']);
        $this->postedJournal($this->tenant);

        $this->assertSame(3, $client->getJson(self::J)->json('total'));
        $this->assertSame(['Alpha satu'], array_column($client->getJson(self::J.'?q=alpha')->json('data'), 'description'));
        $this->assertSame(['Beta dua'], array_column($client->getJson(self::J.'?q=ref-beta')->json('data'), 'description'));
        $this->assertSame(1, $client->getJson(self::J.'?status=POSTED')->json('total'));
        $this->assertSame(2, $client->getJson(self::J.'?from=2026-03-01&to=2026-03-31')->json('total'));
        $this->assertCount(1, $client->getJson(self::J.'?per_page=1')->json('data'));
        $client->getJson(self::J.'?status=BOGUS')->assertStatus(422);
    }

    public function test_data_scope_hides_journals_that_touch_other_branches(): void
    {
        $org = app(OrganizationService::class);
        [$north, $south] = $this->inTenant($this->tenant, fn () => [
            $org->createBranch($this->tenant->id, ['code' => 'N', 'name' => 'North']), $org->createBranch($this->tenant->id, ['code' => 'S', 'name' => 'South']),
        ]);
        $admin = $this->signedIn($this->tenant);
        $northJournal = $this->draft($this->tenant, ['lines' => $this->lines($this->tenant, '10', dimensions: ['branch_id' => $north->id])])['id'];
        $southJournal = $this->draft($this->tenant, ['lines' => $this->lines($this->tenant, '20', dimensions: ['branch_id' => $south->id])])['id'];
        $mixed = $this->draft($this->tenant, ['lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '5', 'branch_id' => $north->id], ['account_id' => $this->account($this->tenant, '4100')->id, 'credit' => '5', 'branch_id' => $south->id],
        ]])['id'];
        $unassigned = $this->draft($this->tenant)['id'];

        [$northUser, $membership] = $this->member($this->tenant, ['accounting.journal.view', 'accounting.journal.create', 'accounting.journal.update']);
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->delete();
        DB::table('data_scopes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'tenant_user_id' => $membership->id, 'scope_type' => 'BRANCH', 'branch_id' => $north->id, 'created_at' => now(), 'updated_at' => now()]);
        $client = $this->as($this->tenantToken($northUser, $this->tenant));

        $this->assertSame([$northJournal], array_column($client->getJson(self::J)->json('data'), 'id'));
        $client->getJson(self::J."/{$northJournal}")->assertOk();
        foreach ([$southJournal, $mixed, $unassigned] as $hidden) {
            $client->getJson(self::J."/{$hidden}")->assertNotFound();
            $client->patchJson(self::J."/{$hidden}", ['description' => 'x'])->assertNotFound();
        }
        $client->postJson(self::J, $this->journalBody($this->tenant, ['lines' => $this->lines($this->tenant, '10', dimensions: ['branch_id' => $south->id])]))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED');
        $client->postJson(self::J, $this->journalBody($this->tenant))->assertStatus(403)->assertJsonPath('code', 'DATA_SCOPE_DENIED'); // no branch on the lines
        $client->postJson(self::J, $this->journalBody($this->tenant, ['lines' => $this->lines($this->tenant, '10', dimensions: ['branch_id' => $north->id])]))->assertCreated();
        unset($admin);
    }

    // -------------------------------------------------------------------------------------------- helpers

    private function signedInTenant(): static
    {
        return $this->signedIn($this->tenant);
    }

    private function assertDbRefuses(callable $work): void
    {
        try {
            DB::transaction(fn () => $work());
            $this->fail('the database accepted an invalid change');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0], $e->getMessage());
        }
    }
}
