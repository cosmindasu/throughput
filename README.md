# Throughput

An operational multi-tenant CRM for B2B distributors: accounts and contacts, a sales pipeline, orders, stock, invoicing. It is a portfolio demo with realistic data and real writes — any visitor can work inside the application, and the data resets nightly.

**Live demo:** [throughput.dbg.ro](https://throughput.dbg.ro) — one button to get in, no account and no password.

![The dashboard on the Marlin workspace: twelve months of revenue, the pipeline by stage, orders by status, and the "needs attention" list](docs/media/dashboard.gif)

*Eight seconds out of the ninety-second demo. The full recording is silent, with burned-in English subtitles: it is filmed automatically, by a Playwright choreography against a database seeded at full volume, not by hand.*

**Status:** the functionality is complete, covered by tests and checked by two internal code-versus-documentation audits (2026-09-23 and 2026-10-06), including the bilingual EN + FR interface ([ADR-022](docs/adr/ADR-022-locale-en-fr-per-utilizator-nu-in-url.md)). The demo runs live on Coolify, with a nightly reset, and `v1.0` is tagged. What remains is opening the repository to the public.

## What it demonstrates

- **Path-based multi-tenancy** (`/{workspace}/…`), with isolation in two layers: an Eloquent global scope and Row-Level Security in PostgreSQL. A leak between tenants requires both to fail at the same time ([ADR-002](docs/adr/ADR-002-tenancy-pe-cale.md), [ADR-003](docs/adr/ADR-003-izolare-tenant-doua-straturi.md), [ADR-014](docs/adr/ADR-014-context-de-tenant-o-singura-poarta.md)).
- **RBAC across four roles**, visible in the interface: every page receives a `can` computed server-side, and a button you have no right to is absent, not disabled.
- **Real volume**: 3 tenants, 8,000 accounts, 50,000 orders, 390 products, with cursor-paginated lists.
- **Concurrent writes handled explicitly**: order and invoice numbers without gaps, reserved stock under ordered locking, bulk operations idempotent per chunk, webhooks deduplicated on `event_id`.
- **Accessible interaction** (WCAG 2.2 AA): a kanban with drag & drop and a complete keyboard alternative, global search on Cmd+K, a dark/light theme rendered without a flash, and a contextual manual on every screen.

## Modules

| Module | What it covers |
|---|---|
| **CRM** | Accounts, contacts, deals with a configurable pipeline and a kanban, saved views, reorderable columns |
| **Catalogue & stock** | Products and variants, an append-only stock ledger ([ADR-004](docs/adr/ADR-004-stoc-registru-append-only.md)), receipt / adjustment / transfer, low-stock alerts |
| **Orders** | Draft → confirmation → partial fulfilment → shipment, with stock reservation and two carriers selectable per tenant ([ADR-010](docs/adr/ADR-010-doi-furnizori-curierat-configurabili.md)) |
| **Invoicing** | Invoices out of orders, partial payments, voiding with a reason, PDFs generated on a queue ([ADR-019](docs/adr/ADR-019-export-pdf-liste-dompdf-nu-chromium.md)); separate from the Stripe subscription ([ADR-005](docs/adr/ADR-005-facturare-separata-de-stripe.md)) |
| **CSV import** | Column mapping with suggestions, a dry run, a partial commit with a savepoint per row, and a re-importable error report |
| **Reports** | Definitions sourced from saved views or from built-in reports, scheduling by the hour, delivery by email ([ADR-009](docs/adr/ADR-009-resend-email-tranzactional.md)) |
| **Bulk operations** | Owner reassignment, price changes, discarding drafts — on a queue, in chunks, cancellable mid-run |
| **Export & GDPR** | List export (CSV / PDF / zip), a full workspace export for portability, contact anonymisation |
| **Administration** | Members and invitations ([ADR-011](docs/adr/ADR-011-dezactivare-membru-fara-blocare.md)), API tokens, subscription, the activity log ([ADR-007](docs/adr/ADR-007-audit-log-cod-propriu.md)), the email log, webhook health |
| **Public API** | REST versioned on the path ([ADR-008](docs/adr/ADR-008-versionare-api-pe-cale.md)), per-token scopes, a mandatory `Idempotency-Key` on writes, an OpenAPI 3.1 contract |
| **Internationalisation** | A complete EN/FR interface per user (`users.locale`, chosen from Settings → Preferences), with no locale in the URL ([ADR-022](docs/adr/ADR-022-locale-en-fr-per-utilizator-nu-in-url.md)); a CI gate (`i18n:coverage`) checks that the two catalogues stay symmetrical |

## Stack

Laravel 13 (PHP 8.3) · Inertia 3 · React 19 + TypeScript · Tailwind 4 · PostgreSQL 16 · Redis 7 + Horizon. The reasoning behind each choice is in [`docs/adr/`](docs/adr/README.md).

## Running it locally

```sh
cp .env.example .env
php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --database=pgsql_migrations
docker compose exec app php artisan demo:seed-volume
```

The application answers on `http://localhost:8000`, and Vite on `5173`. Why the migrations run on a separate connection, and why `artisan` commands run inside the container: [CONTRIBUTING.md](CONTRIBUTING.md).

## Demo accounts

With `DEMO_MODE=true` (the default in `.env.example`), the login page carries a "Log in as …" button for each role. No password is ever typed.

| Role | Email | What it sees |
|---|---|---|
| Owner | `demo.owner@throughput.dev` | Everything, billing included; switches between the three workspaces |
| Manager | `demo.manager@throughput.dev` | Full operational access, without billing and without carrier settings |
| Agent | `demo.agent@throughput.dev` | Their own accounts and deals by default, with a switch to the whole workspace |
| Viewer | `demo.viewer@throughput.dev` | Read-only, no action buttons; export stays allowed |

The showcase workspace is `marlin`; the other two, `cascade` and `northgate`, exist so that the switcher and the isolation can be seen.

In the demo, actions that would spoil the next visitor's session are hidden (deactivating a member, changing a role), and emails are intercepted and visible under Settings → Sent Emails. Stripe runs exclusively in test mode.

## Demo data

| Command | What it does |
|---|---|
| `php artisan demo:seed-volume` | The full set (~60 s) |
| `php artisan demo:seed-volume --scale=0.01` | The same set, under the same realism rules, at ~1% of the volume — for E2E and fast iteration |
| `php artisan demo:reset` | `migrate:fresh` plus the full set. In production it runs nightly at 03:00 UTC, as a job on Horizon ([ADR-017](docs/adr/ADR-017-reset-demo-ca-job-pe-horizon.md)) |
| `php artisan db:explain-critical` | `EXPLAIN ANALYZE` over the critical queries. It fails if one of them does a Seq Scan on a large table; global search has a time budget instead ([ADR-018](docs/adr/ADR-018-cautare-sub-rls-fara-index-trigram.md)) |

## Tests

- **Pest**, against real PostgreSQL, run as the application role (without `BYPASSRLS`): `APP_ENV=testing ./vendor/bin/pest`. Never SQLite — there is no RLS there, so every isolation test would pass while testing nothing. The test database is described in [CONTRIBUTING.md](CONTRIBUTING.md).
- Run `npm run build` before Pest: the application layout requires the Vite manifest.
- **Playwright**, against the real application with a queue worker running: `npx playwright test -c e2e/playwright.config.ts`. Pull requests run the `@smoke` subset; the full suite runs on `main` and on tags.
- **k6**: `tests/Performance/reads.js`, run locally, not in CI.

## CI

GitHub Actions, under strict rules about minute consumption: the Vite build runs once and is passed on as an artifact, expensive jobs sit behind cheap ones, and everything runs on `ubuntu-latest`. The reasoning is written into [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

## Documentation

- [`docs/adr/`](docs/adr/README.md) — the architecture decisions; a single source, and an accepted ADR is never rewritten.
- [CONTRIBUTING.md](CONTRIBUTING.md) — how work is done on the project.
- [`.ai/rules/`](.ai/rules/index.md) — the rules for coding agents: the traps already paid for, by area of the code.
