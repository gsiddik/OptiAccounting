<?php

return [

    /*
    | Identity mode of this installation (docs/architecture/SAAS_ARCHITECTURE.md).
    | "standalone": local users, tenants, roles and permissions.
    | "optinexus": OptiNexus is the authority for tenant, user, membership,
    |              role, permission, subscription and events.
    */
    'identity_mode' => env('OPTIACCOUNTING_IDENTITY_MODE', 'standalone'),

    'identity_modes' => ['standalone', 'optinexus'],

    /*
    | Password of the demo accounts created by DemoSeeder (documented in docs/DEMO.md).
    | Demo environments only; the seeder refuses to run in production.
    */
    'demo_password' => env('OPTIACCOUNTING_DEMO_PASSWORD'),

    /*
    | OptiNexus adapter (docs/architecture/OPTINEXUS_ADAPTER.md). Only read when identity_mode = optinexus.
    | `sso` is the OIDC client registered in OptiNexus; `service` is the M2M service account
    | (scopes: authorization.check commercial.read event.write audit.write).
    */
    'optinexus' => [
        'base_url' => rtrim((string) env('OPTINEXUS_BASE_URL', ''), '/'),
        'application_code' => env('OPTINEXUS_APPLICATION_CODE', 'optiaccounting'),

        'sso' => [
            'client_id' => env('OPTINEXUS_SSO_CLIENT_ID'),
            'client_secret' => env('OPTINEXUS_SSO_CLIENT_SECRET'),
            // Registered exactly as a redirect URI on the OIDC client; defaults to this API's callback route.
            'redirect_uri' => env('OPTINEXUS_SSO_REDIRECT_URI'),
            // Where the browser lands after the callback (the SPA).
            'frontend_url' => rtrim((string) env('OPTINEXUS_SSO_FRONTEND_URL', env('APP_URL', '')), '/'),
            'state_ttl_seconds' => 600,
            'ticket_ttl_seconds' => 60,
        ],

        'service' => [
            'client_id' => env('OPTINEXUS_SERVICE_CLIENT_ID'),
            'client_secret' => env('OPTINEXUS_SERVICE_CLIENT_SECRET'),
            'timeout_seconds' => (int) env('OPTINEXUS_TIMEOUT', 15),
        ],

        // Local password login for users with platform access (operators) while OptiNexus is the identity authority.
        'break_glass_login' => (bool) env('OPTINEXUS_BREAK_GLASS_LOGIN', true),

        // Create the local tenant on the first verified sign-in of an unknown OptiNexus tenant.
        'provision_tenants' => (bool) env('OPTINEXUS_PROVISION_TENANTS', true),

        // Seconds an answer about a user's permissions may be reused. Never above 300 (SAAS_ARCHITECTURE §7).
        'permission_ttl_seconds' => max(1, min(300, (int) env('OPTINEXUS_PERMISSION_TTL', 300))),
        'permission_pool_size' => 25,

        // Re-read a tenant's commercial state at sign-in when the projection is older than this.
        'entitlement_ttl_seconds' => max(0, (int) env('OPTINEXUS_ENTITLEMENT_TTL', 300)),

        'relay' => ['batch_size' => 100, 'max_attempts' => 20],
    ],

];
