<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\Services\AccountMappingService;
use App\Domain\Accounting\Services\PostingEngine;
use App\Domain\Accounting\Services\PostingRuleService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use App\Domain\Shared\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA1 batches G-H: posting rules, account mappings, the accounting event foundation and the event posting path. */
class PostingRulesAndEventsTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const RULES = '/api/v1/app/accounting/posting-rules';

    private const MAPPINGS = '/api/v1/app/accounting/account-mappings';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->accountingTenant();
    }

    private function expenseRuleBody(string $code = 'EXP'): array
    {
        return ['code' => $code, 'event_type' => 'EXPENSE_RECOGNIZED', 'name' => 'Beban diakui', 'lines' => [
            ['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'net'],
            ['side' => 'DEBIT', 'account_role' => 'TAX_RECEIVABLE', 'amount_key' => 'tax'],
            ['side' => 'CREDIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'total'],
        ]];
    }

    /** Create and publish the expense rule through the API; returns the published rule. */
    private function publishedRule(string $from = '2026-01-01', string $code = 'EXP'): array
    {
        $client = $this->signedIn($this->tenant);
        $rule = $client->postJson(self::RULES, $this->expenseRuleBody($code))->assertCreated()->json();

        return $client->postJson(self::RULES."/{$rule['id']}/publish", ['effective_from' => $from])->assertOk()->json();
    }

    private function postExpense(string $id = 'EXP-1', array $payload = ['net' => '1000', 'tax' => '110', 'total' => '1110'], string $date = '2026-03-10', array $dimensions = [])
    {
        return $this->inTenant($this->tenant, fn () => app(PostingEngine::class)->postEvent('EXPENSE_RECOGNIZED', 'TEST_DOC', $id, $date, $payload, $dimensions));
    }

    // ------------------------------------------------------------------------------------------ rules

    public function test_a_rule_is_created_as_a_draft_validated_and_published_with_an_effective_window(): void
    {
        $client = $this->signedIn($this->tenant);

        $body = $this->expenseRuleBody();
        $body['lines'][0]['amount_key'] = 'amount'; // not a component of EXPENSE_RECOGNIZED
        $client->postJson(self::RULES, $body)->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_COMPONENT_INVALID');
        $body = $this->expenseRuleBody();
        $body['lines'][0]['account_role'] = 'NOT_A_ROLE';
        $client->postJson(self::RULES, $body)->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_ROLE_UNKNOWN');
        $client->postJson(self::RULES, ['event_type' => 'NOPE'] + $this->expenseRuleBody('Y'))->assertStatus(422)->assertJsonPath('code', 'EVENT_TYPE_UNKNOWN');
        $client->postJson(self::RULES, ['lines' => [['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'net']]] + $this->expenseRuleBody('Z'))->assertStatus(422); // two lines minimum

        $rule = $client->postJson(self::RULES, $this->expenseRuleBody('EXP'))->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('version', 1)->json();
        $this->assertCount(3, $rule['lines']);
        $client->postJson(self::RULES, $this->expenseRuleBody('EXP'))->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_CODE_TAKEN');

        $client->patchJson(self::RULES."/{$rule['id']}", ['name' => 'Beban diakui (revisi)'])->assertOk()->assertJsonPath('name', 'Beban diakui (revisi)');
        $published = $client->postJson(self::RULES."/{$rule['id']}/publish", ['effective_from' => '2026-01-01'])->assertOk()->assertJsonPath('status', 'PUBLISHED')->json();
        $this->assertSame('2026-01-01', $published['effective_from']);
        $this->assertNull($published['effective_to']);
        $this->assertNotNull($published['published_at']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.posting_rule.published')->where('resource_id', $rule['id'])->count());
    }

    public function test_a_published_rule_is_immutable_in_the_service_and_in_the_database(): void
    {
        $rule = $this->publishedRule();
        $client = $this->signedIn($this->tenant);

        $client->patchJson(self::RULES."/{$rule['id']}", ['name' => 'x'])->assertStatus(409)->assertJsonPath('code', 'POSTING_RULE_IMMUTABLE');
        $client->deleteJson(self::RULES."/{$rule['id']}")->assertStatus(409)->assertJsonPath('code', 'POSTING_RULE_IMMUTABLE');
        $client->postJson(self::RULES."/{$rule['id']}/publish", ['effective_from' => '2026-02-01'])->assertStatus(409)->assertJsonPath('code', 'POSTING_RULE_IMMUTABLE');

        $line = DB::table('posting_rule_lines')->where('posting_rule_id', $rule['id'])->first();
        foreach ([
            fn () => DB::table('posting_rule_lines')->where('id', $line->id)->update(['account_role' => 'CASH']),
            fn () => DB::table('posting_rule_lines')->where('id', $line->id)->delete(),
            fn () => DB::table('posting_rule_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'posting_rule_id' => $rule['id'], 'line_number' => 9,
                'side' => 'DEBIT', 'account_role' => 'CASH', 'amount_key' => 'net', 'created_at' => now(), 'updated_at' => now()]),
        ] as $attempt) {
            $this->assertDbRefuses($attempt);
        }
    }

    public function test_a_rule_needs_both_sides_and_mapped_roles_before_it_can_be_published(): void
    {
        $client = $this->signedIn($this->tenant);
        $oneSided = $client->postJson(self::RULES, ['lines' => [
            ['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'net'], ['side' => 'DEBIT', 'account_role' => 'TAX_RECEIVABLE', 'amount_key' => 'tax'],
        ]] + $this->expenseRuleBody('ONE'))->assertCreated()->json();
        $client->postJson(self::RULES."/{$oneSided['id']}/publish", ['effective_from' => '2026-01-01'])->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_INCOMPLETE');

        DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'TAX_RECEIVABLE')->delete();
        $rule = $client->postJson(self::RULES, $this->expenseRuleBody('MAP'))->assertCreated()->json();
        $client->postJson(self::RULES."/{$rule['id']}/publish", ['effective_from' => '2026-01-01'])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING')
            ->assertJsonPath('details.account_roles.0', 'TAX_RECEIVABLE');
        $this->assertSame('DRAFT', DB::table('posting_rules')->where('id', $rule['id'])->value('status'));
    }

    public function test_versions_never_overlap_and_the_previous_one_ends_the_day_before(): void
    {
        $v1 = $this->publishedRule('2026-01-01');
        $client = $this->signedIn($this->tenant);

        $client->postJson(self::RULES."/{$v1['id']}/new-version")->assertCreated()->assertJsonPath('version', 2)->assertJsonPath('status', 'DRAFT');
        $v2 = DB::table('posting_rules')->where('code', 'EXP')->where('version', 2)->first();
        $client->postJson(self::RULES."/{$v1['id']}/new-version")->assertStatus(409)->assertJsonPath('code', 'POSTING_RULE_DRAFT_EXISTS');

        $client->postJson(self::RULES."/{$v2->id}/publish", ['effective_from' => '2026-01-01'])->assertStatus(409)->assertJsonPath('code', 'POSTING_RULE_WINDOW_OVERLAP');
        $client->postJson(self::RULES."/{$v2->id}/publish", ['effective_from' => '2026-07-01'])->assertOk()->assertJsonPath('effective_to', null);

        $this->assertSame('2026-06-30', DB::table('posting_rules')->where('id', $v1['id'])->value('effective_to'));
        $rules = $this->inTenant($this->tenant, fn () => app(PostingRuleService::class));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => $rules->resolve('EXPENSE_RECOGNIZED', '2026-06-30'))->version);
        $this->assertSame(2, $this->inTenant($this->tenant, fn () => $rules->resolve('EXPENSE_RECOGNIZED', '2026-07-01'))->version);

        // A version published in the past slots in between and is cut off by the next one.
        $client->postJson(self::RULES."/{$v1['id']}/new-version")->assertCreated()->assertJsonPath('version', 3);
        $v3 = DB::table('posting_rules')->where('code', 'EXP')->where('version', 3)->first();
        $client->postJson(self::RULES."/{$v3->id}/publish", ['effective_from' => '2026-03-01'])->assertStatus(409)->assertJsonPath('code', 'POSTING_RULE_WINDOW_OVERLAP'); // inside v1's closed window
    }

    public function test_archiving_stops_a_rule_from_serving_later_dates(): void
    {
        $rule = $this->publishedRule('2026-01-01');
        $client = $this->signedIn($this->tenant);

        $client->postJson(self::RULES."/{$rule['id']}/archive", ['effective_to' => '2025-12-31'])->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_WINDOW_INVALID');
        $client->postJson(self::RULES."/{$rule['id']}/archive", ['effective_to' => '2026-03-31'])->assertOk()->assertJsonPath('status', 'ARCHIVED')->assertJsonPath('effective_to', '2026-03-31');
        $client->postJson(self::RULES."/{$rule['id']}/archive")->assertStatus(409)->assertJsonPath('code', 'POSTING_RULE_NOT_PUBLISHED');

        $this->postExpense('IN-WINDOW', date: '2026-03-31');
        $this->assertDomainError('POSTING_RULE_NOT_FOUND', fn () => $this->postExpense('AFTER', date: '2026-04-01'));
        $this->assertSame('FAILED', DB::table('accounting_events')->where('source_id', 'AFTER')->value('status'));
    }

    public function test_only_a_draft_can_be_deleted(): void
    {
        $client = $this->signedIn($this->tenant);
        $draft = $client->postJson(self::RULES, $this->expenseRuleBody('GONE'))->assertCreated()->json();
        $client->deleteJson(self::RULES."/{$draft['id']}")->assertNoContent();
        $this->assertSame(0, DB::table('posting_rules')->where('id', $draft['id'])->count());
        $this->assertSame(0, DB::table('posting_rule_lines')->where('posting_rule_id', $draft['id'])->count());
    }

    // ------------------------------------------------------------------------------------------ mappings

    public function test_mappings_validate_the_account_and_the_most_specific_one_wins(): void
    {
        $client = $this->signedIn($this->tenant);
        $org = app(OrganizationService::class);
        [$branch, $unit] = $this->inTenant($this->tenant, function () use ($org) {
            $branch = $org->createBranch($this->tenant->id, ['code' => 'B1', 'name' => 'Cabang 1']);

            return [$branch, $org->createBusinessUnit($this->tenant->id, ['code' => 'U1', 'name' => 'Unit 1'], $branch->id)];
        });

        $overview = $client->getJson(self::MAPPINGS)->assertOk()->json();
        $this->assertSame(10, collect($overview['roles'])->where('mapped', true)->count());

        $header = $this->account($this->tenant, '6000');
        $client->putJson(self::MAPPINGS, ['account_role' => 'EXPENSE', 'account_id' => $header->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_MAPPABLE');
        $client->putJson(self::MAPPINGS, ['account_role' => 'NOPE', 'account_id' => $this->account($this->tenant, '6100')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_ROLE_UNKNOWN');

        $salary = $this->account($this->tenant, '6100');
        $rent = $this->account($this->tenant, '6200');
        $power = $this->account($this->tenant, '6300');
        $client->putJson(self::MAPPINGS, ['account_role' => 'EXPENSE', 'account_id' => $rent->id, 'branch_id' => $branch->id])->assertOk();
        $client->putJson(self::MAPPINGS, ['account_role' => 'EXPENSE', 'account_id' => $power->id, 'business_unit_id' => $unit->id])->assertOk();
        $client->putJson(self::MAPPINGS, ['account_role' => 'EXPENSE', 'account_id' => $salary->id, 'branch_id' => $branch->id])->assertOk(); // same scope: re-pointed, not duplicated
        $this->assertSame(1, DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'EXPENSE')->where('branch_id', $branch->id)->whereNull('business_unit_id')->count());

        $mappings = $this->inTenant($this->tenant, fn () => app(AccountMappingService::class));
        $resolve = fn (?string $b, ?string $u) => $this->inTenant($this->tenant, fn () => $mappings->resolve('EXPENSE', $b, $u));
        $this->assertSame('6900', $resolve(null, null)['account']->code);
        $this->assertSame('6100', $resolve($branch->id, null)['account']->code);
        $this->assertSame('6300', $resolve($branch->id, $unit->id)['account']->code);
        $this->assertSame('BUSINESS_UNIT', $resolve($branch->id, $unit->id)['specificity']);
        $this->assertSame('6900', $resolve((string) Str::uuid(), null)['account']->code); // unknown branch falls back to the default

        $this->assertSame(3, DB::table('audit_logs')->whereIn('action', ['accounting.account_mapping.created', 'accounting.account_mapping.updated'])->count());
    }

    public function test_the_default_mapping_of_a_role_used_by_a_published_rule_cannot_be_removed_and_a_mapped_account_cannot_be_deactivated(): void
    {
        $this->publishedRule();
        $client = $this->signedIn($this->tenant);
        $mapping = DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'EXPENSE')->first();

        $client->postJson(self::MAPPINGS."/{$mapping->id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_MAPPING_IN_USE');
        $client->postJson('/api/v1/app/accounting/accounts/'.$mapping->account_id.'/status', ['status' => 'INACTIVE'])->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_MAPPED');

        $unused = DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'INVENTORY_ASSET')->first();
        $client->postJson(self::MAPPINGS."/{$unused->id}/deactivate")->assertOk()->assertJsonPath('status', 'INACTIVE');
    }

    // ------------------------------------------------------------------------------------------ event posting

    public function test_an_event_is_posted_through_the_engine_with_a_complete_snapshot(): void
    {
        $this->publishedRule();
        $event = $this->postExpense();

        $this->assertSame('POSTED', $event->status);
        $journal = DB::table('journal_entries')->where('id', $event->journal_entry_id)->first();
        $this->assertSame('SYSTEM', $journal->journal_type);
        $this->assertSame('POSTED', $journal->status);
        $this->assertSame('SJ-FY2026-000001', $journal->journal_number);
        $this->assertSame('TEST_DOC', $journal->source_type);
        $this->assertSame('EXP-1', $journal->source_id);
        $this->assertNull($journal->created_by);
        $this->assertSame('1110.0000', $journal->total_debit);
        $this->assertSame('1110.0000', $journal->total_credit);

        $codes = fn (string $side) => DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journal->id)->where("l.$side", '>', 0)->orderBy('l.line_number')->pluck('a.code')->all();
        $this->assertSame(['6900', '1150'], $codes('debit'));
        $this->assertSame(['2110'], $codes('credit'));

        $snapshot = json_decode($journal->posting_snapshot, true);
        $this->assertSame('EXP', $snapshot['rule']['code']);
        $this->assertSame(1, $snapshot['rule']['version']);
        $this->assertCount(3, $snapshot['lines']);
        $this->assertSame('DEFAULT', $snapshot['lines'][0]['mapping_scope']);
        $this->assertSame('EXPENSE', $snapshot['lines'][0]['account_role']);
        $this->assertSame(['DRAFT', 'POSTED'], DB::table('journal_transitions')->where('journal_entry_id', $journal->id)->orderBy('occurred_at')->orderBy('id')->pluck('to_status')->all());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.journal.posted')->where('resource_id', $journal->id)->count());
        $this->assertSame('LOCKED', DB::table('accounting_profiles')->where('tenant_id', $this->tenant->id)->value('status'));
    }

    public function test_the_same_business_fact_posts_once_and_a_changed_fact_is_a_conflict(): void
    {
        $this->publishedRule();
        $first = $this->postExpense();
        $again = $this->postExpense();

        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->journal_entry_id, $again->journal_entry_id);
        $this->assertSame(1, DB::table('journal_entries')->where('source_id', 'EXP-1')->count());
        $this->assertSame(1, DB::table('accounting_events')->count());
        $this->assertSame(1, DB::table('document_sequences')->count());

        // The same key with the same content in another key order / int form is still the same fact.
        $this->assertSame($first->id, $this->postExpense(payload: ['total' => 1110, 'tax' => '110', 'net' => '1000'])->id);

        $this->assertDomainError('ACCOUNTING_EVENT_CONFLICT', fn () => $this->postExpense(payload: ['net' => '2000', 'tax' => '220', 'total' => '2220']));
        $this->assertSame('POSTED', DB::table('accounting_events')->where('source_id', 'EXP-1')->value('status'));
        $this->assertSame(1, DB::table('journal_entries')->where('source_id', 'EXP-1')->count());

        // A different purpose is a different fact of the same source.
        $this->inTenant($this->tenant, fn () => app(PostingEngine::class)->postEvent('EXPENSE_RECOGNIZED', 'TEST_DOC', 'EXP-1', '2026-03-10', ['net' => '1000', 'tax' => '110', 'total' => '1110'], [], 'ADJUST'));
        $this->assertSame(2, DB::table('journal_entries')->where('source_id', 'EXP-1')->count());
    }

    public function test_a_failed_event_leaves_a_record_and_no_journal_and_can_be_retried_with_a_corrected_fact(): void
    {
        $this->publishedRule();

        $this->assertDomainError('JOURNAL_UNBALANCED', fn () => $this->postExpense('BAD', ['net' => '1000', 'tax' => '110', 'total' => '1000']));
        $event = DB::table('accounting_events')->where('source_id', 'BAD')->first();
        $this->assertSame('FAILED', $event->status);
        $this->assertSame('JOURNAL_UNBALANCED', $event->failure_code);
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->journal_entry_id);
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('document_sequences')->count(), 'no number is consumed by a failed posting');

        $this->assertDomainError('EVENT_COMPONENT_MISSING', fn () => $this->postExpense('BAD', ['net' => '1000', 'total' => '1000']));
        $this->assertSame(2, DB::table('accounting_events')->where('source_id', 'BAD')->value('attempts'));
        $this->assertSame(1, DB::table('accounting_events')->where('source_id', 'BAD')->count());

        $event = $this->postExpense('BAD', ['net' => '1000', 'tax' => '110', 'total' => '1110']);
        $this->assertSame('POSTED', $event->status);
        $this->assertSame(3, $event->attempts);
        $this->assertNull($event->failure_code);
        $this->assertSame(1, DB::table('journal_entries')->count());
    }

    public function test_an_event_cannot_post_into_a_closed_period_or_with_bad_amounts(): void
    {
        $this->publishedRule();
        DB::table('accounting_periods')->where('tenant_id', $this->tenant->id)->where('code', '2026-02')->update(['status' => 'CLOSED', 'closed_at' => now()]);

        $this->assertDomainError('PERIOD_CLOSED', fn () => $this->postExpense('CLOSED', date: '2026-02-10'));
        $this->assertSame('PERIOD_CLOSED', DB::table('accounting_events')->where('source_id', 'CLOSED')->value('failure_code'));
        $this->assertDomainError('AMOUNT_INVALID', fn () => $this->postExpense('FLOAT', ['net' => 1000.5, 'tax' => '110', 'total' => '1110.5']));
        $this->assertDomainError('AMOUNT_INVALID', fn () => $this->postExpense('NEG', ['net' => '-1', 'tax' => '0', 'total' => '-1']));
        $this->assertDomainError('AMOUNT_INVALID', fn () => $this->postExpense('SCALE', ['net' => '1000.001', 'tax' => '0', 'total' => '1000.001']));
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_zero_components_are_skipped_only_when_the_rule_says_so(): void
    {
        $this->publishedRule();
        $event = $this->postExpense('NOTAX', ['net' => '500', 'tax' => '0', 'total' => '500']);
        $this->assertSame(2, DB::table('journal_lines')->where('journal_entry_id', $event->journal_entry_id)->count());

        $client = $this->signedIn($this->tenant);
        $strict = $this->expenseRuleBody('STRICT');
        $strict['event_type'] = 'AP_INVOICE_RECOGNIZED';
        $strict['lines'][1]['skip_if_zero'] = false;
        $rule = $client->postJson(self::RULES, $strict)->assertCreated()->json();
        $client->postJson(self::RULES."/{$rule['id']}/publish", ['effective_from' => '2026-01-01'])->assertOk();
        $this->assertDomainError('LINE_AMOUNT_INVALID', fn () => $this->inTenant($this->tenant, fn () => app(PostingEngine::class)
            ->postEvent('AP_INVOICE_RECOGNIZED', 'TEST_DOC', 'AP-1', '2026-03-10', ['net' => '500', 'tax' => '0', 'total' => '500'])));
    }

    public function test_later_rule_and_mapping_changes_never_rewrite_a_posted_journal(): void
    {
        $v1 = $this->publishedRule('2026-01-01');
        $event = $this->postExpense('HIST');
        $before = DB::table('journal_lines')->where('journal_entry_id', $event->journal_entry_id)->orderBy('line_number')->get(['account_id', 'debit', 'credit'])->toJson();
        $snapshot = DB::table('journal_entries')->where('id', $event->journal_entry_id)->value('posting_snapshot');

        $client = $this->signedIn($this->tenant);
        $client->putJson(self::MAPPINGS, ['account_role' => 'EXPENSE', 'account_id' => $this->account($this->tenant, '6200')->id])->assertOk();
        $client->postJson(self::RULES."/{$v1['id']}/new-version")->assertCreated();
        $v2 = DB::table('posting_rules')->where('code', 'EXP')->where('version', 2)->first();
        $client->patchJson(self::RULES."/{$v2->id}", ['lines' => [
            ['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'total'], ['side' => 'CREDIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'total'],
        ]])->assertOk();
        $client->postJson(self::RULES."/{$v2->id}/publish", ['effective_from' => '2026-04-01'])->assertOk();

        $this->assertSame($before, DB::table('journal_lines')->where('journal_entry_id', $event->journal_entry_id)->orderBy('line_number')->get(['account_id', 'debit', 'credit'])->toJson());
        $this->assertSame($snapshot, DB::table('journal_entries')->where('id', $event->journal_entry_id)->value('posting_snapshot'));

        // New events use the new rule and mapping; the old date still resolves the old rule.
        $later = $this->postExpense('NEW', ['net' => '1000', 'tax' => '110', 'total' => '1110'], '2026-04-15');
        $this->assertSame(2, json_decode(DB::table('journal_entries')->where('id', $later->journal_entry_id)->value('posting_snapshot'), true)['rule']['version']);
        $this->assertSame(2, DB::table('journal_lines')->where('journal_entry_id', $later->journal_entry_id)->count());
        $this->assertSame('6200', DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $later->journal_entry_id)->where('l.debit', '>', 0)->value('a.code'));
    }

    public function test_event_dimensions_select_the_branch_mapping_and_land_on_every_line(): void
    {
        $this->publishedRule();
        $branch = $this->inTenant($this->tenant, fn () => app(OrganizationService::class)->createBranch($this->tenant->id, ['code' => 'B1', 'name' => 'Cabang 1']));
        $this->signedIn($this->tenant)->putJson(self::MAPPINGS, ['account_role' => 'EXPENSE', 'account_id' => $this->account($this->tenant, '6500')->id, 'branch_id' => $branch->id])->assertOk();

        $event = $this->postExpense('BR-1', dimensions: ['branch_id' => $branch->id]);
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $event->journal_entry_id)->orderBy('l.line_number')->get(['a.code', 'l.branch_id']);
        $this->assertSame('6500', $lines[0]->code);
        $this->assertSame([$branch->id], $lines->pluck('branch_id')->unique()->all());
        $this->assertSame('BRANCH', json_decode(DB::table('journal_entries')->where('id', $event->journal_entry_id)->value('posting_snapshot'), true)['lines'][0]['mapping_scope']);
    }

    // ------------------------------------------------------------------------------------------ simulation, log, access

    public function test_simulation_shows_the_journal_a_rule_would_build_and_stores_nothing(): void
    {
        $client = $this->signedIn($this->tenant);
        $rule = $client->postJson(self::RULES, $this->expenseRuleBody())->assertCreated()->json();

        $ok = $client->postJson(self::RULES."/{$rule['id']}/simulate", ['payload' => ['net' => '1000', 'tax' => '110', 'total' => '1110']])->assertOk()->json();
        $this->assertTrue($ok['balanced']);
        $this->assertSame('1110.0000', $ok['total_debit']);
        $this->assertSame(['6900', '1150', '2110'], array_column($ok['lines'], 'account_code'));

        $off = $client->postJson(self::RULES."/{$rule['id']}/simulate", ['payload' => ['net' => '1000', 'tax' => '110', 'total' => '1000']])->assertOk()->json();
        $this->assertFalse($off['balanced']);
        $client->postJson(self::RULES."/{$rule['id']}/simulate", ['payload' => ['net' => '1000']])->assertStatus(422)->assertJsonPath('code', 'EVENT_COMPONENT_MISSING');

        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('accounting_events')->count());
        $this->assertSame(0, DB::table('document_sequences')->count());
    }

    public function test_the_event_log_lists_posted_and_failed_facts_of_this_tenant_only(): void
    {
        $this->publishedRule();
        $this->postExpense('OK-1');
        try {
            $this->postExpense('KO-1', ['net' => '1', 'tax' => '0', 'total' => '2']);
        } catch (DomainException) {
        }

        $other = $this->accountingTenant('beta');
        $this->inTenant($other, fn () => DB::table('accounting_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $other->id, 'event_type' => 'EXPENSE_RECOGNIZED',
            'source_type' => 'X', 'source_id' => 'FOREIGN', 'posting_purpose' => 'POST', 'status' => 'FAILED', 'posting_date' => '2026-03-01', 'payload' => '{}', 'payload_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]));

        $client = $this->signedIn($this->tenant);
        $this->assertEqualsCanonicalizing(['OK-1', 'KO-1'], array_column($client->getJson('/api/v1/app/accounting/accounting-events')->assertOk()->json('data'), 'source_id'));
        $this->assertSame(['KO-1'], array_column($client->getJson('/api/v1/app/accounting/accounting-events?status=FAILED')->json('data'), 'source_id'));
        $client->getJson('/api/v1/app/accounting/accounting-events?status=BOGUS')->assertStatus(422);
    }

    public function test_configuration_is_tenant_private_and_permission_gated(): void
    {
        $rule = $this->publishedRule();
        $beta = $this->accountingTenant('beta');
        $foreign = $this->signedIn($beta);
        $foreign->getJson(self::RULES."/{$rule['id']}")->assertNotFound();
        $foreign->patchJson(self::RULES."/{$rule['id']}", ['name' => 'x'])->assertNotFound();
        $foreign->postJson(self::RULES."/{$rule['id']}/simulate", ['payload' => ['net' => '1']])->assertNotFound();
        $foreign->postJson(self::RULES."/{$rule['id']}/archive")->assertNotFound();
        $this->assertSame([], $foreign->getJson(self::RULES)->json('data'));
        $mappingId = DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->value('id');
        $foreign->postJson(self::MAPPINGS."/{$mappingId}/deactivate")->assertNotFound();
        $foreign->putJson(self::MAPPINGS, ['account_role' => 'CASH', 'account_id' => $this->account($this->tenant, '1110')->id])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');

        $viewer = $this->signedIn($this->tenant, ['accounting.posting_rule.view', 'accounting.account_mapping.view']);
        $viewer->getJson(self::RULES)->assertOk();
        $viewer->getJson(self::MAPPINGS)->assertOk();
        $viewer->postJson(self::RULES, $this->expenseRuleBody('NOPE'))->assertStatus(403);
        $viewer->postJson(self::RULES."/{$rule['id']}/archive")->assertStatus(403);
        $viewer->putJson(self::MAPPINGS, ['account_role' => 'CASH', 'account_id' => $this->account($this->tenant, '1110')->id])->assertStatus(403);
        $this->signedIn($this->tenant, ['accounting.journal.view'])->getJson(self::RULES)->assertStatus(403);
    }

    // ------------------------------------------------------------------------------------------ helpers

    private function assertDomainError(string $code, callable $work): void
    {
        try {
            $work();
            $this->fail("Expected {$code}");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function assertDbRefuses(callable $work): void
    {
        try {
            DB::transaction(fn () => $work());
            $this->fail('The database accepted a change it must refuse.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? null);
        }
    }
}
