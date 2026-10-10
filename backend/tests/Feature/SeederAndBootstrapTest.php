<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoOa4Seeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Seeding and bootstrap (OA0 §33-34): production-safe, idempotent, no default credentials; demo data documented. */
class SeederAndBootstrapTest extends TestCase
{
    use Fixtures;

    private const TABLES = ['modules', 'module_dependencies', 'features', 'permissions', 'roles', 'role_permissions'];

    private function snapshot(): array
    {
        return array_combine(self::TABLES, array_map(fn ($t) => DB::table($t)->count(), self::TABLES));
    }

    public function test_the_production_seeder_is_idempotent_and_creates_no_tenants_users_or_commercial_data(): void
    {
        $first = $this->snapshot();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($first, $this->snapshot());
        $this->assertGreaterThan(0, $first['modules']);
        foreach (['tenants', 'users', 'tenant_users', 'subscriptions', 'bundles', 'branches', 'tenant_module_entitlements', 'personal_access_tokens'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must stay empty");
        }
    }

    public function test_seeding_never_overwrites_catalog_edits_made_by_an_operator(): void
    {
        DB::table('modules')->where('code', 'ACCOUNTING_AP')->update(['name' => 'Hutang Usaha']);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('Hutang Usaha', DB::table('modules')->where('code', 'ACCOUNTING_AP')->value('name'));
    }

    public function test_the_catalog_matches_the_documented_modules(): void
    {
        $documented = ['ACCOUNTING_CORE', 'ACCOUNTING_AP', 'ACCOUNTING_EXPENSE', 'ACCOUNTING_CASH_BANK', 'ACCOUNTING_AR', 'ACCOUNTING_BUDGET',
            'ACCOUNTING_FIXED_ASSET', 'ACCOUNTING_TAX', 'ACCOUNTING_MULTI_CURRENCY', 'ACCOUNTING_REPORTING', 'ACCOUNTING_INTEGRATION', 'ACCOUNTING_ANALYTICS'];

        $this->assertEqualsCanonicalizing($documented, DB::table('modules')->pluck('code')->all());
        $this->assertSame(12, DB::table('module_dependencies')->count());
    }

    public function test_bootstrap_creates_the_first_platform_administrator_who_can_sign_in(): void
    {
        putenv('OPTIENTRY_BOOTSTRAP_PASSWORD='.self::PASSWORD);

        $this->artisan('optientry:bootstrap-platform-admin', ['email' => 'root@example.test', '--name' => 'Root'])
            ->expectsOutputToContain('Platform administrator ready')->assertExitCode(0);
        putenv('OPTIENTRY_BOOTSTRAP_PASSWORD');

        $user = User::query()->where('email', 'root@example.test')->firstOrFail();
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertSame(1, DB::table('platform_role_assignments')->where('user_id', $user->id)->count());

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'root@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->assertSame('platform', $login->json('scope'));
        $this->as($login->json('token'))->getJson('/api/v1/platform/tenants')->assertOk();
    }

    public function test_bootstrap_is_idempotent_and_does_not_reset_an_existing_password(): void
    {
        putenv('OPTIENTRY_BOOTSTRAP_PASSWORD='.self::PASSWORD);
        $this->artisan('optientry:bootstrap-platform-admin', ['email' => 'root@example.test'])->assertExitCode(0);
        $hash = User::query()->where('email', 'root@example.test')->value('password');

        putenv('OPTIENTRY_BOOTSTRAP_PASSWORD=An0ther!Passw0rd#2');
        $this->artisan('optientry:bootstrap-platform-admin', ['email' => 'ROOT@example.test'])->expectsOutputToContain('already a platform administrator')->assertExitCode(0);
        putenv('OPTIENTRY_BOOTSTRAP_PASSWORD');

        $this->assertSame($hash, User::query()->where('email', 'root@example.test')->value('password'));
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, DB::table('platform_role_assignments')->count());
    }

    public function test_bootstrap_refuses_a_weak_password(): void
    {
        putenv('OPTIENTRY_BOOTSTRAP_PASSWORD=weak');

        $this->artisan('optientry:bootstrap-platform-admin', ['email' => 'root@example.test'])->assertExitCode(1);
        putenv('OPTIENTRY_BOOTSTRAP_PASSWORD');

        $this->assertSame(0, User::query()->count());
    }

