<?php

use App\Http\Controllers\Api\App;
use App\Http\Controllers\Api\App\Accounting;
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

            Route::get('dimension-types', [Accounting\DimensionController::class, 'types'])->middleware($gate('accounting.dimension.view', $config));
            Route::get('cost-centers', [Accounting\DimensionController::class, 'costCenters'])->middleware($gate('accounting.dimension.view', $config));
            Route::post('cost-centers', [Accounting\DimensionController::class, 'storeCostCenter'])->middleware($gate('accounting.dimension.manage', $config));
            Route::patch('cost-centers/{costCenter}', [Accounting\DimensionController::class, 'updateCostCenter'])->middleware($gate('accounting.dimension.manage', $config));
            Route::post('cost-centers/{costCenter}/status', [Accounting\DimensionController::class, 'costCenterStatus'])->middleware($gate('accounting.dimension.manage', $config));
        });
    });
});
