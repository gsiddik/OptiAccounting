<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\RoleService;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Budget\Models\Budget;
use App\Domain\Budget\Services\BudgetService;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\Currency\Models\Currency;
use App\Domain\Currency\Services\CurrencyService;
use App\Domain\Currency\Services\ExchangeRateService;
use App\Domain\Currency\Services\FxSetupService;
use App\Domain\Entitlement\Models\Subscription;
use App\Domain\Entitlement\Models\TenantFeatureEntitlement;
use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\FixedAsset\Models\AssetCategory;
use App\Domain\FixedAsset\Models\DepreciationScheduleRow;
use App\Domain\FixedAsset\Models\FixedAsset;
use App\Domain\FixedAsset\Services\AssetCategoryService;
use App\Domain\FixedAsset\Services\AssetDisposalService;
use App\Domain\FixedAsset\Services\AssetSetupService;
use App\Domain\FixedAsset\Services\DepreciationRunService;
use App\Domain\FixedAsset\Services\FixedAssetService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Services\ApInvoiceService;
use App\Domain\Payables\Services\VendorPaymentService;
use App\Domain\Payables\Services\VendorService;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use App\Domain\Receivables\Models\Customer;
use App\Domain\Receivables\Services\ArInvoiceService;
use App\Domain\Receivables\Services\CustomerReceiptService;
use App\Domain\Receivables\Services\CustomerService;
use App\Domain\Tax\Models\TaxCode;
use App\Domain\Tax\Services\TaxCodeService;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * OA4 demo data for the demo tenant that already has books, payables and receivables (called by DemoSeeder after the OA3 data; never by
 * DatabaseSeeder): the OA4 module entitlements and role permissions, a budget with an active and a draft version, tax codes with effective-dated
 * rates and tax-coded invoices, foreign currencies with exchange rates and foreign invoices settled at another rate, and a small fixed
 * asset register (active with a depreciation run, draft, disposed). Everything goes through the same services as the API, the accountant
 * preparing and the manager approving and posting, so every accounting invariant applies to this data too. Dates are relative to today and stay
 * inside the open periods. Every section skips itself once its marker exists, so running the seeder again changes nothing.
 */
class DemoOa4Seeder
{
    /** The OA4 modules the demo Business bundle includes (ACCOUNTING_TAX is part of it since OA2). */
    public const MODULES = ['ACCOUNTING_BUDGET', 'ACCOUNTING_FIXED_ASSET', 'ACCOUNTING_TAX', 'ACCOUNTING_MULTI_CURRENCY'];

    /** What the bookkeeper may do with OA4: read everything, prepare budgets, assets, depreciation runs and disposals, read tax and exchange rates. */
    public const ACCOUNTANT_PERMISSIONS = [
        'accounting.budget.view', 'accounting.budget.manage', 'accounting.budget.submit',
        'accounting.asset.view', 'accounting.asset.manage', 'accounting.asset.depreciation.run', 'accounting.asset.dispose', 'accounting.asset.reconciliation.view',
        'accounting.tax.view', 'accounting.tax.report.view',
        'accounting.currency.view', 'accounting.exchange_rate.view',
    ];

    /** What the finance manager adds: budget approval, asset categories, capitalization, posting and approval, tax and currency configuration. */
    public const MANAGER_PERMISSIONS = [
        'accounting.budget.approve',
        'accounting.asset_category.manage', 'accounting.asset.capitalize', 'accounting.asset.depreciation.post', 'accounting.asset.disposal.approve', 'accounting.asset.disposal.post',
        'accounting.tax.manage',
        'accounting.currency.manage', 'accounting.exchange_rate.manage',
    ];

