<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Entitlement\Services\CapacityService;
use Illuminate\Support\Facades\DB;

/**
 * Everything an OptiNexus administrator has to register for OptiEntry (application, capabilities, permissions,
 * event catalog, service-account scopes, OIDC client). It is generated from this repository's own catalogs, so the
 * registration can never drift from the code (docs/integration/OPTINEXUS_ONBOARDING.md).
 *
 * Manifest v2 (audit A02/R08): `manifest_version` is the schema ("2.0", a string, as OptiNexus requires), `revision` a digest of the catalog content (it changes if and
 * only if a capability, permission, delegable flag, event or scope changes, so an importer can tell "same" from "new" without
 * comparing everything; deployment URLs are not part of it), and every permission names its `capability_code` (the module or feature whose entitlement it needs, null
 * when none) and says whether it is `delegable` (DelegableSet; false unless listed). v1 readers ignore the additions.
 */
class OptinexusManifest
{
    public const VERSION = '2.0';

    /** @return array<string,mixed> */
    public function build(): array
    {
        $application = OptinexusSettings::applicationCode();
        $frontend = (string) config('optientry.optinexus.sso.frontend_url');

        $modules = DB::table('modules')->orderBy('sort_order')->get(['id', 'code', 'name', 'description', 'sort_order']);
        $features = DB::table('features')->orderBy('sort_order')->get(['module_id', 'code', 'name', 'sort_order'])->groupBy('module_id');

        $capabilities = [];
        foreach ($modules as $module) {
            $capabilities[] = ['type' => 'MODULE', 'code' => $module->code, 'name' => $module->name, 'description' => $module->description, 'sort_order' => (int) $module->sort_order, 'parent_code' => null];
            foreach ($features->get($module->id, collect()) as $feature) {
                $capabilities[] = ['type' => 'FEATURE', 'code' => $feature->code, 'name' => $feature->name, 'description' => null, 'sort_order' => (int) $feature->sort_order, 'parent_code' => $module->code];
            }
        }

        $gates = PermissionCapabilities::map();
        $permissions = DB::table('permissions')->where('scope', 'tenant')->orderBy('code')->get(['code', 'group', 'description'])
            ->map(fn ($row) => [
                'permission_key' => "{$application}.{$row->code}",
                'name' => $row->code,
                'description' => $row->description,
                'group' => $row->group,
                'capability_code' => $gates[$row->code] ?? null,
                'delegable' => DelegableSet::allows($row->code),
            ])->all();

        $content = [
            'application' => [
                'application_code' => $application,
                'name' => 'OptiEntry',
                'description' => 'Double-entry accounting for the organization.',
                'frontend_url' => $frontend,
            ],
            'capabilities' => $capabilities,
            'permissions' => $permissions,
            'events' => EventCatalog::all(),
            'entitlement_limit_keys' => array_map('strtolower', CapacityService::codes()),
            // `tenant.delegate` is asked for with its own token (NexusApi::DELEGATION_SCOPES); without it the Users and Roles screens cannot work.
            'service_account' => ['scopes' => array_values(array_unique([...explode(' ', NexusApi::SCOPES), ...explode(' ', NexusApi::DELEGATION_SCOPES)]))],
            'oidc_client' => [
                'redirect_uris' => [OptinexusSettings::redirectUri()],
                'post_logout_redirect_uris' => [$frontend.'/login'],
                'backchannel_logout_uri' => url('/api/v1/integration/optinexus/backchannel-logout'),
                'launch_url' => $frontend,
                'scopes' => ['openid', 'profile', 'email'],
            ],
        ];

        // The digest covers the catalog only (not URLs), so every installation of one release reports the same revision.
        $catalog = array_intersect_key($content, array_flip(['capabilities', 'permissions', 'events', 'entitlement_limit_keys', 'service_account']));

        return ['manifest_version' => self::VERSION, 'revision' => substr(hash('sha256', (string) json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16)] + $content;
    }
}
