# Cum se lucrează pe Throughput

Regulile de mai jos nu sunt preferințe de stil. Fiecare e ori o consecință a unui
[ADR acceptat](docs/adr/README.md), ori un bug reprodus o dată și scris aici ca să nu
se repete. Code review respinge un PR care le încalcă, chiar dacă funcționează.

Deciziile arhitecturale sunt în [`docs/adr/`](docs/adr/README.md) și sunt **autoritate**:
nimic din cod sau din documentație nu le poate contrazice. Dacă o decizie trebuie
schimbată, se scrie un ADR nou care îl supersedează pe cel vechi — un ADR acceptat nu
se rescrie.

---

## Pornire locală

```sh
cp .env.example .env
php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --database=pgsql_migrations
```

`docker compose up` trebuie să pornească tot stack-ul în **sub 2 minute**. Aplicația
răspunde pe `http://localhost:8000`, Vite pe `5173`.

Comenzile `artisan` se rulează **în container** (`docker compose exec app ...`), nu pe
gazdă: `DB_HOST=postgres` și `REDIS_HOST=redis` sunt nume de servicii Docker, nu se
rezolvă din afara rețelei compose.

### De ce migrațiile au nevoie de `--database=pgsql_migrations`

Există două conexiuni către aceeași bază ([ADR-003](docs/adr/ADR-003-izolare-tenant-doua-straturi.md)):

| Conexiune | Rol Postgres | `BYPASSRLS` | Cine o folosește |
|---|---|---|---|
| `pgsql` | `throughput_app` | **nu** | Fiecare request HTTP și fiecare job de coadă |
| `pgsql_migrations` | `throughput_migrator` | **da** | Doar `artisan migrate` și comenzi de întreținere |

Migrațiile rulează pe a doua, pentru că rolul care creează tabelele trebuie să fie și
proprietarul lor (de aici clauza `ALTER DEFAULT PRIVILEGES FOR ROLE throughput_migrator`
din `docker/postgres/init/01-roles-and-extensions.sh`). Un request care ar folosi
`pgsql_migrations` ar anula complet stratul 2 de izolare — `BYPASSRLS` vede toți tenanții.

---

## Reguli absolute

### 1. Niciun apel extern într-un controller

[ADR-013](docs/adr/ADR-013-apeluri-externe-in-cozi.md). Middleware-ul de context ține o
**tranzacție deschisă pe toată durata cererii**, deci orice apel către un serviciu
extern — curierat, Stripe, Chromium pentru PDF, orice — ține tranzacția deschisă cât
durează rețeaua. Dacă o acțiune apelează altceva decât baza de date proprie, acțiunea
aparține unei cozi.

Încălcarea nu dă eroare. Dă tranzacții lungi care se văd abia sub concurență.

### 2. `after_commit` e pornit și rămâne pornit

[ADR-014](docs/adr/ADR-014-context-de-tenant-o-singura-poarta.md), punctul 6. Fiindcă
fiecare cerere rulează într-o tranzacție, **fiecare `dispatch()` se întâmplă într-o
tranzacție**. Cu `'after_commit' => false`, worker-ul poate ridica jobul înainte de
commit și să nu găsească rândul pe care jobul îl caută: eșecuri intermitente exact la
eticheta de curierat și la operațiile în masă, unde interfața face polling pe un rând
care încă nu există.

### 3. Contextul de tenant se setează într-un singur loc

`App\Services\Tenancy\TenantContext` e **singura poartă**. Nu se apelează `set_config`
din altă parte, nu se „mai adaugă un `SET LOCAL`, doar aici". Vezi
[ADR-014](docs/adr/ADR-014-context-de-tenant-o-singura-poarta.md) pentru ce s-a stricat
când mecanismul era împrăștiat.

### 4. Niciun cod de culoare literal într-o componentă

Sistemul de design e un set de tokens pe două teme, cu contrastul **măsurat** pe fiecare
token × fiecare suprafață pe care poate apărea. Trei valori au fost urcate după audit
exact pentru că variantele evidente cădeau sub prag pe una din suprafețe.