    /** [code, name, rate history [[percent, effective from]], type, method, treatment, recoverable]. Example rates, not a statement of tax law. */
    private const TAX_CODES = [
        ['PPN-IN', 'PPN Masukan 11%', [['10', '2020-01-01'], ['11', '2022-04-01']], 'INPUT_TAX', 'EXCLUSIVE', 'STANDARD', true],
        ['PPN-IN-NK', 'PPN Masukan 11% (tidak dapat dikreditkan)', [['11', '2022-04-01']], 'INPUT_TAX', 'EXCLUSIVE', 'STANDARD', false],
        ['PPN-OUT', 'PPN Keluaran 11%', [['10', '2020-01-01'], ['11', '2022-04-01']], 'OUTPUT_TAX', 'EXCLUSIVE', 'STANDARD', true],
        ['PPN-OUT-INK', 'PPN Keluaran 11% (harga sudah termasuk PPN)', [['11', '2022-04-01']], 'OUTPUT_TAX', 'INCLUSIVE', 'STANDARD', true],
        ['PPN-OUT-0', 'PPN Keluaran 0% (tarif nol)', [['0', '2020-01-01']], 'OUTPUT_TAX', 'EXCLUSIVE', 'ZERO_RATED', true],
        ['PPH23', 'PPh 23 jasa 2% (dipotong pihak lain)', [['2', '2020-01-01']], 'WITHHOLDING', 'EXCLUSIVE', 'STANDARD', true],
    ];

    /** Exchange rates into IDR by days ago (DAILY); the bank's actual rate on the payment day is entered as a MANUAL rate on top. */
    private const RATES = [
        'USD' => [42 => '16080', 35 => '16150', 28 => '16230.5', 21 => '16190', 14 => '16310.25', 7 => '16275', 3 => '16340', 0 => '16360'],
        'SGD' => [42 => '11950', 35 => '12010', 28 => '12060', 21 => '12040', 14 => '12090', 7 => '12075', 0 => '12100'],
    ];

    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    private TenantContext $context;

    private User $accountant;

    private User $manager;

    private string $today;

    private string $floor;

    private int $year;

    private ?string $branch = null;

    private ?string $branchSby = null;

    /** @var array<string,string> */
    private array $accounts = [];

    // ------------------------------------------------------------------------------------------------ entitlements

    /**
     * Bring the bundle-sourced entitlements of a tenant up to its subscription's bundle: a tenant created before OA4 lacks the rows of the modules
     * and features the bundle gained since. Does what SubscriptionService provisions at creation, so a fresh tenant is left untouched.
     */
    public function entitle(Tenant $tenant): void
    {
        if ($tenant->status !== Tenant::ACTIVE) {
            return;
        }

        app(TenantContext::class)->runAs($tenant->id, function () use ($tenant) {
            $subscription = Subscription::query()->whereIn('status', Subscription::LIVE)->whereNotNull('bundle_id')->first();
            $bundle = $subscription?->bundle()->with('modules.features')->first();
            if ($subscription === null || $bundle === null) {
                return;
            }

            $modules = $bundle->modules->keyBy('id');
            $haveModules = TenantModuleEntitlement::query()->pluck('module_id')->all();
            $haveFeatures = TenantFeatureEntitlement::query()->pluck('feature_id')->all();
            $entitlements = app(EntitlementService::class);
            $from = $subscription->starts_on->toDateString();
            $until = $subscription->ends_on?->toDateString();

            foreach (app(ModuleDependencyService::class)->orderByDependencies($modules->keys()->all()) as $id) {
                $module = $modules[$id];
                if (! in_array($id, $haveModules, true)) {
                    $entitlements->grantModule($tenant, $module, 'ACTIVE', 'BUNDLE', $from, $until, $subscription->id);
                }
                foreach ($module->features->where('status', 'ACTIVE') as $feature) {
                    if (! in_array($feature->id, $haveFeatures, true)) {
                        $entitlements->grantFeature($tenant, $feature, 'ACTIVE', 'BUNDLE', $from, $until);
                    }
                }
            }
        });
    }

    // ------------------------------------------------------------------------------------------------ seed

