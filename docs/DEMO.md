# Demo data and logins

Demo data exists only for local and showcase environments. The seeder refuses to run in
`production`. The production-safe `DatabaseSeeder` creates no tenant, user or password.

```bash
php artisan migrate:fresh --seed                 # production-safe catalog, permissions, system roles
php artisan db:seed --class=DemoSeeder           # demo bundles, operators, 4 tenants (safe to run again)
```

Demo password for every account below: `Demo#Passw0rd2026`
(override with `OPTIENTRY_DEMO_PASSWORD` before seeding). Never reuse it outside demo.

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
| `akuntan@majujaya.demo.test` | `Demo#Passw0rd2026` | tenant | PT Maju Jaya, "Akuntan": prepares and submits journals, vendor and customer invoices, vendor payments, customer receipts, credit notes, expenses and cash transactions, reconciles bank statements, reads the books (cannot approve or post) | whole tenant |
| `manajer@majujaya.demo.test` | `Demo#Passw0rd2026` | tenant | PT Maju Jaya, "Manajer Keuangan": approves and posts journals and every payables, receivables, expense and cash/bank document, reverses, manages vendors, customers, categories and cash/bank accounts, closes periods, opening balance, configuration, exports | whole tenant |
| `admin@sinarabadi.demo.test` | `Demo#Passw0rd2026` | tenant | CV Sinar Abadi (Starter bundle, 1 branch limit), full administration | whole tenant |
| `admin@tunggakan.demo.test` | `Demo#Passw0rd2026` | tenant | PT Tunggakan Demo, subscription PAST_DUE: every module READ_ONLY (module reads work, module changes refused); tenant administration (users, roles, organization) stays available | whole tenant |
| `multi@demo.test` | `Demo#Passw0rd2026` | identity | Viewer in PT Maju Jaya and administrator in CV Sinar Abadi: choose a tenant after signing in, then switch | per tenant |
| `admin@ditangguhkan.demo.test` | `Demo#Passw0rd2026` | identity | CV Ditangguhkan Demo is SUSPENDED: the account has no tenant it may enter | none |

"Lands in" is the scope of the token returned by `POST /api/v1/auth/login`: `platform` and `tenant`
enter directly, `identity` means the user must pick a tenant (`POST /api/v1/auth/switch-tenant`).

## Demo books (Accounting Core)

PT Maju Jaya is seeded with working books for the current calendar year: SAK EP profile in IDR, the `UMUM_ID` chart of
accounts, an opening balance on 1 January, monthly sales / rent / salary journals (branches JKT and SBY) taken through the
real prepare, approve and post workflow, one reversal, pending journals in every state (2 draft, 1 submitted, 1 approved),
January closed and February soft-closed. Sign in as `akuntan@majujaya.demo.test` (prepares) and `manajer@majujaya.demo.test`
(approves, posts), or as the tenant administrator.

## Demo payables, expenses and cash/bank (OA2)

On top of those books, PT Maju Jaya has the default OA2 posting rules (published from 1 January), payment terms and expense categories, plus
dates relative to today (inside the open periods):

- 4 vendors (`SUMBER`, `LISTRIK`, `SERVIS`, `ATK`) and 2 accounts: `BCA-OPS` (bank, GL 1120) and `KAS-KECIL` (cash, GL 1110).
- 6 vendor invoices: 3 posted (one overdue and part-paid, one fully paid, one with a payment awaiting approval), 1 submitted,
  1 approved (waiting to post), 1 draft. Vendor payments: 2 posted, 1 submitted.
- 4 expenses: a posted payable expense, a posted directly paid one, one submitted, one draft.
- Cash/bank: 3 posted cash transactions and 1 draft; a bank statement `BCA-<year-month>` in progress with 3 matched lines and 1 unmatched
  deposit (the reconciliation cannot be completed until it is matched or flagged).
- The AP-to-GL reconciliation reads MATCHED (the opening-balance payable is shown as its own component); the home page shows the operational summary.

The accountant cannot approve or post (segregation of duties); the manager approves and posts. Re-running the seeder changes nothing.

## Demo receivables and revenue (OA3)

The same tenant also has the OA3 posting rules, the AR payment terms (shared with AP) and, relative to today:

- 4 customers (`LOGISTIK` with a credit limit shown for information, `RETAIL`, `TAMBANG`, `TOKO`).
- 7 customer invoices: 4 posted (one overdue and part-paid with a credit note, one fully paid, one with a receipt awaiting approval, one unpaid COD),
  1 submitted, 1 approved (waiting to post), 1 draft. Customer receipts: 2 posted, 1 submitted. 1 posted credit note (partial return).
- The AR aging shows CURRENT and 1-30 buckets; the AR-to-GL reconciliation reads MATCHED (the opening-balance receivable is its own component);
  the home page shows the receivables summary. The cash/bank-to-GL reconciliation counts the customer receipts as a document kind of its own.

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
OPTIENTRY_BOOTSTRAP_PASSWORD='<strong password>' php artisan optientry:bootstrap-platform-admin admin@your-company.com --name="Your Name"
```

Without the environment variable the command asks for the password (hidden). Running it again changes nothing.
