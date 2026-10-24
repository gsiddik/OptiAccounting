# Standalone identity -> central governance (optional plan)

Status: plan for operators. Nothing here runs automatically; no user database is migrated without a mapping and a decision.
Context: owner decision K1 (2026-10-24) keeps `standalone` (local Superadmin, users, roles, permissions, subscriptions) a full, supported
mode; this plan is only for an installation that chooses to join OptiNexus governance (R01-R08, `OPTINEXUS_ADAPTER.md` §9). The file name
keeps the former "legacy" wording so existing links stay valid. Switching a running installation in place is not supported (`SAAS_ARCHITECTURE.md` §1): the
local tenant keeps its local subscription and the projection skips it with a warning. The steps below move one tenant at a time.

## What is local authority in `standalone`

| Concern | Tables / code | In `optinexus` mode |
|---|---|---|
| Tenant, subscription, module/feature entitlement, capacity | `tenants`, `subscriptions`, `tenant_*_entitlements`, `tenant_capacity_limits`; Platform Portal writes | projected from OptiNexus (`source = OPTINEXUS`); local writes answer `409 MANAGED_BY_OPTINEXUS` |
| Users, memberships | `users`, `tenant_users`; `Api/App/MemberController` | OptiNexus is the authority; local rows are a projection keyed by `users.optinexus_subject`; people are created through the delegation API |
| Roles, grants | `roles`, `role_permissions`, `tenant_user_roles`; `LocalPermissionSource`, `RoleController` | permissions come from `POST /authorization/effective-permissions`; custom roles are created through the delegation API |
| Data scope, branches, business units | `data_scopes`, `branches`, `business_units` | stays local (accounting-specific); preserved by the migration |

## Plan, per tenant (operator-run, reversible until step 6)

1. **Inventory (read-only).** Export, per tenant: active members (`users.email`, `name`, `status`, `tenant_users.status`, last sign-in),
   their roles and the effective permission set of each role (`role_permissions`), custom roles (`roles.is_system = false`), data scopes,
   the subscription, enabled modules/features and capacity limits. Use SQL views or `optientry:*` read commands; do not change data.
2. **Map to OptiNexus subjects.** For every person decide: *existing identity* (match by verified e-mail in OptiNexus, record its user id) or
   *new identity* (to be created through delegation). Output a reviewed table `local user id -> OptiNexus user id | NEW | EXCLUDE`. E-mails that
   differ in case, shared mailboxes and service identities (OptiFleet integration actor) need an explicit decision; platform operators stay
   local break-glass accounts and are not mapped.
3. **Map roles.** For every local role decide: *equivalent OptiNexus application role template*, *new custom role* (recreated through the
   delegation API within the delegable set), or *drop*. A local permission that is not delegable (`DelegableSet`) must be given by an
   operator through a role template in OptiNexus; document each such exception. Role names are not authorization: compare permission sets.
4. **Stage in OptiNexus.** An operator creates the tenant, assigns the application and the plan (modules/features/limits equal to the local
   entitlements), flags the Admin Tenant, and creates or links the people and roles of the mapping. Verify with `optientry:nexus:check` and a
   dry run of `POST /authorization/effective-permissions` per mapped person: the permission set must equal the inventory (or the documented difference).
5. **Link, do not recreate.** Set `tenants.optinexus_tenant_id` and, per mapped person, `users.optinexus_subject` on the **existing** rows
   (a reviewed one-off script run by the operator, printed first, applied in one transaction). Keeping the rows keeps `tenant_users`, data scopes,
   journals' `created_by/approved_by/posted_by` and the audit trail intact: posted history is never rewritten and no accounting row is touched.
   Never re-bind a user that already has a different subject (`account_conflict`).
6. **Cut over.** Switch the installation (or move the tenant to a new `optinexus` installation by restoring its data) and let people sign in
   through OptiNexus: the first verified sign-in matches the subject, activates the membership and keeps the local data scope. From here local
   role/member/subscription writes are refused. Roll-back before this step is only a configuration change.
7. **Retire legacy artefacts.** After a signed-off observation period, deactivate (never delete) local passwords of mapped people, keep local
   roles for history, and remove the tenant from any legacy platform procedures.

Rules: no automatic migration; no password or secret is exported; financial data is never changed; every step is recorded in the audit log;
a mapping that cannot be decided is escalated to the owner with the impact stated, and the tenant stays on legacy until it is.
