# Architecture Decision Records — Throughput

This directory holds the **Architecture Decision Records (ADRs)** for the Throughput project, in **MADR 3.0** format (Markdown Any Decision Records).

Each ADR documents one architectural decision with long-term impact — **context, options considered, the decision taken, consequences**. They are the authoritative reference for the project's technical decisions.

## ADR index

| ADR | Title | Status | Date | Target sprint |
|---|---|---|---|---|
| [ADR-001](ADR-001-stack-tehnic.md) | Technical stack — Laravel + Inertia + React + PostgreSQL (over Next.js / Filament / Livewire) | Accepted · versions superseded by [ADR-015](ADR-015-laravel-13-si-inertia-3.md) | 2026-09-12 | Sprint 0 |
| [ADR-002](ADR-002-tenancy-pe-cale.md) | Path-based multi-tenancy (workspace slug), not subdomain-based | Accepted | 2026-09-12 | Sprint 1 |
| [ADR-003](ADR-003-izolare-tenant-doua-straturi.md) | Tenant isolation in two layers — global scope + Row-Level Security | Accepted | 2026-09-12 | Sprint 1 |
| [ADR-004](ADR-004-stoc-registru-append-only.md) | Stock as an append-only ledger, not as a mutable quantity | Accepted | 2026-09-12 | Sprint 3 |
| [ADR-005](ADR-005-facturare-separata-de-stripe.md) | Customer invoicing is separate from the Stripe subscription | Accepted | 2026-09-12 | Sprint 4 |
| [ADR-006](ADR-006-cashier-16-pentru-abonament.md) | Laravel Cashier 16 for the tenant subscription | Accepted · the sentence on PDF rendering partially superseded by [ADR-021](ADR-021-factura-abonament-dompdf-nu-spatie-laravel-pdf.md) | 2026-09-12 | Sprint 4 |
| [ADR-007](ADR-007-audit-log-cod-propriu.md) | Activity log written in-house, not `owen-it/laravel-auditing` | Accepted | 2026-09-12 | Sprint 5 |
| [ADR-008](ADR-008-versionare-api-pe-cale.md) | Public API versioned in the path (`/api/v1/...`) | Accepted | 2026-09-12 | Sprint 5 |
| [ADR-009](ADR-009-resend-email-tranzactional.md) | Resend as the transactional email provider | Accepted | 2026-09-12 | Sprint 4 |
| [ADR-010](ADR-010-doi-furnizori-curierat-configurabili.md) | Two shipping carriers, selectable per tenant, plus a demo one | Accepted | 2026-09-12 | Sprint 5 |
| [ADR-011](ADR-011-dezactivare-membru-fara-blocare.md) | Deactivating a member is never blocked by the records they own | Accepted | 2026-09-12 | Phase 2 |
| [ADR-012](ADR-012-retentie-30-zile-post-anulare.md) | A 30-day retention window after subscription cancellation | Accepted | 2026-09-12 | Phase 5 |
| [ADR-013](ADR-013-apeluri-externe-in-cozi.md) | External calls leave the HTTP request and move to queues | Accepted | 2026-09-12 | Phase 5 |
| [ADR-014](ADR-014-context-de-tenant-o-singura-poarta.md) | Tenant context — a single gate, two session variables, a dedicated policy for `memberships` | Accepted · the SQL form in point 2 partially superseded by [ADR-016](ADR-016-cast-rls-pe-setare-nu-pe-coloana.md) | 2026-09-12 | Phase 1 |
| [ADR-015](ADR-015-laravel-13-si-inertia-3.md) | Laravel 13 and Inertia 3, not Laravel 12 and Inertia 2 — supersedes the versions in [ADR-001](ADR-001-stack-tehnic.md) | Accepted | 2026-09-12 | Sprint 0 |
| [ADR-016](ADR-016-cast-rls-pe-setare-nu-pe-coloana.md) | RLS policies cast the setting, not the column — partially supersedes the SQL form in [ADR-014](ADR-014-context-de-tenant-o-singura-poarta.md) | Accepted | 2026-09-12 | Phase 1 |
| [ADR-017](ADR-017-reset-demo-ca-job-pe-horizon.md) | The daily demo reset runs as a job on Horizon, not inside the `scheduler` container | Accepted | 2026-09-13 | Phase 2 |
| [ADR-018](ADR-018-cautare-sub-rls-fara-index-trigram.md) | Global search under RLS filters over the tenant's rows, without GIN trigram indexes | Accepted | 2026-09-13 | Phase 2 |
| [ADR-019](ADR-019-export-pdf-liste-dompdf-nu-chromium.md) | List PDF export (Orders) with DomPDF, not with Chromium in the image | Accepted | 2026-09-14 | Phase 3 |
| [ADR-020](ADR-020-politica-rls-proprie-pentru-jurnalul-de-email.md) | A dedicated RLS policy for the email log, with an optional tenant | Accepted | 2026-09-19 | Phase 4 |
| [ADR-021](ADR-021-factura-abonament-dompdf-nu-spatie-laravel-pdf.md) | The subscription invoice stays on `DompdfInvoiceRenderer`, the Cashier default — not on `spatie/laravel-pdf` — partially supersedes [ADR-006](ADR-006-cashier-16-pentru-abonament.md) | Accepted | 2026-09-19 | Phase 5 |
| [ADR-022](ADR-022-locale-en-fr-per-utilizator-nu-in-url.md) | The interface becomes bilingual (EN default + FR), language is a per-user preference (`users.locale`) — not a URL segment — amends [ADR-002](ADR-002-tenancy-pe-cale.md) | Accepted | 2026-09-20 | after Phase 5 |

## Conventions

- **Format**: MADR 3.0 (<https://adr.github.io/madr/>)
- **Numbering**: ADR-XXX (3 digits, monotonically increasing)
- **Status**: `Proposed` → `Accepted` → `Deprecated` / `Superseded by ADR-YYY`
- **An accepted ADR is never rewritten.** If the decision changes, a new one is written that supersedes it, and the old one gets the matching status.
- **Links**: `[[ADR-XXX]]` in the body of the document.

## Decisions still open

Every decision left open on 2026-09-12 has been taken (ADR-005…015). New ones are written as ADRs when they come up, not before.

Still to be re-evaluated along the way:

- ~~**Unifying PDF generation**~~ — **closed on 2026-09-19, but not by unification: by correction.** The premise was wrong — [[ADR-006]] claimed that subscription invoices use `spatie/laravel-pdf`; the installed code shows that the renderer actually in effect, the Cashier 16 default, is `DompdfInvoiceRenderer`. [[ADR-021]] explicitly confirms staying on the Cashier default and documents, as a deliberate decision, the coexistence of two entry points into DomPDF: Cashier's own and `spatie/laravel-pdf` with an explicit driver (customer invoices — [[ADR-005]] — and exports/reports — [[ADR-019]]). See the note at the top of [[ADR-021]].
- ~~**Deferring the second shipping integration**~~ — **resolved on 2026-09-12: the escape valve was pulled.** EasyPost gates access to API keys, test keys included, behind a monthly subscription, so the adapter drops out of the MVP. `demo` + `shippo` remain; the interface, the contract tests and the per-tenant settings screen do not change. See the note at the top of [[ADR-010]].
