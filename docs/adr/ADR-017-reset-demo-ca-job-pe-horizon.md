# ADR-017: Resetul zilnic al demo-ului rulează ca job pe Horizon, nu în containerul `scheduler`

- **Status**: Accepted
- **Data**: 2026-09-13
- **Decidenți**: proprietarul proiectului
- **Related**: [[ADR-001]], [[ADR-014]]

## Context și problema

FR-DEMO-03 (specs.md §22.1) cere ca demo-ul public să fie readus zilnic, la 03:00 UTC, la setul semănat, activ de la publicare (finalul Fazei 2). Planul (§8) îl descria ca „o linie în `routes/console.php`" peste comanda `demo:reset`, existentă din Faza 1: `migrate:fresh` pe conexiunea de migrare, apoi seed-ul complet (3 tenanți, 8.000 de conturi, 50.000 de comenzi).

`Schedule::command()` nu trimite comanda nicăieri. O rulează ca subproces **în containerul care rulează scheduler-ul**, adică serviciul `scheduler` din `docker-compose.coolify.yml`: `mem_limit: 128m`, descris în plan §3.1 ca „doar dispecerizează — nu execută el însuși job-uri grele". Imaginea păstrează `memory_limit` PHP implicit, 128M.

Durata comenzii era cunoscută din Faza 1 (~57 s). Vârful ei de memorie nu fusese măsurat niciodată.

## Drivers de decizie

- **Măsurat, nu estimat.** Un reset care moare la jumătate e mai rău decât niciun reset: `migrate:fresh` a șters deja schema, iar demo-ul public rămâne gol până la rularea următoare.
- **Bugetul de memorie al VPS-ului partajat** (`.ai/rules/project.md`: 250–400 MB la vârf, 11 ucideri OOM măsurate). De preferat fără plafoane noi.
- **Scheduler-ul rămâne dispecer**, cum îl descrie planul.
- **Testabil în CI**, nu configurare manuală în afara repo-ului.

## Măsurătoarea

Mediu: PHP 8.4 CLI local, PostgreSQL 16.14, bază dedicată `throughput_reset_probe` (ca măsurătoarea să nu perturbe baza de dev), seed complet. Runtime-ul real e PHP 8.3 într-un container Linux: cifrele absolute pot diferi, ordinul de mărime nu.

| Proces | Heap PHP de vârf | RSS max | Durată |
|---|---|---|---|
| `demo:reset`, înainte de fixul de seed | 206,5 MB | 208 MB | 59 s |
| `demo:reset`, după fixul de seed (`0772c63`), rulat cu `memory_limit=128M` | 80,5 MB | 90,2 MB | 67,7 s¹ |
| `schedule:work`, inactiv | — | 63,2 MB | — |
| `schedule:run`, o trecere fără nimic scadent | — | 63,2 MB | — |

¹ Pe o mașină încărcată în paralel de alte procese; fără încărcare, ~57 s (Faza 1).

**Fixul de seed.** Rezumatele celor 30.000 de comenzi ale tenantului Marlin țineau câte două obiecte Carbon pe rând până la facturare: 134,6 MB, față de 16,1 MB cu timestamp-uri întregi (măsurat izolat). După fix, datele au rămas echivalente: 0 facturi cu `issue_date` diferit de data comenzii, 0 comenzi sau deals mai vechi decât contul, aceleași volume.

**Chiar după fix**, la 03:00 containerul `scheduler` ar ține simultan `schedule:work` (63 MB), `schedule:run`-ul pornit în acel minut (63 MB) și resetul (90 MB): ~216 MB pe un plafon de 128m.

## Opțiuni considerate

1. **`Schedule::command('demo:reset')` direct în `scheduler`, plafon neschimbat.** Varianta din plan. Nu încape nici măcar resetul singur lângă `schedule:work`. Respinsă.
2. **Aceeași intrare, cu `scheduler` urcat la 256m.** Cea mai simplă. Dar ridică plafonul unui serviciu pe un VPS care a avut deja ucideri OOM, contrazice rolul de simplu dispecer, iar la ~216 MB măsurați ar fi rămas oricum la limită. Respinsă.
3. **Scheduled Task în Coolify, în containerul `app` (256m).** Fără cod. Dar configurarea stă în afara repo-ului, nu e testabilă în CI, iar `DEMO_RESET_CRON` ar rămâne nefolosit. Respinsă.
4. **Scheduler-ul dispecerizează `ResetDemoDataJob`, iar resetul rulează în `horizon` (384m, deja bugetat).** **Aleasă.**

## Decizia

- `routes/console.php`: `Schedule::job(new ResetDemoDataJob, 'default')->cron(config('throughput.demo.reset_cron'))->when(DemoMode::enabled)`.
- `ResetDemoDataJob` e un job de sistem, fără tenant ([[ADR-014]]):
  - `tries = 1`, `timeout = 600`, `failOnTimeout`;
  - `ShouldBeUnique` pe 15 minute;
  - reverifică `DEMO_MODE` la execuție;
  - un cod de ieșire nenul aruncă excepție, cu ieșirea comenzii în mesaj.
- `queue.connections.redis.retry_after` urcă de la 90 la **900** s, peste timeout-ul jobului. Altfel Redis ar pune resetul înapoi în coadă cât încă rulează.
- Fixul de memorie din seed (`0772c63`) e precondiție: fără el, resetul nu încape nici în `memory_limit` 128M al worker-ului Horizon.

## Consecințe

### Pozitive

- Niciun plafon de memorie nu se schimbă. Resetul folosește ~90 MB din cei 384m ai `horizon`, la 03:00, când coada e liniștită.
- Scheduler-ul rămâne dispecer. Intrarea și contractul jobului sunt acoperite de `DemoResetScheduleTest`: cron-ul, filtrul DEMO_MODE, eșecul zgomotos, `retry_after` peste timeout.
- Seed-ul inițial prin `APP_RUN_SEEDERS` beneficiază de același fix de memorie.

### Negative / trade-offs

- **Worker-ul unic e ocupat ~70 s** cât rulează resetul (`maxProcesses = 1`). Joburile dispecerizate în acel interval așteaptă. Acceptat, la 03:00 UTC.
- **`retry_after` de 15 minute pentru toate joburile de pe conexiune.** Jobul unui worker mort se reia după până la 15 minute, nu după 90 s.
- **Aplicația dă erori ~70 s** cât rulează `migrate:fresh` și seed-ul. La fel în toate variantele. O pagină de mentenanță pe durata resetului rămâne o îmbunătățire posibilă, nefăcută.
- Cifrele sunt de pe PHP 8.4 local. Se reverifică pe stack-ul de producție la publicare (plan §15: „rulat o dată pe stack-ul de producție, durata măsurată").

## Istoric

- 2026-09-13 — creat. Proprietarul a ales varianta 4 dintre variantele 2–4, pe măsurătorile de mai sus.