    public function test_bootstrap_can_promote_an_existing_user_without_touching_their_password(): void
    {
        $existing = User::query()->forceCreate(['name' => 'Existing', 'email' => 'exists@example.test', 'password' => bcrypt(self::PASSWORD), 'status' => 'ACTIVE']);
        $hash = $existing->password;

        $this->artisan('optientry:bootstrap-platform-admin', ['email' => 'exists@example.test'])->expectsOutputToContain('The user exists')->assertExitCode(0);

        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertSame(1, DB::table('platform_role_assignments')->where('user_id', $existing->id)->count());
    }

    public function test_the_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not run in production');
        $this->app->make(DemoSeeder::class)->run(); // called directly: db:seed itself would ask for confirmation first
    }

    public function test_the_demo_seeder_is_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $counts = fn () => array_map(fn ($t) => DB::table($t)->count(), ['users', 'tenants', 'tenant_users', 'subscriptions', 'bundles', 'branches', 'business_units', 'roles', 'data_scopes', 'tenant_module_entitlements']);
        $first = $counts();

        $this->seed(DemoSeeder::class);

        $this->assertSame($first, $counts());
    }

    public function test_every_documented_demo_login_works_and_lands_where_the_documentation_says(): void
    {
        $this->seed(DemoSeeder::class);
        $doc = file_get_contents(base_path('../docs/DEMO.md'));
        preg_match_all('/^\|\s*`([^`|]+@[^`|]+)`\s*\|\s*`([^`]+)`\s*\|\s*([a-z]+)\s*\|/m', $doc, $rows, PREG_SET_ORDER);

        $this->assertGreaterThanOrEqual(9, count($rows), 'docs/DEMO.md must list the demo logins');
        foreach ($rows as [, $email, $password, $scope]) {
            $this->flushHeaders();
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
                ->assertOk()->assertJsonPath('scope', $scope);
        }
    }

    public function test_demo_tenants_show_the_documented_commercial_states(): void
    {
        $this->seed(DemoSeeder::class);
        $status = fn (string $code) => DB::table('tenants')->where('code', $code)->value('status');
        $sub = fn (string $code) => DB::table('subscriptions')->where('tenant_id', DB::table('tenants')->where('code', $code)->value('id'))->value('status');

        $this->assertSame(['ACTIVE', 'ACTIVE'], [$status('maju-jaya'), $sub('maju-jaya')]);
        $this->assertSame(['ACTIVE', 'PAST_DUE'], [$status('tunggakan'), $sub('tunggakan')]);
        $this->assertSame('SUSPENDED', $status('ditangguhkan'));

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@tunggakan.demo.test', 'password' => DemoSeeder::DEFAULT_PASSWORD])->assertOk()->json('token');
        $this->as($token)->getJson('/api/v1/app/branches')->assertOk();
        $this->as($token)->postJson('/api/v1/app/account/ping', [])->assertStatus(404); // sanity: unknown route is not a gate bypass

        $multi = $this->postJson('/api/v1/auth/login', ['email' => 'multi@demo.test', 'password' => DemoSeeder::DEFAULT_PASSWORD])->assertOk();
        $this->assertSame('identity', $multi->json('scope'));
        $this->assertCount(2, $multi->json('tenants'));
    }

    public function test_demo_users_are_limited_by_their_scope_and_role_permissions(): void
    {
        $this->seed(DemoSeeder::class);
        $login = fn (string $email) => $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => DemoSeeder::DEFAULT_PASSWORD])->assertOk()->json('token');

        $viewer = $login('viewer@majujaya.demo.test');
        $this->as($viewer)->getJson('/api/v1/app/branches')->assertOk();
        $this->as($viewer)->postJson('/api/v1/app/branches', ['code' => 'X', 'name' => 'X'])->assertForbidden();

        $finance = $login('keuangan@majujaya.demo.test');
        $this->as($finance)->getJson('/api/v1/app/audit-logs')->assertOk();
        $this->as($finance)->getJson('/api/v1/app/users')->assertForbidden();

        $admin = $login('admin@majujaya.demo.test');
        $this->as($admin)->getJson('/api/v1/app/users')->assertOk()->assertJsonPath('total', 7); // admin, 5 staff (incl. accountant and finance manager), multi-tenant viewer
    }

    public function test_the_demo_books_carry_payables_expenses_and_cash_bank_in_every_state_and_stay_consistent(): void
    {
        $this->seed(DemoSeeder::class);
        $tenant = DB::table('tenants')->where('code', 'maju-jaya')->value('id');
        $count = fn (string $table, array $where = []) => DB::table($table)->where('tenant_id', $tenant)->where($where)->count();

        $this->assertSame(5, $count('vendors')); // four domestic vendors and the USD one (OA4)
        $this->assertSame(2, $count('cash_bank_accounts'));
        // posted: three OA2 invoices, two with tax codes and two in USD (OA4)
        $this->assertSame([7, 1, 1, 1], [$count('ap_invoices', ['status' => 'POSTED', 'origin' => 'INVOICE']), $count('ap_invoices', ['status' => 'SUBMITTED']), $count('ap_invoices', ['status' => 'APPROVED']), $count('ap_invoices', ['status' => 'DRAFT'])]);
        $this->assertSame([3, 1], [$count('vendor_payments', ['status' => 'POSTED']), $count('vendor_payments', ['status' => 'SUBMITTED'])]); // one of the posted ones is in USD
        $this->assertSame([2, 1, 1], [$count('expenses', ['status' => 'POSTED']), $count('expenses', ['status' => 'SUBMITTED']), $count('expenses', ['status' => 'DRAFT'])]);
        $this->assertSame([3, 1], [$count('cash_transactions', ['status' => 'POSTED']), $count('cash_transactions', ['status' => 'DRAFT'])]);
        $this->assertSame([3, 1], [$count('bank_statement_items', ['status' => 'MATCHED']), $count('bank_statement_items', ['status' => 'UNMATCHED'])]);

        // The payables control account equals what the subledger says is owed, and every posted journal balances.
        $ap = DB::table('accounts')->where('tenant_id', $tenant)->where('code', '2110')->value('id');
        $control = (string) DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.tenant_id', $tenant)->where('j.status', 'POSTED')
            ->where('l.account_id', $ap)->selectRaw('coalesce(sum(l.credit - l.debit), 0) as b')->value('b');
        // what is owed is carried in the functional currency: a USD invoice by its functional total, a USD payment by the value it released
        $owed = (string) DB::selectOne("select coalesce(sum(coalesce(i.functional_total_amount, i.total_amount) - coalesce((select sum(coalesce(a.carrying_amount, a.amount)) from ap_payment_allocations a
            join vendor_payments p on p.id = a.vendor_payment_id where a.ap_invoice_id = i.id and a.is_effective and p.status = 'POSTED'), 0)), 0) as owed
            from ap_invoices i where i.tenant_id = ? and i.status = 'POSTED'", [$tenant])->owed;
        $opening = '80000000'; // the demo opening balance already carries a payable that has no invoice behind it
        $this->assertEquals((float) $owed + (float) $opening, (float) $control, 'AP control = subledger + the opening payable');
        $this->assertSame(0, DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $tenant)->where('j.status', 'POSTED')
            ->groupBy('j.id')->havingRaw('sum(l.debit) <> sum(l.credit)')->select('j.id')->get()->count());

        // The demo accountant prepares but cannot approve; the manager approves and posts.
        $login = fn (string $email) => $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => DemoSeeder::DEFAULT_PASSWORD])->assertOk()->json('token');
        $accountant = $login('akuntan@majujaya.demo.test');
        $manager = $login('manajer@majujaya.demo.test');
        $this->as($accountant)->getJson('/api/v1/app/accounting/vendors')->assertOk()->assertJsonPath('total', 5);
        $submitted = DB::table('ap_invoices')->where('tenant_id', $tenant)->where('status', 'SUBMITTED')->value('id');
        $this->as($accountant)->postJson("/api/v1/app/accounting/ap-invoices/{$submitted}/approve")->assertForbidden();
        $this->as($manager)->postJson("/api/v1/app/accounting/ap-invoices/{$submitted}/approve")->assertOk();
        $this->as($manager)->getJson('/api/v1/app/accounting/operational-summary')->assertOk();
    }

    public function test_the_demo_books_carry_receivables_in_every_state_and_reconcile_to_the_control_account(): void
    {
        $this->seed(DemoSeeder::class);
        $tenant = DB::table('tenants')->where('code', 'maju-jaya')->value('id');
        $count = fn (string $table, array $where = []) => DB::table($table)->where('tenant_id', $tenant)->where($where)->count();

        $this->assertSame(6, $count('customers')); // four domestic customers, one in USD and one in SGD (OA4)
        // posted: four OA3 invoices, two with tax codes, one in USD and one in SGD (OA4)
        $this->assertSame([8, 1, 1, 1], [$count('ar_invoices', ['status' => 'POSTED']), $count('ar_invoices', ['status' => 'SUBMITTED']), $count('ar_invoices', ['status' => 'APPROVED']), $count('ar_invoices', ['status' => 'DRAFT'])]);
        $this->assertSame([3, 1], [$count('customer_receipts', ['status' => 'POSTED']), $count('customer_receipts', ['status' => 'SUBMITTED'])]); // one of the posted ones is in USD
        $this->assertSame(1, $count('ar_credit_notes', ['status' => 'POSTED']));

        $login = fn (string $email) => $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => DemoSeeder::DEFAULT_PASSWORD])->assertOk()->json('token');
        $accountant = $login('akuntan@majujaya.demo.test');
        $manager = $login('manajer@majujaya.demo.test');

        // The receivables control account equals the subledger plus the opening-balance receivable, and the reconciliation says so.
        $reconciliation = $this->as($manager)->getJson('/api/v1/app/accounting/reconciliation/ar')->assertOk()->assertJsonPath('status', 'MATCHED')->json();
        // The subledger is carried in the functional currency (the USD and SGD invoices by what they were recognised at), so the aging adds up to the same figure.
        $this->assertSame(['120000000.0000', '0.0000'], [$reconciliation['opening_balance_component'], $reconciliation['difference']]);
        $this->assertGreaterThan(0, (float) $reconciliation['subledger_balance']);
        $this->as($manager)->getJson('/api/v1/app/accounting/ar-aging')->assertOk()->assertJsonPath('totals.total', $reconciliation['subledger_balance']);
        $this->assertSame(0, DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $tenant)->where('j.status', 'POSTED')
            ->groupBy('j.id')->havingRaw('sum(l.debit) <> sum(l.credit)')->select('j.id')->get()->count());

        // The demo accountant prepares but cannot approve; the manager approves and posts.
        $this->as($accountant)->getJson('/api/v1/app/accounting/customers')->assertOk()->assertJsonPath('total', 6);
        $submitted = DB::table('ar_invoices')->where('tenant_id', $tenant)->where('status', 'SUBMITTED')->value('id');
        $this->as($accountant)->postJson("/api/v1/app/accounting/ar-invoices/{$submitted}/approve")->assertForbidden();
        $this->as($manager)->postJson("/api/v1/app/accounting/ar-invoices/{$submitted}/approve")->assertOk();
        $this->as($manager)->getJson('/api/v1/app/accounting/operational-summary')->assertOk()->assertJsonPath('receivables.pending_approval', 0);
    }

    public function test_the_demo_books_carry_budget_fixed_assets_tax_and_foreign_currency_and_stay_balanced(): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00'); // the figures below depend on the dates the seeder derives from today
        $this->seed(DemoSeeder::class);
        $tenant = DB::table('tenants')->where('code', 'maju-jaya')->value('id');
        $count = fn (string $table, array $where = []) => DB::table($table)->where('tenant_id', $tenant)->where($where)->count();

        // Budget: one active budget; the original version is active, a revision is still a draft.
        $this->assertSame(1, $count('budgets', ['status' => 'ACTIVE']));
        $this->assertSame([1, 1, 216], [$count('budget_versions', ['status' => 'ACTIVE']), $count('budget_versions', ['status' => 'DRAFT']), $count('budget_lines')]);

        // Fixed assets: two on the books, one draft, one sold; two depreciation runs posted and this month's calculated; the register equals the ledger.
        $this->assertSame(3, $count('asset_categories'));
        $this->assertSame([2, 1, 1], [$count('fixed_assets', ['status' => 'ACTIVE']), $count('fixed_assets', ['status' => 'DRAFT']), $count('fixed_assets', ['status' => 'DISPOSED'])]);
        $this->assertSame([2, 1, 1], [$count('asset_depreciation_runs', ['status' => 'POSTED']), $count('asset_depreciation_runs', ['status' => 'DRAFT']), $count('asset_disposals', ['status' => 'POSTED'])]);

        // Tax: six codes with effective-dated rates, eight posted tax lines on two purchase and two sales invoices.
        $this->assertSame([6, 8, 8], [$count('tax_codes'), $count('tax_rates'), $count('tax_transactions', ['status' => 'POSTED'])]);

        // Multi-currency: USD and SGD, USD and SGD documents, and the realised difference of the part-paid USD invoice (loss) and part-collected one (gain).
        $this->assertSame(2, $count('currencies'));
        $this->assertSame([2, 1, 1, 1], [$count('ap_invoices', ['currency' => 'USD', 'status' => 'POSTED']), $count('ar_invoices', ['currency' => 'USD', 'status' => 'POSTED']),
            $count('ar_invoices', ['currency' => 'SGD', 'status' => 'POSTED']), $count('vendor_payments', ['currency' => 'USD', 'status' => 'POSTED'])]);
        $fx = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.tenant_id', $tenant)->where('l.is_fx_difference', true)
            ->orderBy('a.code')->get(['a.code', 'l.debit', 'l.credit'])->map(fn ($r) => [$r->code, (string) $r->debit, (string) $r->credit])->all();
        $this->assertSame([['4260', '0.0000', '222500.0000'], ['6950', '875000.0000', '0.0000']], $fx);

        // Every posted journal balances in the functional currency and, apart from the exchange difference line, in each transaction currency.
        $this->assertSame(0, DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $tenant)->where('j.status', 'POSTED')
            ->groupBy('j.id')->havingRaw('sum(l.debit) <> sum(l.credit)')->select('j.id')->get()->count());
        $this->assertSame(0, DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $tenant)->where('j.status', 'POSTED')
            ->where('l.is_fx_difference', false)->groupBy('j.id', 'l.transaction_currency')->havingRaw('sum(l.transaction_debit) <> sum(l.transaction_credit)')->select('j.id')->get()->count());

        // The Business bundle carries the four OA4 modules for both tenants on it; the finance roles hold the OA4 permissions.
        foreach (['maju-jaya', 'tunggakan'] as $code) {
            $this->assertSame(4, DB::table('tenant_module_entitlements as e')->join('modules as m', 'm.id', '=', 'e.module_id')->join('tenants as t', 't.id', '=', 'e.tenant_id')
                ->where('t.code', $code)->whereIn('m.code', DemoOa4Seeder::MODULES)->count());
        }
        $held = fn (string $role) => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('r.tenant_id', $tenant)->where('r.name', $role)->whereIn('p.code', [...DemoOa4Seeder::ACCOUNTANT_PERMISSIONS, ...DemoOa4Seeder::MANAGER_PERMISSIONS])->count();
        $this->assertSame([12, 21], [$held('Akuntan'), $held('Manajer Keuangan')]);

        $login = fn (string $email) => $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => DemoSeeder::DEFAULT_PASSWORD])->assertOk()->json('token');
        $accountant = $login('akuntan@majujaya.demo.test');
        $manager = $login('manajer@majujaya.demo.test');
        $viewer = $login('viewer@majujaya.demo.test');
        $overdue = $login('admin@tunggakan.demo.test');

        // Reports: the tax report reads the frozen tax of the posted invoices, the payables and receivables still reconcile, the register equals the ledger.
        $totals = $this->as($accountant)->getJson('/api/v1/app/accounting/tax-report')->assertOk()->json('totals');
        $this->assertSame(['4950000.0000', '3190000.0000', '275000.0000', '1760000.0000'], [$totals['output_tax'], $totals['input_tax_recoverable'], $totals['input_tax_non_recoverable'], $totals['net_payable']]);
        $this->as($manager)->getJson('/api/v1/app/accounting/reconciliation/ap')->assertOk()->assertJsonPath('status', 'MATCHED');
        $this->as($manager)->getJson('/api/v1/app/accounting/reconciliation/ar')->assertOk()->assertJsonPath('status', 'MATCHED');
        $this->as($accountant)->getJson('/api/v1/app/accounting/asset-reconciliation')->assertOk()->assertJsonPath('totals.difference', '0.0000');

        // The accountant reads and prepares but does not configure tax or exchange rates; the viewer reads; the overdue tenant reads and cannot change.
        $this->as($accountant)->getJson('/api/v1/app/accounting/budgets')->assertOk()->assertJsonPath('total', 1);
        $this->as($accountant)->postJson('/api/v1/app/accounting/tax-codes', ['code' => 'X', 'name' => 'X', 'tax_type' => 'OTHER', 'rate' => '1', 'effective_from' => '2026-10-10'])->assertForbidden();
        $this->as($accountant)->postJson('/api/v1/app/accounting/exchange-rates', ['from_currency' => 'USD', 'rate' => '16400', 'effective_date' => '2026-10-10'])->assertForbidden();
        $this->as($viewer)->getJson('/api/v1/app/accounting/tax-codes')->assertOk()->assertJsonPath('total', 6);
        $this->as($viewer)->getJson('/api/v1/app/accounting/assets')->assertOk()->assertJsonPath('total', 4);
        $this->as($overdue)->getJson('/api/v1/app/accounting/tax-codes')->assertOk();
        $this->as($overdue)->postJson('/api/v1/app/accounting/currencies', ['code' => 'EUR', 'name' => 'Euro'])->assertForbidden();

        Carbon::setTestNow();
    }
}
