<?php

/*
| OptiEntry (formerly OptiAccounting). The OPTIENTRY_* variables replace the former OPTIACCOUNTING_* ones; the old
| names are still read as a fallback so an existing .env keeps working. The OptiNexus application code, the
| `optiaccounting.*` permission/event keys and the database identifiers are registered or persisted identifiers and
| were deliberately not renamed (docs/status/RENAME_OPTIENTRY.md).
*/

return [

    /*
    | Identity mode of this installation (docs/architecture/SAAS_ARCHITECTURE.md).
    | "standalone": local users, tenants, roles and permissions.
    | "optinexus": OptiNexus is the authority for tenant, user, membership,
    |              role, permission, subscription and events.
    */
    'identity_mode' => env('OPTIENTRY_IDENTITY_MODE', env('OPTIACCOUNTING_IDENTITY_MODE', 'standalone')),

    'identity_modes' => ['standalone', 'optinexus'],

    /*
    | Password of the demo accounts created by DemoSeeder (documented in docs/DEMO.md).
    | Demo environments only; the seeder refuses to run in production.
    */
    'demo_password' => env('OPTIENTRY_DEMO_PASSWORD', env('OPTIACCOUNTING_DEMO_PASSWORD')),

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
            // Bounded recovery of one call (NexusApi::send): repeats after a 429 and the longest Retry-After slept through.
            'max_retries' => max(0, min(5, (int) env('OPTINEXUS_MAX_RETRIES', 2))),
            'max_retry_wait_seconds' => max(1, min(30, (int) env('OPTINEXUS_MAX_RETRY_WAIT', 5))),
        ],

        // Local password login for users with platform access (operators) while OptiNexus is the identity authority.
        'break_glass_login' => (bool) env('OPTINEXUS_BREAK_GLASS_LOGIN', true),

        // Create the local tenant on the first verified sign-in of an unknown OptiNexus tenant.
        'provision_tenants' => (bool) env('OPTINEXUS_PROVISION_TENANTS', true),

        // Seconds an answer about a user's permissions may be reused. Never above 300 (SAAS_ARCHITECTURE §7).
        'permission_ttl_seconds' => max(1, min(300, (int) env('OPTINEXUS_PERMISSION_TTL', 300))),

        // Re-read a tenant's commercial state at sign-in when the projection is older than this.
        'entitlement_ttl_seconds' => max(0, (int) env('OPTINEXUS_ENTITLEMENT_TTL', 300)),

        'relay' => ['batch_size' => 100, 'max_attempts' => 20],
    ],

    /*
    | Integration feeds (OA6). `feed_pairing`: whether a pulled feed must be proven to belong to the same OptiNexus tenant as the organization
    | (audit A19). auto = required while identity_mode is optinexus; required = always; off = never (legacy standalone only).
    */
    'integration' => [
        'feed_pairing' => in_array($pairing = strtolower((string) env('OPTIENTRY_FEED_PAIRING', 'auto')), ['auto', 'required', 'off'], true) ? $pairing : 'auto',
        // A NEEDS_REVIEW payment, correction or cancellation that still finds no recorded invoice after this many minutes means the invoice event
        // never reached the feed (a missed projection at the source): an alert, not a wait (audit A17).
        'orphan_grace_minutes' => max(1, (int) env('OPTIENTRY_FEED_ORPHAN_GRACE_MINUTES', 120)),
    ],

    // Most rows one CSV export of an OA2 list may hold; beyond it the request is refused (EXPORT_TOO_LARGE) and the filters must be narrowed.
    'export_max_rows' => max(1, (int) env('OPTIENTRY_EXPORT_MAX_ROWS', env('OPTIACCOUNTING_EXPORT_MAX_ROWS', 10000))),

    // How many days back an exchange rate may be used for a document date (OA4). Beyond it the rate is stale and the document needs a fresh one.
    'fx_rate_max_age_days' => max(1, (int) env('OPTIENTRY_FX_RATE_MAX_AGE_DAYS', 31)),

];
