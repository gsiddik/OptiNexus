# OptiNexus — CGO Governance Core (Phase 1)

Central Governance & Orchestration platform for the OptiNexus ecosystem
(OptiFleet, OptiAccounting, VMS, Taxi Management, and future applications).
Phase 1 delivers the Governance Core: Customer, Tenant, Application
Registry, Capability Registry, Permission, Role, User/Identity, SSO
foundation, and Audit Log, exposed through a versioned REST API that
integrated applications consume for tenant validation, identity lookup,
and authorization decisions.

## Structure

```
backend/    Laravel 13 API (PHP 8.4, PostgreSQL, Sanctum + Passport)
frontend/   React 19 + TypeScript admin SPA (Vite)
docs/       OpenAPI 3.0 specification (docs/openapi.yaml)
```

## Backend setup

```bash
cd backend
composer install
cp .env.example .env        # set DB_* to a PostgreSQL database
php artisan key:generate
php artisan passport:keys
php artisan migrate --seed  # seeds system roles, cgo.* permissions,
                             # a bootstrap super admin, and OptiFleet demo data
php artisan serve
```

Seeded bootstrap super admin: `superadmin@cgo.local`. Set
`CGO_SUPERADMIN_PASSWORD` in `.env` before seeding outside local/testing;
locally it defaults to `ChangeMe!12345`.

Run the test suite (uses a real PostgreSQL database - see `phpunit.xml`):

```bash
php artisan test
```

Simulate an external application (OptiFleet) integrating end-to-end over
real HTTP - client-credentials auth, an authorized check, a cross-tenant
denial, and an audit event submission:

```bash
php artisan cgo:simulate-integration
```

## Frontend setup

```bash
cd frontend
npm install
npm run dev   # expects the backend at VITE_API_BASE_URL (see .env.development)
```

## API documentation

`docs/openapi.yaml` is the OpenAPI 3.0 contract for every Phase 1 endpoint,
including request/response schemas, error codes, and both authentication
mechanisms (Sanctum bearer tokens for humans, OAuth2 client-credentials
for integrated applications). View it with any OpenAPI viewer (e.g.
[Swagger Editor](https://editor.swagger.io)) or import it into Postman/Insomnia.

## Authentication model

- **Human users** (admin SPA): `POST /api/v1/auth/login` → Sanctum bearer token.
- **Integrated applications** (M2M): `POST /api/v1/oauth/token` (OAuth2
  client-credentials grant) using credentials issued via
  `POST /api/v1/service-accounts`.

## Key integration endpoints

- `POST /api/v1/authorization/check` — ask CGO whether an identity may
  perform an action (tenant/application/role/permission/scope evaluated
  server-side).
- `GET /api/v1/auth/context` — the authenticated user's effective
  governance context (tenants, roles, permissions).
- `POST /api/v1/audit-events` — trusted applications submit audit events;
  the actor is derived from the authenticated service account, never the
  request body.
