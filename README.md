# OptiAccounting

Double-entry accounting platform, delivered from one codebase as a multi-tenant
SaaS in the OptiNexus ecosystem or as a standalone product. Integration-ready
(OptiFleet-v2 first) through versioned events, never shared databases.

Status: **MASTER — architecture foundation**. No accounting features yet; see
`docs/architecture/ROADMAP.md` and `docs/status/`.

## Layout

```
backend/    Laravel 13 API (PHP 8.3, PostgreSQL, Redis)
frontend/   React 19 + TypeScript + Vite SPA
docker/     container definitions
docs/       architecture, owner phase briefs (specs), status checkpoints
```

## Run locally (without Docker)

Requirements: PHP 8.3 with `pdo_pgsql` and `intl`, Composer, Node 22, PostgreSQL 16, Redis 7.

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate   # set DB_* for your PostgreSQL
php artisan migrate --seed                          # production-safe seed only
php artisan serve                                   # http://localhost:8000/api/v1/health

cd ../frontend
npm install
npm run dev                                         # http://localhost:5173 (proxies /api)
```

Tests (real PostgreSQL database `optiaccounting_test`, see `backend/phpunit.xml`):

```bash
cd backend && php artisan test
cd frontend && npm run lint && npm run build
```

## Run with Docker

```bash
cp .env.example .env    # set APP_KEY
docker compose up -d --build
```

API on `http://localhost:8000`, SPA on `http://localhost:5173`.

## Seeders

- `php artisan db:seed` — production-safe (catalogs, permissions, templates; no tenants, no passwords).
- `php artisan db:seed --class=DemoSeeder` — demo data; logins in `docs/DEMO.md`.

## Identity mode

`OPTIACCOUNTING_IDENTITY_MODE=standalone` (default, local users/roles) or
`optinexus` (OptiNexus manages tenants, users, roles, permissions and events).
See `docs/architecture/SAAS_ARCHITECTURE.md`.
