<?php

namespace Tests\Support;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Closing\Services\YearEndSetupService;
use App\Domain\FixedAsset\Services\AssetSetupService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the OA5 verification tests (tests/Feature/Oa5, tests/Concurrency/Oa5*). Combine with Fixtures, AccountingFixtures, PayablesFixtures,
 * ReceivablesFixtures, TaxFixtures, FxFixtures, FixedAssetFixtures, ReportingFixtures, ClosingFixtures and ReconciliationFixtures. Helpers that call
 * the API act as the already-authenticated client.
 */
trait Oa5Fixtures
{
    /** A tenant with every module, every OA2-OA4 posting rule, the year-end rule and no segregation-of-duties limit; calendar year 2026. */
    protected function oa5Tenant(string $code = 'alpha', array $profile = []): Tenant
    {
        $tenant = $this->fxTenant($code, $profile);
        $this->inTenant($tenant, function () {
            app(AssetSetupService::class)->applyDefaults('2026-01-01');
            app(YearEndSetupService::class)->applyDefaults('2026-01-01');
        });

        return $tenant;
    }

    /** Move one module of the tenant to a state and/or entitlement window (null keeps a field) and drop the access cache. */
    protected function moduleWindow(Tenant $tenant, string $module, array $attrs): void
    {
        DB::table('tenant_module_entitlements')->where('tenant_id', $tenant->id)->where('module_id', Module::query()->where('code', $module)->value('id'))->update($attrs);
        app(AccessCache::class)->touchTenant($tenant->id);
    }

    /** @return array<string,mixed> the module entitlement row, to put back with moduleWindow */
    protected function moduleRow(Tenant $tenant, string $module): array
    {
        $row = DB::table('tenant_module_entitlements')->where('tenant_id', $tenant->id)->where('module_id', Module::query()->where('code', $module)->value('id'))->first();

        return ['state' => $row->state, 'effective_from' => $row->effective_from, 'effective_until' => $row->effective_until];
    }

    /** @param list<string> $tables @return array<string,string> table => hash of the tenant's rows */
    protected function rowHashes(Tenant $tenant, array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = md5(DB::table($table)->where('tenant_id', $tenant->id)->orderBy('id')->get()->toJson());
        }

        return $out;
    }

    /** Posted documents of every OA2-OA4 area in March 2026 as the signed-in client: AP invoice + part payment, AR invoice + part receipt, payable expense, cash payment, asset + March depreciation. */
    protected function marchDocuments(Tenant $tenant): void
    {
        $bank = $this->cashAccount($tenant, 'BCA');
        $this->signedIn($tenant);
        $vendor = $this->vendor($tenant, 'V1');
        $invoice = $this->postedInvoice($vendor);
        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '300000']);
        $customer = $this->customer($tenant, 'C1');
        $ar = $this->postedArInvoice($customer);
        $this->postedReceipt($customer, $bank->id, [$ar['id'] => '400000']);
        $this->postedExpense($this->payableExpenseBody($this->expenseCategory($tenant, 'UTIL')->id, $vendor));
        $cash = $this->postJson(self::AP.'/cash-payments', [
            'cash_bank_account_id' => $bank->id, 'counter_account_id' => $this->account($tenant, '6900')->id, 'amount' => '150000',
            'transaction_date' => '2026-03-12', 'purpose' => 'Biaya administrasi bank', 'description' => 'Biaya administrasi', 'reference' => 'ADM-03',
        ])->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-payments/{$cash}/post")->assertOk();
        $this->capitalizedAsset($tenant, $this->newCategory());
        $this->postedRun($tenant, '2026-03');
    }

    /** Debit minus credit of the POSTED lines of a P&L-style account up to a date (4-place string). */
    protected function net(Tenant $tenant, string $code, ?string $upTo = null): string
    {
        return $this->glBalance($tenant, $code, $upTo);
    }

    /** Fail when any needle appears in the haystack. @param list<string> $needles */
    protected function assertNoneOf(array $needles, string $haystack, string $label = ''): void
    {
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $haystack, $label);
        }
    }
}
