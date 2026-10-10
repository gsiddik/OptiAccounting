<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Entitlement\Services\CapacityService;
use Illuminate\Support\Facades\DB;

/**
 * Everything an OptiNexus administrator has to register for OptiEntry (application, capabilities, permissions,
 * event catalog, service-account scopes, OIDC client). It is generated from this repository's own catalogs, so the
 * registration can never drift from the code (docs/integration/OPTINEXUS_ONBOARDING.md).
 */
class OptinexusManifest
{
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

        $permissions = DB::table('permissions')->where('scope', 'tenant')->orderBy('code')->get(['code', 'description'])
            ->map(fn ($row) => ['permission_key' => "{$application}.{$row->code}", 'name' => $row->code, 'description' => $row->description])->all();

        return [
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
            'service_account' => ['scopes' => explode(' ', NexusApi::SCOPES)],
            'oidc_client' => [
                'redirect_uris' => [OptinexusSettings::redirectUri()],
                'post_logout_redirect_uris' => [$frontend.'/login'],
                'backchannel_logout_uri' => url('/api/v1/integration/optinexus/backchannel-logout'),
                'launch_url' => $frontend,
                'scopes' => ['openid', 'profile', 'email'],
            ],
        ];
    }
}