Dacă un ecran are nevoie de o nuanță care nu e token, **se adaugă tokenul**, nu excepția.
Corolar: nicio culoare nu se definește doar într-un bloc de temă — ambele seturi se
declară complet, iar componentele citesc doar tokens.

Mono (`IBM Plex Mono`) numai pe cifre și identificatori, niciodată pe proză. Orice
coloană de sume sau cantități are `font-variant-numeric: tabular-nums`.

### 5. Culoarea nu e niciodată singurul purtător de sens

SC 1.4.1. Fiecare chip de stare are și etichetă text, fiecare deltă de KPI are și semn.

---

## Props Inertia — contractul

Cele șapte reguli de mai jos sunt verificate la code review. ADR-001 semnala explicit
riscul: *„Inertia cere convenții clare pentru props; fără ele, controllerele devin API
deghizat."*

1. **Niciun controller nu trece un model sau o colecție Eloquent brută în
   `Inertia::render()`.** Trece mereu printr-o clasă `JsonResource` din
   `app/Http/Resources/`. Un model brut expune coloane necontrolat — inclusiv
   `variants.cost`, care trebuie ascuns pentru rolurile Agent și Viewer. Resource-ul e
   locul unde se decide ce iese, nu convenția „sper că React nu randează câmpul ăla".

2. **Fiecare pagină primește `can: Record<string, boolean>` calculat server-side**,
   niciodată recalculat în React din rolul brut al utilizatorului.

3. **Props comune vin dintr-un singur loc** — `HandleInertiaRequests::share()`:
   `auth.user`, `workspace` (curent), `workspaces` (lista pentru comutator), `flash`,
   `demoMode`, `theme`. Niciun controller nu le repetă manual.

4. **Numele paginii Inertia = calea fișierului React, 1:1 cu metoda resourceful a
   controllerului**: `Accounts/Index`, `Accounts/Show`, `Deals/Kanban`.

5. **Orice schimbare de formă a unui Resource se reflectă imediat în
   `resources/js/types/generated.d.ts`.** Nu există generare automată în MVP — ar adăuga
   o dependință nevalidată. În schimb, fiecare pagină critică are un test de contract:

   ```php
   $response->assertInertia(fn (Assert $page) => $page
       ->component('Deals/Show')
       ->has('deal.id')
       ->has('can.edit')
       ->has('can.delete')
       ->has('can.move_stage')
   );
   ```

   Un PR care schimbă un Resource fără să actualizeze testul de contract corespunzător
   se respinge.

6. **Filtrele, sortarea și paginarea vin din query string**, parsate server-side într-un
   value object comun (`App\Support\ListQuery`), reutilizat de vizualizările salvate și
   de export. Nu se parsează de două ori, în două forme.

7. **Paginare pe cursor peste tot unde există volum potențial mare** (`cursorPaginate()`)
   — niciodată `paginate()` clasic pe `accounts`, `contacts`, `deals`, `orders`,
   `stock_movements`, `activity_log` (FR-PERF-03).

---

## Convenții de naming

