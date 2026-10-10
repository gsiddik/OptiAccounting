<?php

use App\Http\Controllers\Api\App;
use App\Http\Controllers\Api\App\Accounting;
use App\Http\Controllers\Api\App\CashBank;
use App\Http\Controllers\Api\App\Expense;
use App\Http\Controllers\Api\App\Operational;
use App\Http\Controllers\Api\App\Payables;
use App\Http\Controllers\Api\App\Receivables;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\SsoController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Integration;
use App\Http\Controllers\Api\Platform;
use Illuminate\Support\Facades\Route;

/*
| Versioned API (docs/architecture/SYSTEM_ARCHITECTURE.md §7):
|   /api/v1/auth/*         login and token context
|   /api/v1/platform/*     platform / superadmin
|   /api/v1/app/*          tenant application
|   /api/v1/integration/*  machine-to-machine only (OA6)
|
| Every protected route declares `access:<permission>[,module=..][,feature=..]`;
| the EffectiveAccess resolver is the only authority (no role-name checks).
*/
Route::prefix('v1')->middleware('request.id')->group(function () {
    Route::get('health', HealthController::class)->name('api.v1.health');

    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

        // OptiNexus single sign-on (identity mode `optinexus`; refused otherwise).
        Route::prefix('sso')->middleware('throttle:sso')->group(function () {
            Route::get('status', [SsoController::class, 'status']);
            Route::get('redirect', [SsoController::class, 'redirect']);
            Route::get('callback', [SsoController::class, 'callback']);
            Route::post('exchange', [SsoController::class, 'exchange']);
        });

        Route::middleware(['auth:sanctum', 'context:session'])->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('switch-tenant', [AuthController::class, 'switchTenant']);
            Route::post('switch-platform', [AuthController::class, 'switchPlatform']);
            Route::post('accept-invitation', [AuthController::class, 'acceptInvitation']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    // ------------------------------------------------------------- integration (machine to machine)
    Route::prefix('integration/optinexus')->middleware('throttle:backchannel')->group(function () {
        Route::post('backchannel-logout', [Integration\OptinexusController::class, 'backchannelLogout']);
    });

    // ---------------------------------------------------------------- platform
    Route::prefix('platform')->middleware(['auth:sanctum', 'context:platform'])->group(function () {
        Route::get('capabilities', Platform\CapabilityController::class);

        Route::get('tenants', [Platform\TenantController::class, 'index'])->middleware('access:platform.tenant.view');
        Route::post('tenants', [Platform\TenantController::class, 'store'])->middleware(['local.identity', 'access:platform.tenant.create']);
        Route::get('tenants/{tenant}', [Platform\TenantController::class, 'show'])->middleware('access:platform.tenant.view');
        Route::patch('tenants/{tenant}', [Platform\TenantController::class, 'update'])->middleware('access:platform.tenant.update');
        Route::post('tenants/{tenant}/status', [Platform\TenantController::class, 'transition'])->middleware('access:platform.tenant.status.manage');

        Route::get('tenants/{tenant}/members', [Platform\TenantMemberController::class, 'index'])->middleware('access:platform.membership.view');
        Route::post('tenants/{tenant}/members/{membership}/status', [Platform\TenantMemberController::class, 'status'])->middleware('access:platform.membership.manage');

        Route::get('modules', [Platform\ModuleController::class, 'index'])->middleware('access:platform.module.view');
        Route::post('modules', [Platform\ModuleController::class, 'store'])->middleware('access:platform.module.manage');
        Route::patch('modules/{module}', [Platform\ModuleController::class, 'update'])->middleware('access:platform.module.manage');
        Route::post('modules/{module}/status', [Platform\ModuleController::class, 'status'])->middleware('access:platform.module.manage');
        Route::post('modules/{module}/dependencies', [Platform\ModuleController::class, 'addDependency'])->middleware('access:platform.module.manage');
        Route::delete('modules/{module}/dependencies/{required}', [Platform\ModuleController::class, 'removeDependency'])->middleware('access:platform.module.manage');
        Route::post('modules/{module}/features', [Platform\ModuleController::class, 'storeFeature'])->middleware('access:platform.module.manage');
        Route::patch('features/{feature}', [Platform\ModuleController::class, 'updateFeature'])->middleware('access:platform.module.manage');

        Route::get('bundles', [Platform\BundleController::class, 'index'])->middleware('access:platform.bundle.view');
        Route::post('bundles', [Platform\BundleController::class, 'store'])->middleware('access:platform.bundle.manage');
        Route::patch('bundles/{bundle}', [Platform\BundleController::class, 'update'])->middleware('access:platform.bundle.manage');

        Route::get('tenants/{tenant}/subscriptions', [Platform\SubscriptionController::class, 'index'])->middleware('access:platform.subscription.view');
        Route::post('tenants/{tenant}/subscriptions', [Platform\SubscriptionController::class, 'store'])->middleware(['local.identity', 'access:platform.subscription.manage']);
        Route::post('tenants/{tenant}/subscriptions/{subscription}/status', [Platform\SubscriptionController::class, 'transition'])->middleware(['local.identity', 'access:platform.subscription.manage']);
        Route::patch('tenants/{tenant}/subscriptions/{subscription}', [Platform\SubscriptionController::class, 'reschedule'])->middleware(['local.identity', 'access:platform.subscription.manage']);

        Route::get('tenants/{tenant}/entitlements', [Platform\EntitlementController::class, 'show'])->middleware('access:platform.entitlement.view');
        Route::post('tenants/{tenant}/entitlements/modules', [Platform\EntitlementController::class, 'grantModule'])->middleware(['local.identity', 'access:platform.entitlement.manage']);
        Route::patch('tenants/{tenant}/entitlements/modules/{entitlement}', [Platform\EntitlementController::class, 'updateModule'])->middleware(['local.identity', 'access:platform.entitlement.manage']);
        Route::post('tenants/{tenant}/entitlements/features', [Platform\EntitlementController::class, 'grantFeature'])->middleware(['local.identity', 'access:platform.entitlement.manage']);
        Route::patch('tenants/{tenant}/entitlements/features/{entitlement}', [Platform\EntitlementController::class, 'updateFeature'])->middleware(['local.identity', 'access:platform.entitlement.manage']);
        Route::put('tenants/{tenant}/capacity/{limitCode}', [Platform\EntitlementController::class, 'setCapacity'])->middleware(['local.identity', 'access:platform.entitlement.manage']);

        Route::get('users', [Platform\PlatformAccessController::class, 'users'])->middleware('access:platform.user.view');
        Route::post('users', [Platform\PlatformAccessController::class, 'storeUser'])->middleware('access:platform.user.manage');
        Route::put('users/{user}/roles', [Platform\PlatformAccessController::class, 'userRoles'])->middleware('access:platform.user.manage');
        Route::post('users/{user}/status', [Platform\PlatformAccessController::class, 'userStatus'])->middleware('access:platform.user.manage');
        Route::get('roles', [Platform\PlatformAccessController::class, 'roles'])->middleware('access:platform.role.view');
        Route::post('roles', [Platform\PlatformAccessController::class, 'storeRole'])->middleware('access:platform.role.manage');
        Route::patch('roles/{role}', [Platform\PlatformAccessController::class, 'updateRole'])->middleware('access:platform.role.manage');
        Route::delete('roles/{role}', [Platform\PlatformAccessController::class, 'destroyRole'])->middleware('access:platform.role.manage');
        Route::get('permissions', [Platform\PlatformAccessController::class, 'permissions'])->middleware('access:platform.permission.view');

        Route::get('audit-logs', [Platform\PlatformAuditController::class, 'index'])->middleware('access:platform.audit.view');
    });

    // ------------------------------------------------------------------ tenant
    Route::prefix('app')->middleware(['auth:sanctum', 'context:tenant'])->group(function () {
        Route::get('capabilities', App\CapabilityController::class);

        Route::get('branches', [App\OrganizationController::class, 'branches'])->middleware('access:organization.view');
        Route::post('branches', [App\OrganizationController::class, 'storeBranch'])->middleware('access:organization.manage');
        Route::patch('branches/{branch}', [App\OrganizationController::class, 'updateBranch'])->middleware('access:organization.manage');
        Route::post('branches/{branch}/status', [App\OrganizationController::class, 'branchStatus'])->middleware('access:organization.manage');
        Route::get('business-units', [App\OrganizationController::class, 'businessUnits'])->middleware('access:organization.view');
        Route::post('business-units', [App\OrganizationController::class, 'storeBusinessUnit'])->middleware('access:organization.manage');
        Route::patch('business-units/{businessUnit}', [App\OrganizationController::class, 'updateBusinessUnit'])->middleware('access:organization.manage');
        Route::post('business-units/{businessUnit}/status', [App\OrganizationController::class, 'businessUnitStatus'])->middleware('access:organization.manage');

        Route::get('users', [App\MemberController::class, 'index'])->middleware('access:access.user.view');
        Route::post('users', [App\MemberController::class, 'store'])->middleware(['local.identity', 'access:access.user.manage']);
        Route::post('users/{member}/status', [App\MemberController::class, 'status'])->middleware(['local.identity', 'access:access.user.manage']);
        Route::put('users/{member}/roles', [App\MemberController::class, 'roles'])->middleware(['local.identity', 'access:access.user.manage']);
        Route::put('users/{member}/data-scopes', [App\MemberController::class, 'scopes'])->middleware('access:access.scope.manage');

        Route::get('roles', [App\RoleController::class, 'index'])->middleware('access:access.role.view');
        Route::post('roles', [App\RoleController::class, 'store'])->middleware(['local.identity', 'access:access.role.manage']);
        Route::patch('roles/{role}', [App\RoleController::class, 'update'])->middleware(['local.identity', 'access:access.role.manage']);
        Route::delete('roles/{role}', [App\RoleController::class, 'destroy'])->middleware(['local.identity', 'access:access.role.manage']);
        Route::get('permissions', [App\RoleController::class, 'permissions'])->middleware('access:access.permission.view');

        Route::get('account/subscription', [App\AccountController::class, 'subscription'])->middleware('access:account.subscription.view');
        Route::get('account/modules', [App\AccountController::class, 'modules'])->middleware('access:account.subscription.view');
        Route::get('account/features', [App\AccountController::class, 'features'])->middleware('access:account.subscription.view');
        Route::get('account/usage', [App\AccountController::class, 'usage'])->middleware('access:account.subscription.view');

        Route::get('audit-logs', [App\AccountController::class, 'audit'])->middleware('access:audit.view');

        // ---------------------------------------------------- Accounting Core (OA1). Module and feature entitlement is enforced by the gate.
        Route::prefix('accounting')->group(function () {
            $gate = fn (string $permission, string $feature) => "access:{$permission},module=ACCOUNTING_CORE,feature={$feature}";
            $config = 'ACCOUNTING_CONFIGURATION';

            Route::get('profile', [Accounting\SetupController::class, 'profile'])->middleware($gate('accounting.profile.view', $config));
            Route::put('profile', [Accounting\SetupController::class, 'saveProfile'])->middleware($gate('accounting.profile.manage', $config));
            Route::post('profile/activate', [Accounting\SetupController::class, 'activate'])->middleware($gate('accounting.profile.manage', $config));
            Route::get('readiness', [Accounting\SetupController::class, 'readiness'])->middleware($gate('accounting.profile.view', $config));

            Route::get('fiscal-years', [Accounting\FiscalCalendarController::class, 'index'])->middleware($gate('accounting.period.view', $config));
            Route::post('fiscal-years', [Accounting\FiscalCalendarController::class, 'store'])->middleware($gate('accounting.period.manage', $config));
            Route::post('fiscal-years/{fiscalYear}/open', [Accounting\FiscalCalendarController::class, 'open'])->middleware($gate('accounting.period.manage', $config));
            Route::post('fiscal-years/{fiscalYear}/close', [Accounting\FiscalCalendarController::class, 'close'])->middleware($gate('accounting.period.close', $config));
            Route::delete('fiscal-years/{fiscalYear}', [Accounting\FiscalCalendarController::class, 'destroy'])->middleware($gate('accounting.period.manage', $config));
            Route::post('periods/{period}/open', [Accounting\FiscalCalendarController::class, 'openPeriod'])->middleware($gate('accounting.period.manage', $config));
            Route::post('periods/{period}/soft-close', [Accounting\FiscalCalendarController::class, 'softClosePeriod'])->middleware($gate('accounting.period.manage', $config));
            Route::post('periods/{period}/close', [Accounting\FiscalCalendarController::class, 'closePeriod'])->middleware($gate('accounting.period.close', $config));

            Route::get('accounts', [Accounting\ChartOfAccountsController::class, 'index'])->middleware($gate('accounting.coa.view', $config));
            Route::post('accounts', [Accounting\ChartOfAccountsController::class, 'store'])->middleware($gate('accounting.coa.manage', $config));
            Route::patch('accounts/{account}', [Accounting\ChartOfAccountsController::class, 'update'])->middleware($gate('accounting.coa.manage', $config));
            Route::post('accounts/{account}/status', [Accounting\ChartOfAccountsController::class, 'status'])->middleware($gate('accounting.coa.manage', $config));
            Route::delete('accounts/{account}', [Accounting\ChartOfAccountsController::class, 'destroy'])->middleware($gate('accounting.coa.manage', $config));
            Route::get('coa-templates', [Accounting\ChartOfAccountsController::class, 'templates'])->middleware($gate('accounting.coa.view', $config));
            Route::post('coa-templates/apply', [Accounting\ChartOfAccountsController::class, 'applyTemplate'])->middleware($gate('accounting.coa.manage', $config));

            $journal = 'JOURNAL';
            Route::get('dashboard', [Accounting\DashboardController::class, 'show'])->middleware($gate('accounting.journal.view', $journal));
            Route::get('journals', [Accounting\JournalController::class, 'index'])->middleware($gate('accounting.journal.view', $journal));
            Route::post('journals', [Accounting\JournalController::class, 'store'])->middleware($gate('accounting.journal.create', $journal));
            Route::get('journals/{journal}', [Accounting\JournalController::class, 'show'])->middleware($gate('accounting.journal.view', $journal));
            Route::patch('journals/{journal}', [Accounting\JournalController::class, 'update'])->middleware($gate('accounting.journal.update', $journal));
            Route::post('journals/{journal}/submit', [Accounting\JournalController::class, 'submit'])->middleware($gate('accounting.journal.submit', $journal));
            Route::post('journals/{journal}/approve', [Accounting\JournalController::class, 'approve'])->middleware($gate('accounting.journal.approve', $journal));
            Route::post('journals/{journal}/reject', [Accounting\JournalController::class, 'reject'])->middleware($gate('accounting.journal.approve', $journal));
            Route::post('journals/{journal}/reopen', [Accounting\JournalController::class, 'reopen'])->middleware($gate('accounting.journal.update', $journal));
            Route::post('journals/{journal}/cancel', [Accounting\JournalController::class, 'cancel'])->middleware($gate('accounting.journal.update', $journal));
            Route::post('journals/{journal}/post', [Accounting\JournalController::class, 'post'])->middleware($gate('accounting.journal.post', $journal));
            Route::post('journals/{journal}/reverse', [Accounting\JournalController::class, 'reverse'])->middleware($gate('accounting.journal.reverse', $journal));

            Route::get('event-types', [Accounting\PostingConfigurationController::class, 'eventTypes'])->middleware($gate('accounting.posting_rule.view', $config));
            Route::get('posting-rules', [Accounting\PostingConfigurationController::class, 'rules'])->middleware($gate('accounting.posting_rule.view', $config));
            Route::post('posting-rules', [Accounting\PostingConfigurationController::class, 'storeRule'])->middleware($gate('accounting.posting_rule.manage', $config));
            Route::get('posting-rules/{rule}', [Accounting\PostingConfigurationController::class, 'showRule'])->middleware($gate('accounting.posting_rule.view', $config));
            Route::patch('posting-rules/{rule}', [Accounting\PostingConfigurationController::class, 'updateRule'])->middleware($gate('accounting.posting_rule.manage', $config));
            Route::delete('posting-rules/{rule}', [Accounting\PostingConfigurationController::class, 'destroyRule'])->middleware($gate('accounting.posting_rule.manage', $config));
            Route::post('posting-rules/{rule}/new-version', [Accounting\PostingConfigurationController::class, 'newVersion'])->middleware($gate('accounting.posting_rule.manage', $config));
            Route::post('posting-rules/{rule}/publish', [Accounting\PostingConfigurationController::class, 'publishRule'])->middleware($gate('accounting.posting_rule.manage', $config));
            Route::post('posting-rules/{rule}/archive', [Accounting\PostingConfigurationController::class, 'archiveRule'])->middleware($gate('accounting.posting_rule.manage', $config));
            Route::post('posting-rules/{rule}/simulate', [Accounting\PostingConfigurationController::class, 'simulate'])->middleware($gate('accounting.posting_rule.view', $config));
            Route::get('account-mappings', [Accounting\PostingConfigurationController::class, 'mappings'])->middleware($gate('accounting.account_mapping.view', $config));
            Route::put('account-mappings', [Accounting\PostingConfigurationController::class, 'saveMapping'])->middleware($gate('accounting.account_mapping.manage', $config));
            Route::post('account-mappings/{mapping}/deactivate', [Accounting\PostingConfigurationController::class, 'deactivateMapping'])->middleware($gate('accounting.account_mapping.manage', $config));
            Route::get('accounting-events', [Accounting\PostingConfigurationController::class, 'events'])->middleware($gate('accounting.posting_rule.view', $config));
            Route::get('operational-rules', [Accounting\OperationalSetupController::class, 'status'])->middleware($gate('accounting.posting_rule.view', $config));
            Route::post('operational-rules/defaults', [Accounting\OperationalSetupController::class, 'apply'])->middleware($gate('accounting.posting_rule.manage', $config));

            $opening = 'OPENING_BALANCE';
            Route::get('opening-balance', [Accounting\OpeningBalanceController::class, 'show'])->middleware($gate('accounting.opening_balance.view', $opening));
            Route::put('opening-balance', [Accounting\OpeningBalanceController::class, 'save'])->middleware($gate('accounting.opening_balance.manage', $opening));
            Route::post('opening-balance/cancel', [Accounting\OpeningBalanceController::class, 'cancel'])->middleware($gate('accounting.opening_balance.manage', $opening));
            Route::post('opening-balance/post', [Accounting\OpeningBalanceController::class, 'post'])->middleware($gate('accounting.opening_balance.post', $opening));

            $ledger = 'GENERAL_LEDGER';
            Route::get('general-ledger', [Accounting\LedgerReportController::class, 'generalLedger'])->middleware($gate('accounting.gl.view', $ledger));
            Route::get('general-ledger/export', [Accounting\LedgerReportController::class, 'exportGeneralLedger'])->middleware($gate('accounting.report.export', $ledger));
            Route::get('trial-balance', [Accounting\LedgerReportController::class, 'trialBalance'])->middleware($gate('accounting.trial_balance.view', $ledger));
            Route::get('trial-balance/export', [Accounting\LedgerReportController::class, 'exportTrialBalance'])->middleware($gate('accounting.report.export', $ledger));
            Route::get('accounts-export', [Accounting\LedgerReportController::class, 'exportAccounts'])->middleware($gate('accounting.report.export', $config));

            Route::get('dimension-types', [Accounting\DimensionController::class, 'types'])->middleware($gate('accounting.dimension.view', $config));
            Route::get('dimensions', [Accounting\DimensionController::class, 'catalog'])->middleware($gate('accounting.dimension.view', $config));
            Route::get('cost-centers', [Accounting\DimensionController::class, 'costCenters'])->middleware($gate('accounting.dimension.view', $config));
            Route::post('cost-centers', [Accounting\DimensionController::class, 'storeCostCenter'])->middleware($gate('accounting.dimension.manage', $config));
            Route::patch('cost-centers/{costCenter}', [Accounting\DimensionController::class, 'updateCostCenter'])->middleware($gate('accounting.dimension.manage', $config));
            Route::post('cost-centers/{costCenter}/status', [Accounting\DimensionController::class, 'costCenterStatus'])->middleware($gate('accounting.dimension.manage', $config));

            // ------------------------------------------------ OA2: payables, expense, cash & bank. Each module has its own entitlement; all depend on ACCOUNTING_CORE.
            $ap = fn (string $permission, string $feature) => "access:{$permission},module=ACCOUNTING_AP,feature={$feature}";

            Route::get('vendors', [Payables\VendorController::class, 'index'])->middleware($ap('accounting.vendor.view', 'VENDOR'));
            Route::post('vendors', [Payables\VendorController::class, 'store'])->middleware($ap('accounting.vendor.manage', 'VENDOR'));
            Route::get('vendors/export', [Operational\OperationalExportController::class, 'vendors'])->middleware($ap('accounting.report.export', 'VENDOR'));
            Route::get('vendors/{vendor}', [Payables\VendorController::class, 'show'])->middleware($ap('accounting.vendor.view', 'VENDOR'));
            Route::patch('vendors/{vendor}', [Payables\VendorController::class, 'update'])->middleware($ap('accounting.vendor.manage', 'VENDOR'));
            Route::post('vendors/{vendor}/status', [Payables\VendorController::class, 'status'])->middleware($ap('accounting.vendor.manage', 'VENDOR'));
            Route::delete('vendors/{vendor}', [Payables\VendorController::class, 'destroy'])->middleware($ap('accounting.vendor.manage', 'VENDOR'));
            Route::get('payment-terms', [Payables\VendorController::class, 'terms'])->middleware($ap('accounting.vendor.view', 'VENDOR'));
            Route::post('payment-terms', [Payables\VendorController::class, 'storeTerm'])->middleware($ap('accounting.vendor.manage', 'VENDOR'));
            Route::post('payment-terms/defaults', [Payables\VendorController::class, 'applyTermDefaults'])->middleware($ap('accounting.vendor.manage', 'VENDOR'));
            Route::patch('payment-terms/{term}', [Payables\VendorController::class, 'updateTerm'])->middleware($ap('accounting.vendor.manage', 'VENDOR'));
            Route::post('payment-terms/{term}/status', [Payables\VendorController::class, 'termStatus'])->middleware($ap('accounting.vendor.manage', 'VENDOR'));
            Route::delete('payment-terms/{term}', [Payables\VendorController::class, 'destroyTerm'])->middleware($ap('accounting.vendor.manage', 'VENDOR'));

            $inv = 'VENDOR_INVOICE';
            Route::get('ap-invoices', [Payables\ApInvoiceController::class, 'index'])->middleware($ap('accounting.ap_invoice.view', $inv));
            Route::post('ap-invoices', [Payables\ApInvoiceController::class, 'store'])->middleware($ap('accounting.ap_invoice.create', $inv));
            Route::get('ap-invoices/check-duplicate', [Payables\ApInvoiceController::class, 'checkDuplicate'])->middleware($ap('accounting.ap_invoice.view', $inv));
            Route::get('ap-invoices/export', [Operational\OperationalExportController::class, 'invoices'])->middleware($ap('accounting.report.export', $inv));
            Route::get('ap-invoices/{invoice}', [Payables\ApInvoiceController::class, 'show'])->middleware($ap('accounting.ap_invoice.view', $inv));
            Route::patch('ap-invoices/{invoice}', [Payables\ApInvoiceController::class, 'update'])->middleware($ap('accounting.ap_invoice.update', $inv));
            Route::post('ap-invoices/{invoice}/submit', [Payables\ApInvoiceController::class, 'submit'])->middleware($ap('accounting.ap_invoice.submit', $inv));
            Route::post('ap-invoices/{invoice}/approve', [Payables\ApInvoiceController::class, 'approve'])->middleware($ap('accounting.ap_invoice.approve', $inv));
            Route::post('ap-invoices/{invoice}/reject', [Payables\ApInvoiceController::class, 'reject'])->middleware($ap('accounting.ap_invoice.approve', $inv));
            Route::post('ap-invoices/{invoice}/reopen', [Payables\ApInvoiceController::class, 'reopen'])->middleware($ap('accounting.ap_invoice.update', $inv));
            Route::post('ap-invoices/{invoice}/cancel', [Payables\ApInvoiceController::class, 'cancel'])->middleware($ap('accounting.ap_invoice.update', $inv));
            Route::post('ap-invoices/{invoice}/post', [Payables\ApInvoiceController::class, 'post'])->middleware($ap('accounting.ap_invoice.post', $inv));
            Route::post('ap-invoices/{invoice}/reverse', [Payables\ApInvoiceController::class, 'reverse'])->middleware($ap('accounting.ap_invoice.reverse', $inv));

            // AP aging and AP-to-GL reconciliation (feature AP_AGING).
            Route::get('ap-aging', [Payables\ApReportController::class, 'aging'])->middleware($ap('accounting.ap_aging.view', 'AP_AGING'));
            Route::get('ap-aging/export', [Payables\ApReportController::class, 'exportAging'])->middleware($ap('accounting.report.export', 'AP_AGING'));
            Route::get('reconciliation/ap', [Payables\ApReportController::class, 'reconciliation'])->middleware($ap('accounting.reconciliation.ap.view', 'AP_AGING'));
            Route::get('reconciliation/ap/export', [Operational\OperationalExportController::class, 'apReconciliation'])->middleware($ap('accounting.report.export', 'AP_AGING'));

            // Vendor payments and allocations (ACCOUNTING_AP, feature AP_PAYMENT).
            $pay = 'AP_PAYMENT';
            Route::get('vendor-payments', [Payables\VendorPaymentController::class, 'index'])->middleware($ap('accounting.ap_payment.view', $pay));
            Route::post('vendor-payments', [Payables\VendorPaymentController::class, 'store'])->middleware($ap('accounting.ap_payment.create', $pay));
            Route::get('vendor-payments/export', [Operational\OperationalExportController::class, 'payments'])->middleware($ap('accounting.report.export', $pay));
            Route::get('vendor-payments/{payment}', [Payables\VendorPaymentController::class, 'show'])->middleware($ap('accounting.ap_payment.view', $pay));
            Route::patch('vendor-payments/{payment}', [Payables\VendorPaymentController::class, 'update'])->middleware($ap('accounting.ap_payment.create', $pay));
            Route::post('vendor-payments/{payment}/submit', [Payables\VendorPaymentController::class, 'submit'])->middleware($ap('accounting.ap_payment.submit', $pay));
            Route::post('vendor-payments/{payment}/approve', [Payables\VendorPaymentController::class, 'approve'])->middleware($ap('accounting.ap_payment.approve', $pay));
            Route::post('vendor-payments/{payment}/reject', [Payables\VendorPaymentController::class, 'reject'])->middleware($ap('accounting.ap_payment.approve', $pay));
            Route::post('vendor-payments/{payment}/reopen', [Payables\VendorPaymentController::class, 'reopen'])->middleware($ap('accounting.ap_payment.create', $pay));
            Route::post('vendor-payments/{payment}/cancel', [Payables\VendorPaymentController::class, 'cancel'])->middleware($ap('accounting.ap_payment.create', $pay));
            Route::post('vendor-payments/{payment}/post', [Payables\VendorPaymentController::class, 'post'])->middleware($ap('accounting.ap_payment.post', $pay));
            Route::post('vendor-payments/{payment}/reverse', [Payables\VendorPaymentController::class, 'reverse'])->middleware($ap('accounting.ap_payment.reverse', $pay));
            Route::get('vendors/{vendor}/open-invoices', [Payables\VendorPaymentController::class, 'openInvoices'])->middleware($ap('accounting.ap_payment.view', $pay));
            Route::get('vendors/{vendor}/allocation-suggestion', [Payables\VendorPaymentController::class, 'suggest'])->middleware($ap('accounting.ap_payment.create', $pay));

            // OA2 counters for the accounting home: reachable like the home itself (journal view); each section is additionally gated inside the service by module, feature and permission.
            Route::get('operational-summary', [Operational\OperationalDashboardController::class, 'show'])->middleware($gate('accounting.journal.view', $journal));

            // Cash and bank accounts (ACCOUNTING_CASH_BANK).
            $cb = fn (string $permission, string $feature) => "access:{$permission},module=ACCOUNTING_CASH_BANK,feature={$feature}";
            Route::get('cash-bank-accounts', [CashBank\CashBankAccountController::class, 'index'])->middleware($cb('accounting.cash_bank.view', 'CASH_BANK_ACCOUNT'));
            Route::post('cash-bank-accounts', [CashBank\CashBankAccountController::class, 'store'])->middleware($cb('accounting.cash_bank.manage', 'CASH_BANK_ACCOUNT'));
            Route::get('cash-bank-accounts/{cashBankAccount}', [CashBank\CashBankAccountController::class, 'show'])->middleware($cb('accounting.cash_bank.view', 'CASH_BANK_ACCOUNT'));
            Route::patch('cash-bank-accounts/{cashBankAccount}', [CashBank\CashBankAccountController::class, 'update'])->middleware($cb('accounting.cash_bank.manage', 'CASH_BANK_ACCOUNT'));
            Route::post('cash-bank-accounts/{cashBankAccount}/status', [CashBank\CashBankAccountController::class, 'status'])->middleware($cb('accounting.cash_bank.manage', 'CASH_BANK_ACCOUNT'));
            Route::delete('cash-bank-accounts/{cashBankAccount}', [CashBank\CashBankAccountController::class, 'destroy'])->middleware($cb('accounting.cash_bank.manage', 'CASH_BANK_ACCOUNT'));

            // Controlled cash and bank payments and receipts (features PAYMENT and RECEIPT). The kind comes from the route, never from the body.
            foreach (['PAYMENT' => ['cash-payments', 'PAYMENT'], 'RECEIPT' => ['cash-receipts', 'RECEIPT']] as $kind => [$path, $feature]) {
                $ct = fn (string $permission) => "access:{$permission},module=ACCOUNTING_CASH_BANK,feature={$feature}";
                Route::get($path, [CashBank\CashTransactionController::class, 'index'])->defaults('kind', $kind)->middleware($ct('accounting.cash_transaction.view'));
                Route::post($path, [CashBank\CashTransactionController::class, 'store'])->defaults('kind', $kind)->middleware($ct('accounting.cash_transaction.create'));
                Route::get("{$path}/export", [Operational\OperationalExportController::class, 'cashTransactions'])->defaults('kind', $kind)->middleware($ct('accounting.report.export'));
                Route::get("{$path}/{cashTransaction}", [CashBank\CashTransactionController::class, 'show'])->defaults('kind', $kind)->middleware($ct('accounting.cash_transaction.view'));
                Route::patch("{$path}/{cashTransaction}", [CashBank\CashTransactionController::class, 'update'])->defaults('kind', $kind)->middleware($ct('accounting.cash_transaction.create'));
                Route::post("{$path}/{cashTransaction}/cancel", [CashBank\CashTransactionController::class, 'cancel'])->defaults('kind', $kind)->middleware($ct('accounting.cash_transaction.create'));
                Route::post("{$path}/{cashTransaction}/post", [CashBank\CashTransactionController::class, 'post'])->defaults('kind', $kind)->middleware($ct('accounting.cash_transaction.post'));
                Route::post("{$path}/{cashTransaction}/reverse", [CashBank\CashTransactionController::class, 'reverse'])->defaults('kind', $kind)->middleware($ct('accounting.cash_transaction.reverse'));
            }

            // Bank history, manual bank reconciliation and the cash/bank to general ledger reconciliation (feature BANK_RECONCILIATION). Nothing here writes to the ledger.
            $br = fn (string $permission) => "access:{$permission},module=ACCOUNTING_CASH_BANK,feature=BANK_RECONCILIATION";
            Route::get('cash-bank-accounts/{cashBankAccount}/transactions', [CashBank\BankReconciliationController::class, 'transactions'])->middleware($cb('accounting.cash_bank.view', 'CASH_BANK_ACCOUNT'));
            Route::get('cash-bank-accounts/{cashBankAccount}/transactions/export', [Operational\OperationalExportController::class, 'accountMovements'])->middleware($cb('accounting.report.export', 'CASH_BANK_ACCOUNT'));
            Route::get('reconciliation/cash-bank', [CashBank\BankReconciliationController::class, 'report'])->middleware($br('accounting.reconciliation.cash_bank.view'));
            Route::get('reconciliation/cash-bank/export', [Operational\OperationalExportController::class, 'cashBankReconciliation'])->middleware($br('accounting.report.export'));
            Route::get('bank-statements/{statement}/export', [Operational\OperationalExportController::class, 'statement'])->middleware($br('accounting.report.export'));
            Route::get('bank-statements', [CashBank\BankReconciliationController::class, 'index'])->middleware($br('accounting.bank_reconciliation.view'));
            Route::post('bank-statements', [CashBank\BankReconciliationController::class, 'store'])->middleware($br('accounting.bank_reconciliation.manage'));
            Route::get('bank-statements/{statement}', [CashBank\BankReconciliationController::class, 'show'])->middleware($br('accounting.bank_reconciliation.view'));
            Route::patch('bank-statements/{statement}', [CashBank\BankReconciliationController::class, 'update'])->middleware($br('accounting.bank_reconciliation.manage'));
            Route::delete('bank-statements/{statement}', [CashBank\BankReconciliationController::class, 'destroy'])->middleware($br('accounting.bank_reconciliation.manage'));
            Route::post('bank-statements/{statement}/complete', [CashBank\BankReconciliationController::class, 'complete'])->middleware($br('accounting.bank_reconciliation.manage'));
            Route::post('bank-statements/{statement}/items', [CashBank\BankReconciliationController::class, 'addItems'])->middleware($br('accounting.bank_reconciliation.manage'));
            Route::patch('bank-statements/{statement}/items/{item}', [CashBank\BankReconciliationController::class, 'updateItem'])->middleware($br('accounting.bank_reconciliation.manage'));
            Route::delete('bank-statements/{statement}/items/{item}', [CashBank\BankReconciliationController::class, 'destroyItem'])->middleware($br('accounting.bank_reconciliation.manage'));
            Route::get('bank-statements/{statement}/items/{item}/candidates', [CashBank\BankReconciliationController::class, 'candidates'])->middleware($br('accounting.bank_reconciliation.view'));
            Route::post('bank-statements/{statement}/items/{item}/match', [CashBank\BankReconciliationController::class, 'match'])->middleware($br('accounting.bank_reconciliation.manage'));
            Route::post('bank-statements/{statement}/items/{item}/unmatch', [CashBank\BankReconciliationController::class, 'unmatch'])->middleware($br('accounting.bank_reconciliation.manage'));
            Route::post('bank-statements/{statement}/items/{item}/exception', [CashBank\BankReconciliationController::class, 'exception'])->middleware($br('accounting.bank_reconciliation.manage'));

            // Expense categories and expenses (ACCOUNTING_EXPENSE). A payable expense also needs ACCOUNTING_AP and a directly paid one ACCOUNTING_CASH_BANK, checked at submit/approve/post.
            $ex = fn (string $permission) => "access:{$permission},module=ACCOUNTING_EXPENSE,feature=EXPENSE";
            Route::get('expense-categories', [Expense\ExpenseCategoryController::class, 'index'])->middleware($ex('accounting.expense.view'));
            Route::post('expense-categories', [Expense\ExpenseCategoryController::class, 'store'])->middleware($ex('accounting.expense_category.manage'));
            Route::post('expense-categories/defaults', [Expense\ExpenseCategoryController::class, 'applyDefaults'])->middleware($ex('accounting.expense_category.manage'));
            Route::patch('expense-categories/{category}', [Expense\ExpenseCategoryController::class, 'update'])->middleware($ex('accounting.expense_category.manage'));
            Route::post('expense-categories/{category}/status', [Expense\ExpenseCategoryController::class, 'status'])->middleware($ex('accounting.expense_category.manage'));
            Route::delete('expense-categories/{category}', [Expense\ExpenseCategoryController::class, 'destroy'])->middleware($ex('accounting.expense_category.manage'));
            Route::get('expenses', [Expense\ExpenseController::class, 'index'])->middleware($ex('accounting.expense.view'));
            Route::post('expenses', [Expense\ExpenseController::class, 'store'])->middleware($ex('accounting.expense.create'));
            Route::get('expenses/export', [Operational\OperationalExportController::class, 'expenses'])->middleware($ex('accounting.report.export'));
            Route::get('expenses/{expense}', [Expense\ExpenseController::class, 'show'])->middleware($ex('accounting.expense.view'));
            Route::patch('expenses/{expense}', [Expense\ExpenseController::class, 'update'])->middleware($ex('accounting.expense.update'));
            Route::post('expenses/{expense}/submit', [Expense\ExpenseController::class, 'submit'])->middleware($ex('accounting.expense.submit'));
            Route::post('expenses/{expense}/approve', [Expense\ExpenseController::class, 'approve'])->middleware($ex('accounting.expense.approve'));
            Route::post('expenses/{expense}/reject', [Expense\ExpenseController::class, 'reject'])->middleware($ex('accounting.expense.approve'));
            Route::post('expenses/{expense}/reopen', [Expense\ExpenseController::class, 'reopen'])->middleware($ex('accounting.expense.update'));
            Route::post('expenses/{expense}/cancel', [Expense\ExpenseController::class, 'cancel'])->middleware($ex('accounting.expense.update'));
            Route::post('expenses/{expense}/post', [Expense\ExpenseController::class, 'post'])->middleware($ex('accounting.expense.post'));
            Route::post('expenses/{expense}/reverse', [Expense\ExpenseController::class, 'reverse'])->middleware($ex('accounting.expense.reverse'));

            // ------------------------------------------------ OA3: receivables and revenue (ACCOUNTING_AR). Depends on ACCOUNTING_CORE; a receipt also needs ACCOUNTING_CASH_BANK writable, checked at submit/approve/post.
            $ar = fn (string $permission, string $feature) => "access:{$permission},module=ACCOUNTING_AR,feature={$feature}";

            Route::get('customers', [Receivables\CustomerController::class, 'index'])->middleware($ar('accounting.customer.view', 'CUSTOMER'));
            Route::post('customers', [Receivables\CustomerController::class, 'store'])->middleware($ar('accounting.customer.manage', 'CUSTOMER'));
            Route::get('customers/export', [Receivables\ArExportController::class, 'customers'])->middleware($ar('accounting.report.export', 'CUSTOMER'));
            Route::get('customers/{customer}', [Receivables\CustomerController::class, 'show'])->middleware($ar('accounting.customer.view', 'CUSTOMER'));
            Route::patch('customers/{customer}', [Receivables\CustomerController::class, 'update'])->middleware($ar('accounting.customer.manage', 'CUSTOMER'));
            Route::post('customers/{customer}/status', [Receivables\CustomerController::class, 'status'])->middleware($ar('accounting.customer.manage', 'CUSTOMER'));
            Route::delete('customers/{customer}', [Receivables\CustomerController::class, 'destroy'])->middleware($ar('accounting.customer.manage', 'CUSTOMER'));
            // Payment terms are shared master data; an AR-only tenant manages them here with the customer permissions.
            Route::get('ar-payment-terms', [Receivables\CustomerController::class, 'terms'])->middleware($ar('accounting.customer.view', 'CUSTOMER'));
            Route::post('ar-payment-terms', [Receivables\CustomerController::class, 'storeTerm'])->middleware($ar('accounting.customer.manage', 'CUSTOMER'));
            Route::post('ar-payment-terms/defaults', [Receivables\CustomerController::class, 'applyTermDefaults'])->middleware($ar('accounting.customer.manage', 'CUSTOMER'));
            Route::patch('ar-payment-terms/{term}', [Receivables\CustomerController::class, 'updateTerm'])->middleware($ar('accounting.customer.manage', 'CUSTOMER'));
            Route::post('ar-payment-terms/{term}/status', [Receivables\CustomerController::class, 'termStatus'])->middleware($ar('accounting.customer.manage', 'CUSTOMER'));
            Route::delete('ar-payment-terms/{term}', [Receivables\CustomerController::class, 'destroyTerm'])->middleware($ar('accounting.customer.manage', 'CUSTOMER'));

            $ci = 'CUSTOMER_INVOICE';
            Route::get('ar-invoices', [Receivables\ArInvoiceController::class, 'index'])->middleware($ar('accounting.ar_invoice.view', $ci));
            Route::post('ar-invoices', [Receivables\ArInvoiceController::class, 'store'])->middleware($ar('accounting.ar_invoice.create', $ci));
            Route::get('ar-invoices/export', [Receivables\ArExportController::class, 'invoices'])->middleware($ar('accounting.report.export', $ci));
            Route::get('ar-invoices/{invoice}', [Receivables\ArInvoiceController::class, 'show'])->middleware($ar('accounting.ar_invoice.view', $ci));
            Route::patch('ar-invoices/{invoice}', [Receivables\ArInvoiceController::class, 'update'])->middleware($ar('accounting.ar_invoice.update', $ci));
            Route::post('ar-invoices/{invoice}/submit', [Receivables\ArInvoiceController::class, 'submit'])->middleware($ar('accounting.ar_invoice.submit', $ci));
            Route::post('ar-invoices/{invoice}/approve', [Receivables\ArInvoiceController::class, 'approve'])->middleware($ar('accounting.ar_invoice.approve', $ci));
            Route::post('ar-invoices/{invoice}/reject', [Receivables\ArInvoiceController::class, 'reject'])->middleware($ar('accounting.ar_invoice.approve', $ci));
            Route::post('ar-invoices/{invoice}/reopen', [Receivables\ArInvoiceController::class, 'reopen'])->middleware($ar('accounting.ar_invoice.update', $ci));
            Route::post('ar-invoices/{invoice}/cancel', [Receivables\ArInvoiceController::class, 'cancel'])->middleware($ar('accounting.ar_invoice.update', $ci));
            Route::post('ar-invoices/{invoice}/post', [Receivables\ArInvoiceController::class, 'post'])->middleware($ar('accounting.ar_invoice.post', $ci));
            Route::post('ar-invoices/{invoice}/reverse', [Receivables\ArInvoiceController::class, 'reverse'])->middleware($ar('accounting.ar_invoice.reverse', $ci));

            // Customer receipts and allocations (feature AR_RECEIPT).
            $rc = 'AR_RECEIPT';
            Route::get('customer-receipts', [Receivables\CustomerReceiptController::class, 'index'])->middleware($ar('accounting.ar_receipt.view', $rc));
            Route::post('customer-receipts', [Receivables\CustomerReceiptController::class, 'store'])->middleware($ar('accounting.ar_receipt.create', $rc));
            Route::get('customer-receipts/export', [Receivables\ArExportController::class, 'receipts'])->middleware($ar('accounting.report.export', $rc));
            Route::get('customer-receipts/{receipt}', [Receivables\CustomerReceiptController::class, 'show'])->middleware($ar('accounting.ar_receipt.view', $rc));
            Route::patch('customer-receipts/{receipt}', [Receivables\CustomerReceiptController::class, 'update'])->middleware($ar('accounting.ar_receipt.create', $rc));
            Route::post('customer-receipts/{receipt}/submit', [Receivables\CustomerReceiptController::class, 'submit'])->middleware($ar('accounting.ar_receipt.submit', $rc));
            Route::post('customer-receipts/{receipt}/approve', [Receivables\CustomerReceiptController::class, 'approve'])->middleware($ar('accounting.ar_receipt.approve', $rc));
            Route::post('customer-receipts/{receipt}/reject', [Receivables\CustomerReceiptController::class, 'reject'])->middleware($ar('accounting.ar_receipt.approve', $rc));
            Route::post('customer-receipts/{receipt}/reopen', [Receivables\CustomerReceiptController::class, 'reopen'])->middleware($ar('accounting.ar_receipt.create', $rc));
            Route::post('customer-receipts/{receipt}/cancel', [Receivables\CustomerReceiptController::class, 'cancel'])->middleware($ar('accounting.ar_receipt.create', $rc));
            Route::post('customer-receipts/{receipt}/post', [Receivables\CustomerReceiptController::class, 'post'])->middleware($ar('accounting.ar_receipt.post', $rc));
            Route::post('customer-receipts/{receipt}/reverse', [Receivables\CustomerReceiptController::class, 'reverse'])->middleware($ar('accounting.ar_receipt.reverse', $rc));
            Route::get('customers/{customer}/open-invoices', [Receivables\CustomerReceiptController::class, 'openInvoices'])->middleware($ar('accounting.ar_receipt.view', $rc));
            Route::get('customers/{customer}/allocation-suggestion', [Receivables\CustomerReceiptController::class, 'suggest'])->middleware($ar('accounting.ar_receipt.create', $rc));

            // Credit notes (feature CREDIT_NOTE).
            $cn = 'CREDIT_NOTE';
            Route::get('ar-credit-notes', [Receivables\ArCreditNoteController::class, 'index'])->middleware($ar('accounting.ar_credit_note.view', $cn));
            Route::post('ar-credit-notes', [Receivables\ArCreditNoteController::class, 'store'])->middleware($ar('accounting.ar_credit_note.create', $cn));
            Route::get('ar-credit-notes/export', [Receivables\ArExportController::class, 'creditNotes'])->middleware($ar('accounting.report.export', $cn));
            Route::get('ar-credit-notes/{note}', [Receivables\ArCreditNoteController::class, 'show'])->middleware($ar('accounting.ar_credit_note.view', $cn));
            Route::patch('ar-credit-notes/{note}', [Receivables\ArCreditNoteController::class, 'update'])->middleware($ar('accounting.ar_credit_note.create', $cn));
            Route::post('ar-credit-notes/{note}/submit', [Receivables\ArCreditNoteController::class, 'submit'])->middleware($ar('accounting.ar_credit_note.submit', $cn));
            Route::post('ar-credit-notes/{note}/approve', [Receivables\ArCreditNoteController::class, 'approve'])->middleware($ar('accounting.ar_credit_note.approve', $cn));
            Route::post('ar-credit-notes/{note}/reject', [Receivables\ArCreditNoteController::class, 'reject'])->middleware($ar('accounting.ar_credit_note.approve', $cn));
            Route::post('ar-credit-notes/{note}/reopen', [Receivables\ArCreditNoteController::class, 'reopen'])->middleware($ar('accounting.ar_credit_note.create', $cn));
            Route::post('ar-credit-notes/{note}/cancel', [Receivables\ArCreditNoteController::class, 'cancel'])->middleware($ar('accounting.ar_credit_note.create', $cn));
            Route::post('ar-credit-notes/{note}/post', [Receivables\ArCreditNoteController::class, 'post'])->middleware($ar('accounting.ar_credit_note.post', $cn));
            Route::post('ar-credit-notes/{note}/reverse', [Receivables\ArCreditNoteController::class, 'reverse'])->middleware($ar('accounting.ar_credit_note.reverse', $cn));

            // AR aging and AR-to-GL reconciliation (feature AR_AGING).
            Route::get('ar-aging', [Receivables\ArReportController::class, 'aging'])->middleware($ar('accounting.ar_aging.view', 'AR_AGING'));
            Route::get('ar-aging/export', [Receivables\ArReportController::class, 'exportAging'])->middleware($ar('accounting.report.export', 'AR_AGING'));
            Route::get('reconciliation/ar', [Receivables\ArReportController::class, 'reconciliation'])->middleware($ar('accounting.reconciliation.ar.view', 'AR_AGING'));
            Route::get('reconciliation/ar/export', [Receivables\ArExportController::class, 'reconciliation'])->middleware($ar('accounting.report.export', 'AR_AGING'));
        });
    });
});