    public function seed(Tenant $tenant, User $admin, User $accountant, User $manager): void
    {
        $this->context = app(TenantContext::class);
        $this->accountant = $accountant;
        $this->manager = $manager;

        $this->context->runAs($tenant->id, function () use ($tenant, $admin) {
            if (! FiscalYear::query()->exists() || ! CashBankAccount::query()->where('code', 'BCA-OPS')->exists()) {
                return; // no demo books to build on
            }

            $today = now(config('app.timezone'));
            $this->today = $today->toDateString();
            $this->year = $today->year;
            $this->floor = $today->month >= 3 ? "{$today->year}-03-01" : "{$today->year}-01-01";
            $this->accounts = Account::query()->pluck('id', 'code')->all();
            $this->branch = Branch::query()->where('code', 'JKT')->value('id');
            $this->branchSby = Branch::query()->where('code', 'SBY')->value('id');

            $this->permissions($tenant, $admin);
            $this->budget();
            $this->taxCodes();
            $this->taxInvoices();
            $this->currencies();
            $this->foreignDocuments();
            $this->assets();
        });
    }

    /** The OA4 permissions of the demo finance roles; a role of an older demo database gets the missing ones. */
    private function permissions(Tenant $tenant, User $admin): void
    {
        $grants = ['Akuntan' => self::ACCOUNTANT_PERMISSIONS, 'Manajer Keuangan' => [...self::ACCOUNTANT_PERMISSIONS, ...self::MANAGER_PERMISSIONS]];
        foreach ($grants as $name => $wanted) {
            $role = Role::query()->forTenant($tenant->id)->where('name', $name)->first();
            if ($role === null) {
                continue;
            }
            $held = $role->permissions()->pluck('code')->all();
            $missing = array_values(array_diff($wanted, $held));
            if ($missing !== []) {
                app(RoleService::class)->update($role, $admin, null, null, [...$held, ...$missing]);
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ budget

    /** One active budget for the fiscal year (version 1 approved and active) and a draft revision that raises maintenance and fuel. */
    private function budget(): void
    {
        $code = "ANGGARAN-{$this->year}";
        $fiscalYear = FiscalYear::query()->where('code', "FY{$this->year}")->first();
        if ($fiscalYear === null || Budget::query()->where('code', $code)->exists()) {
            return;
        }

        $budgets = app(BudgetService::class);
        $periods = AccountingPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->orderBy('start_date')->pluck('id')->values()->all();

        $budget = $this->as($this->accountant, fn () => $budgets->create([
            'code' => $code, 'name' => "Anggaran Operasional {$this->year}", 'fiscal_year_id' => $fiscalYear->id, 'responsible_user_id' => $this->manager->id,
            'description' => 'Rencana pendapatan dan beban operasional tahun berjalan per bulan.',
        ], $this->accountant));
        $budget = $this->as($this->manager, fn () => $budgets->open($budget, $this->manager));

        $original = $this->as($this->accountant, fn () => $budgets->createVersion($budget, ['label' => 'Anggaran Awal', 'description' => 'Disusun akhir tahun lalu.'], $this->accountant));
        $original = $this->as($this->accountant, fn () => $budgets->replaceLines($original, $this->budgetRows($periods, []), $this->accountant));
        $original = $this->as($this->accountant, fn () => $budgets->submit($original, $this->accountant));
        $original = $this->as($this->manager, fn () => $budgets->approve($original, $this->manager));
        $this->as($this->manager, fn () => $budgets->activate($original, $this->manager));

        // A revision in preparation: the draft screens have something to show; it changes nothing until it is submitted, approved and activated.
        $revision = $this->as($this->accountant, fn () => $budgets->createVersion($budget, ['label' => 'Revisi 1 (draf)', 'description' => 'Usulan kenaikan biaya perawatan dan BBM.'], $this->accountant));
        $this->as($this->accountant, fn () => $budgets->replaceLines($revision, $this->budgetRows($periods, ['6400' => '1.15', '6500' => '1.10']), $this->accountant));
    }

    /**
     * Lines for the revenue and the main expense accounts in every period of the year (payroll split by branch).
     *
     * @param  list<string>  $periods  period ids in calendar order
     * @param  array<string,string>  $factors  account code => multiplier for a revision
     * @return list<array<string,mixed>>
     */
    private function budgetRows(array $periods, array $factors): array
    {
        $plan = [ // account code, branch id, monthly amount, growth per month, description
            ['4100', null, '60000000', '3000000', 'Target pendapatan usaha'],
            ['6100', $this->branch, '18000000', '0', 'Gaji kantor pusat'], ['6100', $this->branchSby, '10000000', '0', 'Gaji cabang Surabaya'],
            ['6200', null, '12000000', '0', 'Sewa kantor'], ['6300', null, '5000000', '0', 'Listrik, air dan telepon'],
            ['6400', null, '6000000', '0', 'Perawatan kendaraan'], ['6500', null, '8000000', '0', 'BBM armada'],
            ['6600', null, '9000000', '0', 'Penyusutan aset tetap'], ['6900', null, '4000000', '0', 'Beban umum dan administrasi'],
        ];

        $rows = [];
        foreach ($plan as [$account, $branchId, $amount, $growth, $description]) {
            foreach ($periods as $index => $periodId) {
                $value = BigDecimal::of($amount)->plus(BigDecimal::of($growth)->multipliedBy($index))->multipliedBy($factors[$account] ?? '1')->toScale(0, RoundingMode::HalfUp);
                $rows[] = ['account_id' => $this->accounts[$account], 'accounting_period_id' => $periodId, 'amount' => (string) $value, 'branch_id' => $branchId, 'description' => $description];
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------------------------------------------ tax

    private function taxCodes(): void
    {
        if (TaxCode::query()->where('code', 'PPN-IN')->exists()) {
            return;
        }

        $codes = app(TaxCodeService::class);
        foreach (self::TAX_CODES as [$code, $name, $history, $type, $method, $treatment, $recoverable]) {
            [$rate, $from] = array_shift($history);
            $created = $this->as($this->manager, fn () => $codes->create([
                'code' => $code, 'name' => $name, 'tax_type' => $type, 'calculation_method' => $method, 'treatment' => $treatment, 'is_recoverable' => $recoverable,
                'rate' => $rate, 'effective_from' => $from,
                'description' => $type === 'WITHHOLDING' ? 'Tarif contoh untuk laporan pajak; belum dapat dipakai pada dokumen.' : 'Tarif contoh untuk demo, bukan ketentuan perpajakan.',
            ], $this->manager));
            foreach ($history as [$next, $nextFrom]) {
                $this->as($this->manager, fn () => $codes->addRate($created, ['rate' => $next, 'effective_from' => $nextFrom], $this->manager));
            }
        }
    }

    /** Two purchase and two sales invoices whose lines carry tax codes (recoverable, non-recoverable, exclusive, inclusive and zero-rated). */
    private function taxInvoices(): void
    {
        $vendors = app(ApInvoiceService::class);
        $customers = app(ArInvoiceService::class);
        if (ApInvoice::query()->where('vendor_invoice_number', "SM-{$this->year}-0170")->exists()) {
            return;
        }
        $tax = fn (string $code) => TaxCode::query()->where('code', $code)->value('id');
        $vendor = fn (string $code) => Vendor::query()->where('code', $code)->firstOrFail();
        $customer = fn (string $code) => Customer::query()->where('code', $code)->firstOrFail();
        $purchase = fn (Vendor $v, string $number, string $description, int $daysAgo, array $lines) => $this->posted($vendors, [
            'vendor_id' => $v->id, 'vendor_invoice_number' => $number, 'document_date' => $this->date($daysAgo), 'posting_date' => $this->date($daysAgo),
            'description' => $description, 'branch_id' => $this->branch, 'lines' => $lines,
        ]);
        $sale = fn (Customer $c, string $reference, string $description, int $daysAgo, array $lines) => $this->posted($customers, [
            'customer_id' => $c->id, 'customer_reference' => $reference, 'document_date' => $this->date($daysAgo), 'posting_date' => $this->date($daysAgo),
            'description' => $description, 'branch_id' => $this->branch, 'lines' => $lines,
        ]);
        $car = $this->accounts['6400'];

        $purchase($vendor('SUMBER'), "SM-{$this->year}-0170", 'Suku cadang armada dengan faktur pajak', 20, [
            ['description' => 'Suku cadang rem dan kopling', 'amount' => '15000000', 'tax_code_id' => $tax('PPN-IN'), 'account_id' => $car],
            ['description' => 'Filter dan oli mesin', 'amount' => '8000000', 'tax_code_id' => $tax('PPN-IN'), 'account_id' => $car],
        ]);
        $purchase($vendor('SERVIS'), 'AS-327', 'Servis armada, sebagian tanpa faktur pajak sah', 8, [
            ['description' => 'Jasa servis berkala', 'amount' => '6000000', 'tax_code_id' => $tax('PPN-IN'), 'account_id' => $car],
            ['description' => 'Suku cadang tanpa faktur pajak yang sah', 'amount' => '2500000', 'tax_code_id' => $tax('PPN-IN-NK'), 'account_id' => $car],
        ]);
        $sale($customer('LOGISTIK'), 'PO-LN-0470', 'Jasa angkut dan penanganan kontainer', 14, [
            ['description' => 'Jasa angkut kontainer', 'amount' => '30000000', 'tax_code_id' => $tax('PPN-OUT')],
            ['description' => 'Biaya penanganan', 'amount' => '5000000', 'tax_code_id' => $tax('PPN-OUT')],
        ]);
        $sale($customer('RETAIL'), 'PO-RM-142', 'Distribusi retail dan pengiriman luar negeri', 5, [
            ['description' => 'Distribusi barang retail (harga sudah termasuk PPN)', 'amount' => '11100000', 'tax_code_id' => $tax('PPN-OUT-INK')],
            ['description' => 'Pengiriman paket ke luar negeri (tarif nol)', 'amount' => '4000000', 'tax_code_id' => $tax('PPN-OUT-0')],
        ]);
    }

    // ------------------------------------------------------------------------------------------------ multi-currency

    /** USD and SGD with rates over the last six weeks, and the exchange gain and loss posting rules. */
    private function currencies(): void
    {
        if (Currency::query()->where('code', 'USD')->exists()) {
            return;
        }

        $this->as($this->manager, fn () => app(FxSetupService::class)->applyDefaults("{$this->year}-01-01"));

        $currencies = app(CurrencyService::class);
        $rates = app(ExchangeRateService::class);
        foreach ([['USD', 'Dolar Amerika Serikat', '$'], ['SGD', 'Dolar Singapura', 'S$']] as [$code, $name, $symbol]) {
            $this->as($this->manager, fn () => $currencies->create(['code' => $code, 'name' => $name, 'symbol' => $symbol, 'decimal_places' => 2], $this->manager));
        }

        $seen = [];
        foreach (self::RATES as $code => $series) {
            foreach ($series as $daysAgo => $rate) {
                $date = $this->date($daysAgo);
                if (isset($seen[$code][$date])) {
                    continue; // early in the year several days fold onto the first open day; one rate per day
                }
                $seen[$code][$date] = true;
                $this->as($this->manager, fn () => $rates->create(['from_currency' => $code, 'rate' => $rate, 'effective_date' => $date, 'rate_type' => 'DAILY', 'source' => 'Kurs demo'], $this->manager));
            }
        }
        // what the bank actually used on the day the vendor was paid: a MANUAL rate wins over the daily one of the same date
        $this->as($this->manager, fn () => $rates->create([
            'from_currency' => 'USD', 'rate' => '16325', 'effective_date' => $this->date(14), 'rate_type' => 'MANUAL', 'source' => 'Slip transfer bank', 'notes' => 'Kurs jual bank saat pembayaran vendor',
        ], $this->manager));
    }

    /** USD purchases (one part-paid at another rate), a USD sale part-collected at another rate, and an unpaid SGD sale. */
    private function foreignDocuments(): void
    {
        if (Vendor::query()->where('code', 'GLOBAL')->exists()) {
            return;
        }

        $term = fn (string $code) => PaymentTerm::query()->where('code', $code)->value('id');
        $global = $this->as($this->manager, fn () => app(VendorService::class)->create(['code' => 'GLOBAL', 'name' => 'Global Parts Pte Ltd', 'payment_term_id' => $term('NET30'), 'email' => 'billing@globalparts.demo.test']));
        $pacific = $this->as($this->manager, fn () => app(CustomerService::class)->create(['code' => 'PACIFIC', 'name' => 'Pacific Trading Inc', 'payment_term_id' => $term('NET30'), 'email' => 'ap@pacifictrading.demo.test']));
        $singmart = $this->as($this->manager, fn () => app(CustomerService::class)->create(['code' => 'SINGMART', 'name' => 'Singmart Trading Pte Ltd', 'payment_term_id' => $term('NET30')]));

        $purchases = app(ApInvoiceService::class);
        $sales = app(ArInvoiceService::class);
        $bank = CashBankAccount::query()->where('code', 'BCA-OPS')->firstOrFail();

        $parts = $this->posted($purchases, [
            'vendor_id' => $global->id, 'vendor_invoice_number' => "GP-{$this->year}-0412", 'document_date' => $this->date(35), 'posting_date' => $this->date(35), 'currency' => 'USD',
            'description' => 'Suku cadang impor (USD)', 'branch_id' => $this->branch,
            'lines' => [['description' => 'Suku cadang rem dan kopling impor', 'amount' => '12500.00', 'account_id' => $this->accounts['6400']]],
        ]);
        $this->posted($purchases, [
            'vendor_id' => $global->id, 'vendor_invoice_number' => "GP-{$this->year}-0455", 'document_date' => $this->date(7), 'posting_date' => $this->date(7), 'currency' => 'USD',
            'description' => 'Lisensi perangkat lunak armada (USD)', 'branch_id' => $this->branch,
            'lines' => [['description' => 'Lisensi tahunan perangkat lunak manajemen armada', 'amount' => '3200.00', 'account_id' => $this->accounts['6900']]],
        ]);
        $this->posted(app(VendorPaymentService::class), [
            'vendor_id' => $global->id, 'cash_bank_account_id' => $bank->id, 'payment_date' => $this->date(14), 'posting_date' => $this->date(14), 'currency' => 'USD', 'amount' => '5000.00',
            'payment_method' => 'TRANSFER', 'reference' => 'TRF-USD-0001', 'description' => 'Pembayaran sebagian faktur Global Parts', 'branch_id' => $this->branch,
            'allocations' => [['ap_invoice_id' => $parts->id, 'amount' => '5000.00']],
        ]);

        $export = $this->posted($sales, [
            'customer_id' => $pacific->id, 'customer_reference' => "PO-PT-{$this->year}-2210", 'document_date' => $this->date(28), 'posting_date' => $this->date(28), 'currency' => 'USD',
            'description' => 'Jasa angkut kontainer ekspor (USD)', 'branch_id' => $this->branch,
            'lines' => [['description' => 'Jasa angkut kontainer ekspor', 'amount' => '8000.00']],
        ]);
        $this->posted($sales, [
            'customer_id' => $singmart->id, 'customer_reference' => "PO-SM-{$this->year}-0093", 'document_date' => $this->date(21), 'posting_date' => $this->date(21), 'currency' => 'SGD',
            'description' => 'Jasa distribusi regional (SGD)', 'branch_id' => $this->branch,
            'lines' => [['description' => 'Jasa distribusi regional', 'amount' => '6000.00']],
        ]);
        $this->posted(app(CustomerReceiptService::class), [
            'customer_id' => $pacific->id, 'cash_bank_account_id' => $bank->id, 'receipt_date' => $this->date(7), 'posting_date' => $this->date(7), 'currency' => 'USD', 'amount' => '5000.00',
            'receipt_method' => 'TRANSFER', 'reference' => 'TRF-IN-USD-0001', 'description' => 'Penerimaan sebagian faktur Pacific Trading', 'branch_id' => $this->branch,
            'allocations' => [['ar_invoice_id' => $export->id, 'amount' => '5000.00']],
        ]);
    }

    // ------------------------------------------------------------------------------------------------ fixed assets

    /**
     * Three categories and four assets: a truck and laptops capitalized and depreciated, a forklift still a draft and a car sold after
     * depreciation. The depreciation runs of the past months are posted; the current month is calculated and waits for posting.
     */
    private function assets(): void
    {
        if (AssetCategory::query()->where('code', 'KENDARAAN')->exists()) {
            return;
        }

        $this->as($this->manager, fn () => app(AssetSetupService::class)->applyDefaults("{$this->year}-01-01"));

        $categories = app(AssetCategoryService::class);
        $category = fn (array $data) => $this->as($this->manager, fn () => $categories->create($data, $this->manager));
        $vehicles = $category(['code' => 'KENDARAAN', 'name' => 'Kendaraan', 'default_method' => 'STRAIGHT_LINE', 'default_useful_life_months' => 96, 'default_residual_type' => 'PERCENT',
            'default_residual_value' => '10', 'asset_account_id' => $this->accounts['1220']]);
        $equipment = $category(['code' => 'PERALATAN', 'name' => 'Peralatan dan Mesin', 'default_method' => 'STRAIGHT_LINE', 'default_useful_life_months' => 60]);
        $computers = $category(['code' => 'KOMPUTER', 'name' => 'Komputer dan Elektronik', 'default_method' => 'DECLINING_BALANCE', 'default_useful_life_months' => 48,
            'default_residual_type' => 'PERCENT', 'default_residual_value' => '5']);

        $assets = app(FixedAssetService::class);
        $draft = fn (AssetCategory $c, array $data) => $this->as($this->accountant, fn () => $assets->create($data + ['asset_category_id' => $c->id], $this->accountant));
        $capitalize = fn (FixedAsset $asset) => $this->as($this->manager, fn () => $assets->capitalize($asset, $this->manager));
        $payable = $this->accounts['2140'];

        $truck = $draft($vehicles, ['name' => 'Truk Box Hino 300 B 9021 TXC', 'acquisition_date' => $this->monthStart(5), 'capitalization_date' => $this->monthStart(5), 'acquisition_cost' => '385000000',
            'source_account_id' => $payable, 'source_reference' => 'FAK-DLR-2210', 'branch_id' => $this->branch]);
        $laptops = $draft($computers, ['name' => 'Laptop tim keuangan (5 unit)', 'acquisition_date' => $this->monthStart(3), 'capitalization_date' => $this->monthStart(3), 'acquisition_cost' => '62500000',
            'source_account_id' => $this->accounts['1120'], 'source_reference' => 'INV-TKP-8841', 'branch_id' => $this->branch]);
        $car = $draft($vehicles, ['name' => 'Toyota Avanza B 1234 ABC (operasional lama)', 'acquisition_date' => $this->monthStart(6), 'capitalization_date' => $this->monthStart(6), 'acquisition_cost' => '210000000',
            'residual_value' => '20000000', 'useful_life_months' => 60, 'source_account_id' => $payable, 'source_reference' => 'FAK-DLR-1987', 'branch_id' => $this->branchSby]);
        $draft($equipment, ['name' => 'Forklift Gudang 3 Ton', 'acquisition_date' => $this->date(2), 'capitalization_date' => $this->date(2), 'acquisition_cost' => '148000000',
            'source_account_id' => $payable, 'source_reference' => 'PO-FRK-0032', 'branch_id' => $this->branchSby]);
        foreach ([$truck, $laptops, $car] as $asset) {
            $capitalize($asset);
        }

        // the past months, oldest first: the first run also catches up the months since capitalization
        $runs = app(DepreciationRunService::class);
        foreach ([2, 1] as $ago) {
            if ($this->monthOf($ago) >= $this->floor) {
                $this->depreciate($runs, $this->monthEnd($ago), self::MONTHS[(int) substr($this->monthEnd($ago), 5, 2) - 1]);
            }
        }

        // the old car is sold for slightly less than its book value, after its depreciation is posted up to the sale
        $sale = max($this->monthStart(6), min($this->monthEnd(1), $this->today));
        $this->posted(app(AssetDisposalService::class), [
            'fixed_asset_id' => $car->id, 'disposal_type' => 'SALE', 'disposal_date' => $sale, 'posting_date' => $sale, 'proceeds_amount' => '185000000', 'proceeds_account_id' => $this->accounts['1120'],
            'reason' => 'Dijual ke dealer karena peremajaan armada', 'reference' => 'BA-JUAL-0007',
        ]);

        // this month's depreciation: calculated by the accountant, waiting for the manager to post
        $end = Carbon::parse($this->today)->endOfMonth()->toDateString();
        if (DepreciationScheduleRow::query()->where('status', DepreciationScheduleRow::PLANNED)->where('period_end', '<=', $end)->exists()) {
            $this->as($this->accountant, fn () => $runs->create([
                'accounting_period_id' => $this->periodOf($end)->id, 'posting_date' => min($end, $this->today), 'description' => 'Penyusutan bulan berjalan (menunggu posting)',
            ], $this->accountant));
        }
    }

    private function depreciate(DepreciationRunService $runs, string $periodEnd, string $monthName): void
    {
        $run = $this->as($this->accountant, fn () => $runs->create([
            'accounting_period_id' => $this->periodOf($periodEnd)->id, 'posting_date' => $periodEnd, 'description' => "Penyusutan aset tetap {$monthName} {$this->year}",
        ], $this->accountant));
        $this->as($this->manager, fn () => $runs->post($run, $this->manager));
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** Prepared by the accountant, submitted, approved and posted by the manager (the posting documents of OA2 and OA3 and the disposal). */
    private function posted(object $service, array $data): mixed
    {
        $document = $this->as($this->accountant, fn () => $service->create($data, $this->accountant));
        $document = $this->as($this->accountant, fn () => $service->submit($document, $this->accountant));
        $document = $this->as($this->manager, fn () => $service->approve($document, $this->manager));

        return $this->as($this->manager, fn () => $service->post($document, $this->manager));
    }

    /** The date $daysAgo before today, never before the first open period and never after today. */
    private function date(int $daysAgo): string
    {
        return max($this->floor, date('Y-m-d', strtotime($this->today." -{$daysAgo} days")));
    }

    /** First day of the month $monthsAgo before the current one, unclamped. */
    private function monthOf(int $monthsAgo): string
    {
        return Carbon::parse($this->today)->startOfMonth()->subMonthsNoOverflow($monthsAgo)->toDateString();
    }

    /** First day of the month $monthsAgo before the current one, never before the first open period. */
    private function monthStart(int $monthsAgo): string
    {
        return max($this->floor, $this->monthOf($monthsAgo));
    }

    private function monthEnd(int $monthsAgo): string
    {
        return Carbon::parse($this->monthOf($monthsAgo))->endOfMonth()->toDateString();
    }

    private function periodOf(string $date): AccountingPeriod
    {
        return AccountingPeriod::query()->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();
    }

    private function as(User $user, callable $work): mixed
    {
        $previous = $this->context->user();
        $this->context->setUser($user);
        try {
            return $work();
        } finally {
            $this->context->setUser($previous);
        }
    }
}
