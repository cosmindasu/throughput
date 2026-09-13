# Architecture Decision Records — Throughput

Acest director conține **Architecture Decision Records (ADR)** pentru proiectul Throughput, în format **MADR 3.0** (Markdown Any Decision Records).

Fiecare ADR documentează o decizie arhitecturală cu impact pe termen lung — **context, opțiuni considerate, decizia luată, consecințe**. Sunt referință autoritară pentru deciziile tehnice ale proiectului.

## Index ADR-uri

| ADR | Titlu | Status | Data | Sprint țintă |
|---|---|---|---|---|
| [ADR-001](ADR-001-stack-tehnic.md) | Stack tehnic — Laravel + Inertia + React + PostgreSQL (față de Next.js / Filament / Livewire) | Accepted · versiunile superseded de [ADR-015](ADR-015-laravel-13-si-inertia-3.md) | 2026-09-12 | Sprint 0 |
| [ADR-002](ADR-002-tenancy-pe-cale.md) | Multi-tenancy pe cale (workspace slug), nu pe subdomeniu | Accepted | 2026-09-12 | Sprint 1 |
| [ADR-003](ADR-003-izolare-tenant-doua-straturi.md) | Izolarea tenanților în două straturi — global scope + Row-Level Security | Accepted | 2026-09-12 | Sprint 1 |
| [ADR-004](ADR-004-stoc-registru-append-only.md) | Stocul ca registru append-only, nu ca o cantitate mutabilă | Accepted | 2026-09-12 | Sprint 3 |
| [ADR-005](ADR-005-facturare-separata-de-stripe.md) | Facturarea către clienți e separată de abonamentul Stripe | Accepted | 2026-09-12 | Sprint 4 |
| [ADR-006](ADR-006-cashier-16-pentru-abonament.md) | Laravel Cashier 16 pentru abonamentul tenantului | Accepted | 2026-09-12 | Sprint 4 |
| [ADR-007](ADR-007-audit-log-cod-propriu.md) | Jurnal de activitate cu cod propriu, nu `owen-it/laravel-auditing` | Accepted | 2026-09-12 | Sprint 5 |
| [ADR-008](ADR-008-versionare-api-pe-cale.md) | Versionarea API-ului public pe cale (`/api/v1/...`) | Accepted | 2026-09-12 | Sprint 5 |
| [ADR-009](ADR-009-resend-email-tranzactional.md) | Resend ca furnizor de email tranzacțional | Accepted | 2026-09-12 | Sprint 4 |
| [ADR-010](ADR-010-doi-furnizori-curierat-configurabili.md) | Doi furnizori de curierat, selectabili per tenant, plus unul de demonstrație | Accepted | 2026-09-12 | Sprint 5 |
| [ADR-011](ADR-011-dezactivare-membru-fara-blocare.md) | Dezactivarea unui membru nu e blocată de înregistrările pe care le deține | Accepted | 2026-09-12 | Faza 2 |
| [ADR-012](ADR-012-retentie-30-zile-post-anulare.md) | Fereastră de retenție de 30 de zile după anularea abonamentului | Accepted | 2026-09-12 | Faza 5 |
| [ADR-013](ADR-013-apeluri-externe-in-cozi.md) | Apelurile externe ies din cererea HTTP, în cozi | Accepted | 2026-09-12 | Faza 5 |
| [ADR-014](ADR-014-context-de-tenant-o-singura-poarta.md) | Contextul de tenant — o singură poartă, două variabile de sesiune, politică proprie pentru `memberships` | Accepted · forma SQL din pct. 2 superseded parțial de [ADR-016](ADR-016-cast-rls-pe-setare-nu-pe-coloana.md) | 2026-09-12 | Faza 1 |
| [ADR-015](ADR-015-laravel-13-si-inertia-3.md) | Laravel 13 și Inertia 3, nu Laravel 12 și Inertia 2 — supersedează versiunile din [ADR-001](ADR-001-stack-tehnic.md) | Accepted | 2026-09-12 | Sprint 0 |
| [ADR-016](ADR-016-cast-rls-pe-setare-nu-pe-coloana.md) | Politicile RLS pun cast-ul pe setare, nu pe coloană — supersedează parțial forma SQL din [ADR-014](ADR-014-context-de-tenant-o-singura-poarta.md) | Accepted | 2026-09-12 | Faza 1 |
| [ADR-017](ADR-017-reset-demo-ca-job-pe-horizon.md) | Resetul zilnic al demo-ului rulează ca job pe Horizon, nu în containerul `scheduler` | Accepted | 2026-09-13 | Faza 2 |
| [ADR-018](ADR-018-cautare-sub-rls-fara-index-trigram.md) | Căutarea globală sub RLS filtrează pe rândurile tenantului, fără indexuri GIN trigram | Accepted | 2026-09-13 | Faza 2 |

## Convenții

- **Format**: MADR 3.0 (<https://adr.github.io/madr/>)
- **Numerotare**: ADR-XXX (3 cifre, cresc monoton)
- **Status**: `Proposed` → `Accepted` → `Deprecated` / `Superseded by ADR-YYY`
- **Un ADR acceptat nu se rescrie.** Dacă decizia se schimbă, se scrie unul nou care îl supersedează, iar cel vechi primește statusul corespunzător.
- **Legături**: `[[ADR-XXX]]` în corpul documentului.

## Decizii încă neluate

Toate deciziile deschise de la 2026-09-12 au fost luate (ADR-005…015). Se scriu ca ADR când apar altele noi, nu înainte.

Rămâne de reevaluat pe parcurs:

- **Unificarea generării de PDF** — [[ADR-006]] folosește `spatie/laravel-pdf` pentru facturile de abonament; dacă facturile către clienți ([[ADR-005]]) ajung pe alt mecanism, se unifică.
- ~~**Amânarea celei de-a doua integrări de curierat**~~ — **rezolvat la 2026-09-12: supapa a fost trasă.** EasyPost condiționează accesul la cheile de API, inclusiv cele de test, de un abonament lunar, deci adaptorul iese din MVP. Rămân `demo` + `shippo`; interfața, testele de contract și ecranul de setări per tenant nu se schimbă. Vezi nota din capul lui [[ADR-010]].
