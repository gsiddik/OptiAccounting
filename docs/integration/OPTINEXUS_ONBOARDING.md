# Connecting OptiAccounting to OptiNexus

For an OptiNexus administrator and the operator of an OptiAccounting installation.
Design: `docs/architecture/OPTINEXUS_ADAPTER.md`. OptiNexus contract:
`INTEGRATION_GUIDE.md` in the OptiNexus repository. **OptiNexus is not modified**;
everything below uses its existing API.

Every step in section 1 was run against a real OptiNexus (see
`docs/status/OA0-N_STATUS.md`). `php artisan optiaccounting:nexus:manifest`
prints the data to register, generated from this repository's catalogs, so it
never drifts from the code.

## 1. In OptiNexus (once per platform, as an administrator)

Use a Sanctum token of an administrator (`POST /api/v1/auth/login`).

| # | Call | Notes |
|---|---|---|
| 1 | `POST /applications` `{application_code: "optiaccounting", name, description, frontend_url, backend_url}`, then `POST /applications/{id}/submit`, `/approve`, `/publish` | Code must equal `OPTINEXUS_APPLICATION_CODE`. |
| 2 | `POST /applications/{id}/capabilities` for every `capabilities[]` of the manifest, **modules first** | The manifest gives `parent_code`; OptiNexus wants `parent_id`, so pass the id returned for the module. Capabilities are created ACTIVE (do not call `activate`). |
| 3 | `POST /permissions` `{application_id, permission_key, name, description}` for every `permissions[]` | Keys are `optiaccounting.<resource>.<action>`. Platform permissions are not registered (platform operators are local). |
| 4 | `php artisan db:seed --class=SystemRoleSeeder` on OptiNexus | PLATFORM_SUPERADMIN receives `*` only when this seeder runs. Without it the administrator cannot grant the new permissions (`403 PRIVILEGE_ESCALATION_DENIED`). |
| 5 | `POST /event-catalog` for every `events[]` | Until an event type is registered, OptiNexus answers 422 and the relay retries (nothing is lost). |
| 6 | A product with the application, a plan with the capabilities (modules and features) and the limits `user_limit`, `branch_limit`, `business_unit_limit`, then `POST /subscriptions` and `/activate` | Capability codes equal the local module and feature codes. A limit that is absent means "no limit set". |
| 7 | Customer, then `POST /tenants`, **`/provision`, `/activate`**, then `POST /tenants/{id}/applications/{app}` | A DRAFT tenant cannot sign in (OptiNexus answers `access_denied`). |
| 8 | A role with `role_type: APPLICATION` and `application_id` (no `tenant_id` on the role), `POST /roles/{role}/permissions/{permission}` per permission | The tenant goes on the assignment, not on the role. |
| 9 | Per person: `POST /users`, `POST /users/{u}/tenants/{t}`, `POST /users/{u}/applications/{app}` `{tenant_id}`, `POST /users/{u}/roles/{role}` `{tenant_id}` | A person with the application but no permission is refused (`no_permissions`). |
| 10 | `POST /oidc-clients` `{application_id, name, redirect_uris, post_logout_redirect_uris, launch_url, backchannel_logout_uri, confidential: true, require_pkce: true}` | Take the values from `oidc_client` of the manifest. The secret is shown once. |
| 11 | `POST /service-accounts` `{name, application_id}` | The secret is shown once. Scopes are requested at token time: `authorization.check commercial.read event.write audit.write`. |

Bulk registration hits OptiNexus's rate limit (`429 RATE_LIMITED`); wait and retry.

## 2. In OptiAccounting (the installation)

```
OPTIACCOUNTING_IDENTITY_MODE=optinexus
OPTINEXUS_BASE_URL=https://nexus.example.com        # the issuer
OPTINEXUS_APPLICATION_CODE=optiaccounting
OPTINEXUS_SSO_CLIENT_ID=...        OPTINEXUS_SSO_CLIENT_SECRET=...
OPTINEXUS_SSO_REDIRECT_URI=https://api.example.com/api/v1/auth/sso/callback   # exactly as registered
OPTINEXUS_SSO_FRONTEND_URL=https://app.example.com                            # the SPA
OPTINEXUS_SERVICE_CLIENT_ID=...    OPTINEXUS_SERVICE_CLIENT_SECRET=...
OPTINEXUS_BREAK_GLASS_LOGIN=true   # local password login for platform operators only
OPTINEXUS_PROVISION_TENANTS=true   # create the local tenant on a verified first sign-in
OPTINEXUS_PERMISSION_TTL=300       # seconds, never above 300
OPTINEXUS_ENTITLEMENT_TTL=300
```

Secrets belong in the secret manager, never in the repository. The scheduler must
run (`php artisan schedule:run` every minute): it relays events every minute and
re-syncs entitlements every 5 minutes. Then:

```
php artisan optiaccounting:nexus:check     # configuration, discovery, JWKS, service-account token and scopes
php artisan optiaccounting:nexus:sync-entitlements [--tenant=ID]
php artisan optiaccounting:nexus:relay-events [--retry-failed] [--tenant=ID]
```

`check` names what is wrong (missing variable, missing scope, application not
registered). The first sign-in of a person of an unknown tenant creates and links
the local tenant (only if OptiNexus confirms it is ACTIVE with `optiaccounting`
enabled) and the person, then projects the subscription.

## 3. Behaviour to know

- **Members, roles and subscriptions are edited in OptiNexus.** The matching local
  actions answer `409 MANAGED_BY_OPTINEXUS` and the UI hides them. Data scopes,
  organization (branches, business units) and tenant profile stay local.
- **Removing a person's access to the application in OptiNexus** is sent as an
  access-revoked call of scope `user`: the local account is deactivated in every
  tenant until the next successful OptiNexus sign-in. Removing the person from one
  tenant, or suspending the tenant, is scope `tenant`.
- **A lapsed or suspended subscription** (it leaves the commercial context) ends
  module access (`SUBSCRIPTION_INACTIVE`); the last known capacity limits are kept.
- **Sign-out** from OptiAccounting also ends the OptiNexus session and returns to
  the OptiAccounting login page (the `post_logout_redirect_uris` entry above).
- **If OptiNexus is down**, new sign-ins fail closed (`sso_unavailable`), sessions
  keep working until the permission cache expires (≤ 5 minutes), then answer
  `503 IDENTITY_PROVIDER_UNAVAILABLE`. Accounting data is never touched.
- **Switching an installation with existing local tenants** to `optinexus` is not
  supported in place (SAAS_ARCHITECTURE §1); such a tenant keeps its local
  subscription and is skipped by the projection with a warning.

## 4. After an upgrade that adds permissions or events

A release that adds `accounting.*` permissions or events (OA1 added 27 permissions and `journal.posted` / `journal.reversed`) needs
section 1 repeated for the **new entries only**: re-run `optiaccounting:nexus:manifest`, register the missing `permissions[]` (step 3) and `events[]`
(step 5), then attach the new permissions to the application roles (step 8) and run `optiaccounting:nexus:sync-entitlements`. Entries that are already registered need no change. Until it is done, people sign in as before but hold none of the new permissions.
