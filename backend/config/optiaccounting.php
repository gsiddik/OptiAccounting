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

    'optinexus' => [
        'base_url' => env('OPTINEXUS_BASE_URL'),
        'application_code' => env('OPTINEXUS_APPLICATION_CODE', 'optiaccounting'),
    ],

];
