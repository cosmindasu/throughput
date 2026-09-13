# Throughput

CRM operațional multi-tenant pentru distribuitori B2B: conturi și contacte, pipeline de vânzări, comenzi, stoc, facturare. E un demo de portofoliu cu date realiste și scriere reală — orice vizitator poate lucra în aplicație, iar datele se resetează zilnic.

**Stare:** în dezvoltare. Faza 2 (CRM, pipeline, kanban, căutare globală) e în curs; publicarea e la finalul ei.

## Ce demonstrează

- **Multi-tenancy pe cale** (`/{workspace}/…`), cu izolare în două straturi: global scope Eloquent și Row-Level Security în PostgreSQL. O scurgere între tenanți cere ca ambele să greșească simultan ([ADR-002](docs/adr/ADR-002-tenancy-pe-cale.md), [ADR-003](docs/adr/ADR-003-izolare-tenant-doua-straturi.md), [ADR-014](docs/adr/ADR-014-context-de-tenant-o-singura-poarta.md)).
- **RBAC pe patru roluri**, vizibil în interfață: fiecare pagină primește `can` calculat server-side, iar un buton fără drept lipsește, nu e dezactivat.
- **Volum real**: 3 tenanți, 8.000 de conturi, 50.000 de comenzi, 24 de luni de istoric, cu liste paginate pe cursor.
- **Interacțiune accesibilă**: kanban cu drag & drop și alternativă completă de tastatură, căutare globală Cmd+K, temă închisă/deschisă randată fără licărire, manual contextual pe fiecare ecran.

## Stack

Laravel 13 (PHP 8.3) · Inertia 3 · React 19 + TypeScript · Tailwind 4 · PostgreSQL 16 · Redis 7 + Horizon. Motivele fiecărei alegeri sunt în [`docs/adr/`](docs/adr/README.md).

## Pornire locală

```sh
cp .env.example .env
php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --database=pgsql_migrations
docker compose exec app php artisan demo:seed-volume
```

Aplicația răspunde pe `http://localhost:8000`, iar Vite pe `5173`. De ce migrațiile rulează pe o conexiune separată și de ce comenzile `artisan` rulează în container: [CONTRIBUTING.md](CONTRIBUTING.md).

## Conturi demo

Cu `DEMO_MODE=true` (implicit în `.env.example`), pagina de login are câte un buton „Log in as …" pentru fiecare rol. Nu se tastează nicio parolă.

| Rol | Email | Ce vede |
|---|---|---|
| Owner | `demo.owner@throughput.dev` | Tot, inclusiv billing; comută între cele trei workspace-uri |
| Manager | `demo.manager@throughput.dev` | Acces operațional complet, fără billing |
| Agent | `demo.agent@throughput.dev` | Implicit doar conturile și deals-urile proprii, cu comutare la tot workspace-ul |
| Viewer | `demo.viewer@throughput.dev` | Doar citire, fără butoane de acțiune; exportul rămâne permis |

Workspace-ul vitrină e `marlin`.

## Date demo

| Comandă | Ce face |
|---|---|
| `php artisan demo:seed-volume` | Setul complet (~60 s) |
| `php artisan demo:seed-volume --scale=0.01` | Același set, cu aceleași reguli de realism, la ~1% din volum — pentru E2E și iterație rapidă |
| `php artisan demo:reset` | `migrate:fresh` + setul complet. În producție rulează zilnic, la 03:00 UTC, ca job pe Horizon ([ADR-017](docs/adr/ADR-017-reset-demo-ca-job-pe-horizon.md)) |
| `php artisan db:explain-critical` | `EXPLAIN ANALYZE` pe interogările critice. Pică dacă una face Seq Scan pe o tabelă mare; căutarea globală are în schimb un buget de timp ([ADR-018](docs/adr/ADR-018-cautare-sub-rls-fara-index-trigram.md)) |

## Teste

- **Pest**, pe PostgreSQL real, rulat cu rolul aplicației (fără `BYPASSRLS`): `APP_ENV=testing ./vendor/bin/pest`. Niciodată SQLite — acolo RLS nu există, deci testele de izolare ar trece fără să testeze nimic. Baza de test e descrisă în [CONTRIBUTING.md](CONTRIBUTING.md).
- Rulează `npm run build` înainte de Pest: layout-ul aplicației cere manifestul Vite.
- **k6**: `tests/Performance/reads.js`, rulat local, nu în CI.

## CI

GitHub Actions, cu reguli stricte de consum de minute: build-ul Vite o singură dată, transmis ca artefact, joburile scumpe în spatele celor ieftine, doar `ubuntu-latest`. Motivele sunt scrise în [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

## Documentație

- [`docs/adr/`](docs/adr/README.md) — deciziile arhitecturale; sursă unică, un ADR acceptat nu se rescrie.
- [CONTRIBUTING.md](CONTRIBUTING.md) — cum se lucrează pe proiect.
- [`.ai/rules/`](.ai/rules/index.md) — regulile pentru agenții de cod: capcanele deja plătite, pe zone de cod.
