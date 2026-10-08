<?php

namespace Tests\Feature\Accounting;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Services\OrganizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA1 batch M: data scope on the workflow and the dashboard, audit completeness of every accounting write, and payload hardening. */
class ScopeAuditAndPayloadTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const A = '/api/v1/app/accounting';

    private const JOURNAL_PERMISSIONS = [
        'accounting.journal.view', 'accounting.journal.create', 'accounting.journal.update', 'accounting.journal.submit',
        'accounting.journal.approve', 'accounting.journal.post', 'accounting.journal.reverse',
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->accountingTenant('alpha', profile: ['approval_required' => false]);
    }

    /** A member restricted to one branch (BRANCH) or to the records they created (OWN). */
    private function scoped(array $permissions, string $type, ?string $branchId = null): string
    {
        [$user, $membership] = $this->member($this->tenant, $permissions);
        DB::table('data_scopes')->where('tenant_user_id', $membership->id)->delete();
        DB::table('data_scopes')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'tenant_user_id' => $membership->id, 'scope_type' => $type,
            'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->tenantToken($user, $this->tenant);
    }

    /** @return array{0:string,1:string} the ids of two branches */
    private function branches(): array
    {
        $org = app(OrganizationService::class);

        return $this->inTenant($this->tenant, fn () => [
            $org->createBranch($this->tenant->id, ['code' => 'N', 'name' => 'North'])->id, $org->createBranch($this->tenant->id, ['code' => 'S', 'name' => 'South'])->id,
        ]);
    }

    private function branchBody(string $branchId, string $amount = '10'): array
    {
        return $this->journalBody($this->tenant, ['lines' => $this->lines($this->tenant, $amount, dimensions: ['branch_id' => $branchId])]);
    }

    // ------------------------------------------------------------------------------------------ data scope

    public function test_the_dashboard_counts_and_lists_only_what_the_users_scope_reaches(): void
    {
        [$north, $south] = $this->branches();
        $admin = $this->signedIn($this->tenant);
        $admin->postJson(self::A.'/journals', $this->branchBody($north))->assertCreated();
        $admin->postJson(self::A.'/journals', $this->branchBody($south))->assertCreated();
        $admin->postJson(self::A.'/journals', $this->journalBody($this->tenant))->assertCreated(); // no branch at all
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '300', dimensions: ['branch_id' => $north])]);
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '700', dimensions: ['branch_id' => $south])]);

        $all = $this->as($this->tenantToken($this->member($this->tenant)[0], $this->tenant))->getJson(self::A.'/dashboard')->assertOk()->json();
        $this->assertSame(3, $all['journals']['draft']);
        $this->assertCount(2, $all['recent_posted']);

        $narrow = $this->as($this->scoped(['accounting.journal.view'], 'BRANCH', $north))->getJson(self::A.'/dashboard')->assertOk()->json();
        $this->assertSame(1, $narrow['journals']['draft']);
        $this->assertSame(['300.0000'], array_column($narrow['recent_posted'], 'total_debit'));
    }

    public function test_a_journal_outside_the_scope_cannot_be_driven_through_any_workflow_step(): void
    {
        [$north, $south] = $this->branches();
        $admin = $this->signedIn($this->tenant);
        $draft = $admin->postJson(self::A.'/journals', $this->branchBody($south))->assertCreated()->json('id');
        $submitted = $admin->postJson(self::A.'/journals', $this->branchBody($south, '11'))->assertCreated()->json('id');
        $posted = $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '12', dimensions: ['branch_id' => $south])])->id;
        DB::table('journal_entries')->where('id', $submitted)->update(['status' => 'SUBMITTED']); // as if a colleague had submitted it

        $client = $this->as($this->scoped(self::JOURNAL_PERMISSIONS, 'BRANCH', $north));
        $before = [DB::table('journal_entries')->count(), DB::table('journal_transitions')->count(), DB::table('audit_logs')->count()];

        foreach ([[$draft, ['submit', 'post', 'reopen']], [$submitted, ['approve', 'reject', 'cancel', 'post']], [$posted, ['reverse']]] as [$id, $steps]) {
            foreach ($steps as $step) {
                $client->postJson(self::A."/journals/{$id}/{$step}", ['reason' => 'x'])->assertNotFound();
            }
            $client->patchJson(self::A."/journals/{$id}", ['description' => 'x'])->assertNotFound();
            $client->getJson(self::A."/journals/{$id}")->assertNotFound();
        }

        $this->assertSame($before, [DB::table('journal_entries')->count(), DB::table('journal_transitions')->count(), DB::table('audit_logs')->count()], 'a refused step changed or recorded nothing');
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $draft)->value('status'));
        $this->assertSame('SUBMITTED', DB::table('journal_entries')->where('id', $submitted)->value('status'));
        $this->assertNull(DB::table('journal_entries')->where('id', $posted)->value('reversed_by_journal_id'));
    }

    public function test_an_own_scope_reaches_only_the_journals_the_user_created(): void
    {
        $theirs = $this->as($this->tenantToken($this->member($this->tenant)[0], $this->tenant))->postJson(self::A.'/journals', $this->journalBody($this->tenant))->assertCreated()->json('id');
        $client = $this->as($this->scoped(self::JOURNAL_PERMISSIONS, 'OWN'));

        $mine = $client->postJson(self::A.'/journals', $this->journalBody($this->tenant, ['description' => 'Milik saya']))->assertCreated()->json('id');

        $this->assertSame([$mine], array_column($client->getJson(self::A.'/journals')->json('data'), 'id'));
        $client->getJson(self::A."/journals/{$mine}")->assertOk();
        $client->getJson(self::A."/journals/{$theirs}")->assertNotFound();
        $client->postJson(self::A."/journals/{$theirs}/submit")->assertNotFound();
        $client->postJson(self::A."/journals/{$theirs}/post")->assertNotFound();
        $this->assertSame('DRAFT', DB::table('journal_entries')->where('id', $theirs)->value('status'));
    }

    // ------------------------------------------------------------------------------------------ audit

    /** @param array<string,mixed> $where */
    private function audited(string $action, ?string $actorId, ?string $resourceId = null, ?Tenant $tenant = null): object
    {
        $query = DB::table('audit_logs')->where('tenant_id', ($tenant ?? $this->tenant)->id)->where('action', $action);
        if ($resourceId !== null) {
            $query->where('resource_id', $resourceId);
        }
        $row = $query->orderByDesc('occurred_at')->first();
        $this->assertNotNull($row, "no audit record for {$action}");
        $this->assertSame($actorId, $row->actor_user_id, "{$action} must name the acting user");
        $this->assertNotNull($row->resource_type, $action);

        return $row;
    }

    public function test_every_write_of_the_accounting_core_leaves_an_audit_record_naming_the_actor(): void
    {
        $this->tenant = $this->accountingTenant('gamma', profile: ['approval_required' => true]);
        [$maker] = $this->member($this->tenant);
        [$checker] = $this->member($this->tenant);
        $make = fn () => $this->as($this->tenantToken($maker, $this->tenant));
        $check = fn () => $this->as($this->tenantToken($checker, $this->tenant));

        // profile and calendar
        $make()->putJson(self::A.'/profile', ['approval_required' => true])->assertOk();
        $year = $make()->postJson(self::A.'/fiscal-years', ['code' => 'FY2027', 'name' => 'Tahun 2027', 'start_date' => '2027-01-01'])->assertCreated()->json('id');
        $make()->postJson(self::A."/fiscal-years/{$year}/open", ['open_periods' => true])->assertOk();
        $draftYear = $make()->postJson(self::A.'/fiscal-years', ['code' => 'FY2028', 'name' => 'Tahun 2028', 'start_date' => '2028-01-01'])->assertCreated()->json('id');
        $make()->deleteJson(self::A."/fiscal-years/{$draftYear}")->assertSuccessful();
        $january = $this->period($this->tenant, '2026-01')->id;
        $make()->postJson(self::A."/periods/{$january}/soft-close")->assertOk();
        $make()->postJson(self::A."/periods/{$january}/close")->assertOk();

        // chart of accounts and dimensions
        $account = $make()->postJson(self::A.'/accounts', ['code' => '9100', 'name' => 'Akun uji', 'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT', 'is_postable' => true])->assertCreated()->json('id');
        $make()->patchJson(self::A."/accounts/{$account}", ['name' => 'Akun uji 2'])->assertOk();
        $make()->postJson(self::A."/accounts/{$account}/status", ['status' => 'INACTIVE'])->assertOk();
        $make()->deleteJson(self::A."/accounts/{$account}")->assertSuccessful();
        $center = $make()->postJson(self::A.'/cost-centers', ['code' => 'OPS', 'name' => 'Operasional'])->assertCreated()->json('id');
        $make()->patchJson(self::A."/cost-centers/{$center}", ['name' => 'Operasional 2'])->assertOk();
        $make()->postJson(self::A."/cost-centers/{$center}/status", ['status' => 'INACTIVE'])->assertOk();

        // journals: draft, edit, submit, reject, reopen, submit, approve, post, reverse, and a cancelled draft
        $journal = $make()->postJson(self::A.'/journals', $this->journalBody($this->tenant))->assertCreated()->json('id');
        $make()->patchJson(self::A."/journals/{$journal}", ['description' => 'Diubah'])->assertOk();
        $make()->postJson(self::A."/journals/{$journal}/submit")->assertOk();
        $check()->postJson(self::A."/journals/{$journal}/reject", ['reason' => 'Lampiran kurang'])->assertOk();
        $make()->postJson(self::A."/journals/{$journal}/reopen")->assertOk();
        $make()->postJson(self::A."/journals/{$journal}/submit")->assertOk();
        $check()->postJson(self::A."/journals/{$journal}/approve")->assertOk();
        $check()->postJson(self::A."/journals/{$journal}/post")->assertOk();
        $check()->postJson(self::A."/journals/{$journal}/reverse", ['reason' => 'Salah akun'])->assertCreated();
        $dropped = $make()->postJson(self::A.'/journals', $this->journalBody($this->tenant))->assertCreated()->json('id');
        $make()->postJson(self::A."/journals/{$dropped}/cancel", ['reason' => 'Dobel'])->assertOk();

        // posting rules and mappings
        $rule = $make()->postJson(self::A.'/posting-rules', ['code' => 'EXP', 'event_type' => 'EXPENSE_RECOGNIZED', 'name' => 'Beban', 'lines' => [
            ['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'net'], ['side' => 'CREDIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'total'],
        ]])->assertCreated()->json('id');
        $make()->patchJson(self::A."/posting-rules/{$rule}", ['name' => 'Beban diakui'])->assertOk();
        $make()->postJson(self::A."/posting-rules/{$rule}/publish", ['effective_from' => '2026-01-01'])->assertOk();
        $v2 = $make()->postJson(self::A."/posting-rules/{$rule}/new-version")->assertCreated()->json('id');
        $make()->deleteJson(self::A."/posting-rules/{$v2}")->assertSuccessful();
        $make()->postJson(self::A."/posting-rules/{$rule}/archive", ['effective_to' => '2026-12-31'])->assertOk();
        $expense = $this->account($this->tenant, '6900')->id;
        $mapping = $make()->putJson(self::A.'/account-mappings', ['account_role' => 'EXPENSE', 'account_id' => $expense])->assertOk()->json('id');
        $make()->postJson(self::A."/account-mappings/{$mapping}/deactivate")->assertOk(); // the only rule using the role is archived by now
        $branch = $this->inTenant($this->tenant, fn () => app(OrganizationService::class)->createBranch($this->tenant->id, ['code' => 'JKT', 'name' => 'Jakarta'])->id);
        $special = $make()->putJson(self::A.'/account-mappings', ['account_role' => 'EXPENSE', 'account_id' => $expense, 'branch_id' => $branch])->assertOk()->json('id');
        $make()->putJson(self::A.'/account-mappings', ['account_role' => 'EXPENSE', 'account_id' => $this->account($this->tenant, '6200')->id, 'branch_id' => $branch])->assertOk();
        $make()->postJson(self::A."/account-mappings/{$special}/deactivate")->assertOk();

        // exports
        $make()->get(self::A.'/accounts-export')->assertOk();
        $make()->get(self::A.'/general-ledger/export?from=2026-03-01&to=2026-03-31')->assertOk();
        $make()->get(self::A.'/trial-balance/export?from=2026-03-01&to=2026-03-31')->assertOk();

        $expected = [
            ['accounting.profile.updated', $maker->id], ['accounting.fiscal_year.created', $maker->id, $year], ['accounting.fiscal_year.opened', $maker->id, $year],
            ['accounting.fiscal_year.deleted', $maker->id, $draftYear], ['accounting.period.status_changed', $maker->id, $january],
            ['accounting.account.created', $maker->id, $account], ['accounting.account.updated', $maker->id, $account], ['accounting.account.status_changed', $maker->id, $account], ['accounting.account.deleted', $maker->id, $account],
            ['accounting.cost_center.created', $maker->id, $center], ['accounting.cost_center.updated', $maker->id, $center], ['accounting.cost_center.status_changed', $maker->id, $center],
            ['accounting.journal.created', $maker->id, $journal], ['accounting.journal.updated', $maker->id, $journal], ['accounting.journal.submitted', $maker->id, $journal],
            ['accounting.journal.rejected', $checker->id, $journal], ['accounting.journal.approved', $checker->id, $journal], ['accounting.journal.posted', $checker->id, $journal],
            ['accounting.journal.reversed', $checker->id, $journal], ['accounting.journal.cancelled', $maker->id, $dropped],
            ['accounting.posting_rule.created', $maker->id, $rule], ['accounting.posting_rule.updated', $maker->id, $rule], ['accounting.posting_rule.published', $maker->id, $rule],
            ['accounting.posting_rule.version_created', $maker->id], ['accounting.posting_rule.deleted', $maker->id, $v2], ['accounting.posting_rule.archived', $maker->id, $rule],
            ['accounting.account_mapping.updated', $maker->id, $mapping], ['accounting.account_mapping.created', $maker->id, $special], ['accounting.account_mapping.deactivated', $maker->id, $special], ['accounting.account_mapping.deactivated', $maker->id, $mapping],
            ['accounting.report.exported', $maker->id],
        ];
        foreach ($expected as $args) {
            $row = $this->audited(...$args);
            $this->assertNotNull($row->occurred_at);
            $this->assertNotNull(json_decode($row->context, true)['request_id'] ?? null, "{$args[0]} must carry the request id");
        }
        $this->assertGreaterThanOrEqual(3, DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', 'accounting.report.exported')->count());
        $this->audited('accounting.coa.template_applied', null);
    }

    public function test_the_opening_balance_audit_trail_covers_save_cancel_post_and_reverse(): void
    {
        $tenant = $this->accountingTenant('delta');
        [$user] = $this->member($tenant);
        $client = fn () => $this->as($this->tenantToken($user, $tenant));
        $body = ['cutover_date' => '2026-03-01', 'reference' => 'SALDO-AWAL', 'lines' => [
            ['account_id' => $this->account($tenant, '1110')->id, 'debit' => '500000'], ['account_id' => $this->account($tenant, '3100')->id, 'credit' => '500000'],
        ]];

        $client()->putJson(self::A.'/opening-balance', $body)->assertOk();
        $client()->postJson(self::A.'/opening-balance/cancel', ['reason' => 'Salah tanggal'])->assertOk();
        $client()->putJson(self::A.'/opening-balance', $body)->assertOk();
        $posted = $client()->postJson(self::A.'/opening-balance/post')->assertOk()->json('data');
        $journalId = $posted['journal_entry_id'] ?? $posted['journal']['id'] ?? DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('journal_type', 'OPENING')->value('id');
        $client()->postJson(self::A."/journals/{$journalId}/reverse", ['reason' => 'Saldo awal keliru'])->assertCreated();

        foreach (['saved', 'cancelled', 'posted', 'reversed'] as $what) {
            $this->audited("accounting.opening_balance.{$what}", $user->id, tenant: $tenant);
        }
    }

    public function test_audit_records_belong_to_their_tenant_and_never_hold_secrets(): void
    {
        $other = $this->accountingTenant('beta');
        $this->signedIn($this->tenant)->postJson(self::A.'/cost-centers', ['code' => 'ONE', 'name' => 'Satu', 'password' => 'x', 'api_token' => 'y'])->assertCreated();

        $this->assertSame(0, DB::table('audit_logs')->where('tenant_id', $other->id)->where('action', 'accounting.cost_center.created')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('changes', 'like', '%api_token%')->count());

        $viewer = $this->signedIn($other, ['audit.view']);
        $actions = array_column($viewer->getJson('/api/v1/app/audit-logs?per_page=100')->assertOk()->json('data'), 'action');
        $this->assertNotContains('accounting.cost_center.created', $actions, 'the audit view of another tenant never lists this tenant');
    }

    // ------------------------------------------------------------------------------------------ payload hardening

    public function test_privileged_fields_in_accounting_payloads_are_ignored(): void
    {
        $foreign = $this->accountingTenant('beta');
        $admin = $this->signedIn($this->tenant);
        $ghost = (string) Str::uuid();

        $account = $admin->postJson(self::A.'/accounts', [
            'code' => '9200', 'name' => 'Akun', 'account_type' => 'EXPENSE', 'normal_balance' => 'DEBIT', 'is_postable' => true,
            'tenant_id' => $foreign->id, 'is_system' => true, 'status' => 'INACTIVE', 'created_by' => $ghost, 'id' => $ghost,
        ])->assertCreated()->json();
        $this->assertSame($this->tenant->id, $account['tenant_id']);
        $this->assertNotSame($ghost, $account['id']);
        $this->assertSame('ACTIVE', $account['status']);
        $this->assertSame($this->tenant->id, DB::table('accounts')->where('id', $account['id'])->value('tenant_id'));

        $center = $admin->postJson(self::A.'/cost-centers', ['code' => 'X1', 'name' => 'X', 'tenant_id' => $foreign->id, 'status' => 'INACTIVE', 'id' => $ghost])->assertCreated()->json();
        $this->assertSame($this->tenant->id, $center['tenant_id']);
        $this->assertSame('ACTIVE', $center['status']);

        $journal = $admin->postJson(self::A.'/journals', $this->journalBody($this->tenant) + [
            'tenant_id' => $foreign->id, 'status' => 'POSTED', 'journal_number' => 'HACK-1', 'posted_at' => '2020-01-01', 'posted_by' => $ghost, 'created_by' => $ghost,
            'journal_type' => 'OPENING', 'reverses_journal_id' => $ghost, 'id' => $ghost,
        ])->assertCreated()->json();
        $row = DB::table('journal_entries')->where('id', $journal['id'])->first();
        $this->assertSame([$this->tenant->id, 'DRAFT', 'MANUAL', null, null, null], [$row->tenant_id, $row->status, $row->journal_type, $row->journal_number, $row->posted_at, $row->reverses_journal_id]);
        $this->assertNotSame($ghost, $row->created_by);

        $admin->patchJson(self::A."/journals/{$journal['id']}", ['status' => 'POSTED', 'tenant_id' => $foreign->id, 'journal_number' => 'HACK-2'])->assertOk();
        $row = DB::table('journal_entries')->where('id', $journal['id'])->first();
        $this->assertSame([$this->tenant->id, 'DRAFT', null], [$row->tenant_id, $row->status, $row->journal_number]);

        $rule = $admin->postJson(self::A.'/posting-rules', [
            'code' => 'R1', 'event_type' => 'EXPENSE_RECOGNIZED', 'name' => 'R', 'tenant_id' => $foreign->id, 'status' => 'PUBLISHED', 'version' => 9, 'is_system' => true,
            'lines' => [['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'net'], ['side' => 'CREDIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'total']],
        ])->assertCreated()->json();
        $this->assertSame([$this->tenant->id, 'DRAFT', 1], [$rule['tenant_id'], $rule['status'], $rule['version']]);

        $profile = $admin->putJson(self::A.'/profile', ['approval_required' => true, 'tenant_id' => $foreign->id, 'status' => 'DRAFT', 'activated_at' => null, 'id' => $ghost])->assertOk()->json();
        $this->assertSame($this->tenant->id, $profile['tenant_id']);
        $this->assertSame($this->tenant->id, DB::table('accounting_profiles')->where('id', $profile['id'])->value('tenant_id'));

        foreach (['accounts', 'cost_centers', 'journal_entries', 'posting_rules', 'accounting_profiles'] as $table) {
            $this->assertSame(0, DB::table($table)->where('id', $ghost)->count(), "{$table}: a client-chosen id must be ignored");
        }
    }

    public function test_search_input_is_matched_literally_and_cannot_break_out_of_the_query(): void
    {
        $admin = $this->signedIn($this->tenant);
        $admin->postJson(self::A.'/journals', $this->journalBody($this->tenant, ['description' => 'Diskon 100% khusus']))->assertCreated();
        $admin->postJson(self::A.'/journals', $this->journalBody($this->tenant, ['description' => 'Penjualan biasa']))->assertCreated();

        $this->assertCount(1, $admin->getJson(self::A.'/journals?q='.urlencode('100%'))->assertOk()->json('data'));
        $this->assertCount(0, $admin->getJson(self::A.'/journals?q='.urlencode('%_%x'))->assertOk()->json('data'));
        $admin->getJson(self::A.'/journals?q='.urlencode("' OR 1=1; DROP TABLE journal_entries;--"))->assertOk()->assertJsonCount(0, 'data');
        $admin->getJson(self::A.'/accounts?q='.urlencode("'; DELETE FROM accounts;--"))->assertOk();
        $this->assertSame(2, DB::table('journal_entries')->where('tenant_id', $this->tenant->id)->count());
        $this->assertGreaterThan(20, DB::table('accounts')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_malformed_input_is_a_client_error_never_a_server_error(): void
    {
        $admin = $this->signedIn($this->tenant);
        $journal = $admin->postJson(self::A.'/journals', $this->journalBody($this->tenant))->assertCreated()->json('id');
        $line = fn (array $over) => $this->journalBody($this->tenant, ['lines' => [['account_id' => $this->account($this->tenant, '1110')->id] + $over, ['account_id' => $this->account($this->tenant, '4100')->id, 'credit' => '1']]]);

        $cases = [
            'not a uuid' => [self::A.'/journals/not-a-uuid', 'getJson', []],
            'array as description' => [self::A.'/journals', 'postJson', $this->journalBody($this->tenant, ['description' => ['x']])],
            'huge amount' => [self::A.'/journals', 'postJson', $line(['debit' => str_repeat('9', 40)])],
            'scientific amount' => [self::A.'/journals', 'postJson', $line(['debit' => '1e3'])],
            'negative amount' => [self::A.'/journals', 'postJson', $line(['debit' => '-5'])],
            'text amount' => [self::A.'/journals', 'postJson', $line(['debit' => 'abc'])],
            'both sides' => [self::A.'/journals', 'postJson', $line(['debit' => '1', 'credit' => '1'])],
            'array amount' => [self::A.'/journals', 'postJson', $line(['debit' => ['1']])],
            'bad date' => [self::A.'/journals', 'postJson', $this->journalBody($this->tenant, ['posting_date' => '2026-02-30'])],
            'too many lines' => [self::A.'/journals', 'postJson', $this->journalBody($this->tenant, ['lines' => array_fill(0, 501, ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '1'])])],
            'unknown rule amount key' => [self::A.'/posting-rules', 'postJson', ['code' => 'Z', 'event_type' => 'EXPENSE_RECOGNIZED', 'name' => 'z', 'lines' => [['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'nope']]]],
            'report with garbage filters' => [self::A.'/general-ledger?from=yesterday&to=[]&account_id=zzz', 'getJson', []],
            'per_page abuse' => [self::A.'/journals?per_page=100000', 'getJson', []],
            'reverse without reason' => [self::A."/journals/{$journal}/reverse", 'postJson', []],
        ];

        $this->assertLessThan(500, $admin->postJson(self::A.'/journals', $this->journalBody($this->tenant, ['posting_date' => '9999-12-31', 'document_date' => '9999-12-31']))->getStatusCode(), 'far future date');

        foreach ($cases as $label => [$uri, $method, $body]) {
            $status = $admin->{$method}($uri, ...($method === 'getJson' ? [] : [$body]))->getStatusCode();
            $this->assertGreaterThanOrEqual(400, $status, $label);
            $this->assertLessThan(500, $status, "{$label} must be refused as a client error");
        }
    }
}
