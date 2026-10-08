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

    'optinexus' => [
        'base_url' => env('OPTINEXUS_BASE_URL'),
        'application_code' => env('OPTINEXUS_APPLICATION_CODE', 'optiaccounting'),
    ],

];
