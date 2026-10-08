# Demo data and logins

Demo data exists only for local and showcase environments. The seeder refuses to run in
`production`. The production-safe `DatabaseSeeder` creates no tenant, user or password.

```bash
php artisan migrate:fresh --seed                 # production-safe catalog, permissions, system roles
php artisan db:seed --class=DemoSeeder           # demo bundles, operators, 4 tenants (safe to run again)
```

Demo password for every account below: `Demo#Passw0rd2026`
(override with `OPTIACCOUNTING_DEMO_PASSWORD` before seeding). Never reuse it outside demo.

## Platform Portal (operators)

| Login | Password | Lands in | What it can do |
|---|---|---|---|
| `platform.admin@demo.test` | `Demo#Passw0rd2026` | platform | Everything in the Platform Portal: tenants, subscriptions, entitlements, catalog, bundles, operators, audit |
| `platform.support@demo.test` | `Demo#Passw0rd2026` | platform | Read-only view of the same screens (no create/update/status actions) |

## Tenant Portal

| Login | Password | Lands in | Tenant and role | Data scope |
|---|---|---|---|---|
| `admin@majujaya.demo.test` | `Demo#Passw0rd2026` | tenant | PT Maju Jaya, full tenant administration | whole tenant |
| `keuangan@majujaya.demo.test` | `Demo#Passw0rd2026` | tenant | PT Maju Jaya, "Staf Keuangan" (organization view, subscription view, audit view) | branch Jakarta (JKT) |
| `cabang.sby@majujaya.demo.test` | `Demo#Passw0rd2026` | tenant | PT Maju Jaya, "Admin Cabang" (organization manage, user view) | branch Surabaya (SBY) |
| `viewer@majujaya.demo.test` | `Demo#Passw0rd2026` | tenant | PT Maju Jaya, read-only viewer | whole tenant |
| `admin@sinarabadi.demo.test` | `Demo#Passw0rd2026` | tenant | CV Sinar Abadi (Starter bundle, 1 branch limit), full administration | whole tenant |
| `admin@tunggakan.demo.test` | `Demo#Passw0rd2026` | tenant | PT Tunggakan Demo, subscription PAST_DUE: every module READ_ONLY (module reads work, module changes refused); tenant administration (users, roles, organization) stays available | whole tenant |
| `multi@demo.test` | `Demo#Passw0rd2026` | identity | Viewer in PT Maju Jaya and administrator in CV Sinar Abadi: choose a tenant after signing in, then switch | per tenant |
| `admin@ditangguhkan.demo.test` | `Demo#Passw0rd2026` | identity | CV Ditangguhkan Demo is SUSPENDED: the account has no tenant it may enter | none |

"Lands in" is the scope of the token returned by `POST /api/v1/auth/login`: `platform` and `tenant`
enter directly, `identity` means the user must pick a tenant (`POST /api/v1/auth/switch-tenant`).

## Demo tenants

| Tenant | Tenant status | Bundle | Subscription | Limits |
|---|---|---|---|---|
| PT Maju Jaya (`maju-jaya`) | ACTIVE | Business | ACTIVE, 1 year | 10 users, 3 branches, 10 business units |
| CV Sinar Abadi (`sinar-abadi`) | ACTIVE | Starter | ACTIVE, 1 year | 5 users, 1 branch, 3 business units |
| PT Tunggakan Demo (`tunggakan`) | ACTIVE | Business | PAST_DUE | 10 users, 3 branches, 10 business units |
| CV Ditangguhkan Demo (`ditangguhkan`) | SUSPENDED | Starter | ACTIVE | 5 users, 1 branch, 3 business units |

Bundles `STARTER`, `BUSINESS` and `ENTERPRISE` are created by the demo seeder only; in a real installation the
platform operator composes bundles in the Platform Portal.

## First platform administrator (real installations)

```bash
OPTIACCOUNTING_BOOTSTRAP_PASSWORD='<strong password>' php artisan optiaccounting:bootstrap-platform-admin admin@your-company.com --name="Your Name"
```

Without the environment variable the command asks for the password (hidden). Running it again changes nothing.