| Element | Convenție | Exemplu |
|---|---|---|
| Migrații | `YYYY_MM_DD_HHMMSS_create_xxx_table.php` | `2026_09_15_090000_create_accounts_table.php` |
| Modele Eloquent | Singular, PascalCase | `Account`, `DealStageEvent` |
| Rute web | kebab-case, cu segment de workspace | `/{workspace}/accounts/{account}` |
| Rute API | kebab-case, sub `/api/v1` (fără segment de workspace) | `/api/v1/orders/{order}` |
| Componente React | PascalCase, un fișier = o pagină/componentă | `resources/js/Pages/Accounts/Index.tsx` |
| Acțiuni de business | `{Verb}{Noun}Action`, o singură metodă publică `execute()` | `ConfirmOrderAction` |
| Permisiuni | `{resursă}.{acțiune}`, punct, nu două puncte | `deals.edit`, `billing.manage` |
| Cozi | `default`, `bulk`, `imports`, `reports` | — |
| Branch-uri | `feature/xxx`, `fix/xxx`, `chore/xxx` | `feature/deal-stage-events` |
| Commit-uri | [Conventional Commits](https://www.conventionalcommits.org/) | `feat(deals): add stage transition ledger` |

**Trunk-based**, un singur branch lung-trăitor (`main`) + branch-uri de feature scurte
(sub 2 zile). Fără `develop`: proiect solo, o ramură lungă în plus ar dubla degeaba
suprafața de CI. Commituri mici, revizuibile în sub 30 de minute.

---

## Migrații

- Fiecare `up()` are un `down()` **funcțional, verificat** — `migrate:rollback` trebuie
  să ruleze curat.
- `tenant_id` e mereu **coloana de lider** în indexurile compuse ale tabelelor
  tenant-scoped. Un index care începe cu altceva e inutil sub RLS.
- Seed-ul e sursa de adevăr a datelor de dezvoltare. Nimeni nu inserează rânduri de test
  prin Tinker în afara `database/seeders/`.

## Testare

Suita rulează pe **PostgreSQL**, nu pe SQLite in-memory, și asta nu e negociabil: pe
SQLite nu există Row-Level Security și nu există cele două roluri din ADR-003, deci
testele de izolare de tenant (FR-TEST-01/02/03) ar trece verde **fără să testeze nimic**.
`phpunit.xml` are un comentariu în locul liniilor pe care le pusese scaffold-ul.

Test-first pe logica critică: calcul de totaluri comandă, `available` din stoc,
`duration_in_previous_stage_seconds`, izolare de tenant, webhook-uri Stripe.

## `typescript` e pinat la 6.0.3 — nu-l ridica accidental

`package.json` cere `"typescript": "6.0.3"` exact, fără `^`. Nu e neglijență: la
2026-09-12, `typescript-eslint` (8.70.0, ultima versiune) declară
`peerDependencies.typescript: ">=4.8.4 <6.1.0"` și **aruncă eroare la `require()`** pe
orice TS ≥ 7 — adică `npx eslint resources/js` nu mai pornește deloc, iar CI-ul cade pe
jobul `assets`. `npm overrides` nu rezolvă: npm refuză nesting-ul la conflictul dintre
cele două `devDependencies`.

Alegerea a fost lint complet pe `.tsx` (inclusiv regulile `react-hooks` — dependențe
lipsă în `useEffect`, apeluri condiționate de hook-uri) în locul compilatorului nou.
Se ridică la TS 7 într-un singur bump, **după** ce `typescript-eslint` îl suportă;
până atunci, `npm update typescript` strică lint-ul.

## Înainte de push

```sh
./vendor/bin/pint            # formatare (CI rulează `pint --test`)
./vendor/bin/pest
npx tsc --noEmit
npx eslint resources/js
npx --yes @stoplight/spectral-cli lint openapi/throughput-v1.yaml
```

CI-ul rulează exact aceste comenzi, plus `npm run build` și `composer audit`. Cota de
minute GitHub Actions e comună cu alte 11 proiecte, deci un run picat pe formatare e
minute cumpărate degeaba: rulează-le local.

## Documentație

| Ce | Unde | Notă |
|---|---|---|
| Decizii arhitecturale | [`docs/adr/`](docs/adr/README.md) | În repo, sursă unică de adevăr. MADR 3.0. |
| Specificație funcțională, plan de implementare, sistem de design | în afara repo-ului, la proprietar | Nu se copiază aici: două seturi care evoluează separat sunt exact deriva care a costat trei runde de remediere pe acest proiect. |
| Manual contextual din aplicație | `resources/js/help/` | Conținut versionat în repo, un subiect per ecran, scris în faza care construiește ecranul. |

Contractul API (`openapi/throughput-v1.yaml`) se scrie **de mână, înaintea**
endpoint-ului, nu generat din cod după. CI îl validează la fiecare push.
