<?php

namespace App\Domain\AccessControl;

/**
 * Permission registry (code = resource.action). OA0 registers only what OA0
 * needs; later phases add their permissions additively and call
 * `php artisan optiaccounting:sync-permissions`.
 */
final class PermissionCatalog
{
    /** @return list<array{code:string,scope:string,group:string,description:string}> */
    public static function all(): array
    {
        $rows = [];
        foreach (self::definitions() as $scope => $groups) {
            foreach ($groups as $group => $permissions) {
                foreach ($permissions as $code => $description) {
                    $rows[] = compact('code', 'scope', 'group', 'description');
                }
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public static function codes(string $scope): array
    {
        return array_values(array_map(
            fn ($row) => $row['code'],
            array_filter(self::all(), fn ($row) => $row['scope'] === $scope),
        ));
    }

    private static function definitions(): array
    {
        return [
            'platform' => [
                'Tenants' => [
                    'platform.tenant.view' => 'View tenants',
                    'platform.tenant.create' => 'Create tenants',
                    'platform.tenant.update' => 'Update tenant details',
                    'platform.tenant.status.manage' => 'Change tenant lifecycle status',
                    'platform.membership.view' => 'View tenant memberships',
                    'platform.membership.manage' => 'Manage tenant memberships',
                ],
                'Product' => [
                    'platform.module.view' => 'View modules, dependencies and features',
                    'platform.module.manage' => 'Manage modules, dependencies and features',
                    'platform.bundle.view' => 'View bundles',
                    'platform.bundle.manage' => 'Manage bundles',
                ],
                'Commercial' => [
                    'platform.subscription.view' => 'View subscriptions',
                    'platform.subscription.manage' => 'Manage subscriptions',
                    'platform.entitlement.view' => 'View entitlements and capacity',
                    'platform.entitlement.manage' => 'Manage entitlements and capacity',
                ],
                'Access' => [
                    'platform.user.view' => 'View platform users',
                    'platform.user.manage' => 'Manage platform users',
                    'platform.role.view' => 'View platform roles',
                    'platform.role.manage' => 'Manage platform roles',
                    'platform.permission.view' => 'View platform permissions',
                ],
                'Audit' => [
                    'platform.audit.view' => 'View the platform audit log',
                ],
            ],
            'tenant' => [
                'Access' => [
                    'access.user.view' => 'View users and memberships',
                    'access.user.manage' => 'Invite and manage users',
                    'access.role.view' => 'View roles',
                    'access.role.manage' => 'Manage roles and their permissions',
                    'access.permission.view' => 'View the permission list',
                    'access.scope.view' => 'View data scopes',
                    'access.scope.manage' => 'Manage data scopes',
                ],
                'Organization' => [
                    'organization.view' => 'View branches and business units',
                    'organization.manage' => 'Manage branches and business units',
                ],
                'Account' => [
                    'account.subscription.view' => 'View subscription, modules, features and usage',
                ],
                'Accounting configuration' => [
                    'accounting.profile.view' => 'View the accounting profile and setup status',
                    'accounting.profile.manage' => 'Manage the accounting profile and activate accounting',
                    'accounting.period.view' => 'View fiscal years and accounting periods',
                    'accounting.period.manage' => 'Manage fiscal years and open or soft-close periods',
                    'accounting.period.close' => 'Close accounting periods',
                    'accounting.coa.view' => 'View the chart of accounts',
                    'accounting.coa.manage' => 'Manage the chart of accounts and apply a template',
                    'accounting.dimension.view' => 'View accounting dimensions and cost centers',
                    'accounting.dimension.manage' => 'Manage cost centers',
                    'accounting.posting_rule.view' => 'View posting rules',
                    'accounting.posting_rule.manage' => 'Manage posting rules',
                    'accounting.account_mapping.view' => 'View account mappings',
                    'accounting.account_mapping.manage' => 'Manage account mappings',
                ],
                'Journals' => [
                    'accounting.journal.view' => 'View journals',
                    'accounting.journal.create' => 'Create draft journals',
                    'accounting.journal.update' => 'Edit and cancel draft journals',
                    'accounting.journal.submit' => 'Submit journals for approval',
                    'accounting.journal.approve' => 'Approve or reject submitted journals',
                    'accounting.journal.post' => 'Post approved journals',
                    'accounting.journal.post_soft_closed' => 'Post into soft-closed periods',
                    'accounting.journal.reverse' => 'Reverse posted journals',
                    'accounting.opening_balance.view' => 'View the opening balance',
                    'accounting.opening_balance.manage' => 'Prepare the opening balance',
                    'accounting.opening_balance.post' => 'Post the opening balance',
                ],
                'Ledger and reports' => [
                    'accounting.gl.view' => 'View the general ledger',
                    'accounting.trial_balance.view' => 'View the trial balance',
                    'accounting.report.export' => 'Export the chart of accounts, general ledger and trial balance',
                ],
                'Audit' => [
                    'audit.view' => 'View the tenant audit log',
                ],
            ],
        ];
    }
}
