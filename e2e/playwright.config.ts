import { defineConfig, devices } from '@playwright/test';
import { APP_URL, REPO_ROOT, e2eEnv } from './support/env';

/**
 * Suita E2E a Fazei 2 (specs.md §24.3, plan §5). Doar Chromium — decizie deja
 * luată, nicio matrice de browsere: nu e vorba de compatibilitate cross-browser,
 * ci de fluxuri critice (RBAC, comutare workspace) și accesibilitate, unde
 * motorul de randare nu schimbă rezultatul.
 *
 * `workers: 1`, fără retry — retry-urile ascund instabilitatea și dublează
 * minutele facturate în CI (regula globală de portofoliu, cota e comună cu
 * celelalte 11 proiecte).
 *
 * --- Lot I18N, Val 1 (ADR-022) — fixarea suitei pe `en` ---------------------
 *
 * Cele 69 de teste existente (17 fișiere de spec) presupun text englez prin
 * 297 de selectori pe text vizibil (`getByRole(..., { name: '...' })`). De la
 * `App\Support\LocalePreference` încolo, limba randată NU mai e un dat fix al
 * aplicației — e rezolvată per cerere, în ordinea: (1) `users.locale` al
 * contului autentificat curent, (2) cookie-ul `locale`, (3) `APP_LOCALE`.
 *
 * Fixare pe DOUĂ straturi diferite, deliberat, nu unul singur:
 *
 *  1. `APP_LOCALE=en`, explicit în `env`-ul serverului Playwright (mai jos) —
 *     fixează pasul (3) al rezoluției, independent de orice ar conține `.env`
 *     local/CI. E același motiv pentru care `DEMO_MODE`/`CACHE_STORE` sunt deja
 *     suprascrise explicit în `e2eEnv()`, nu lăsate pe seama mediului ambiant.
 *     Conturile demo (owner/manager/agent/viewer) NU au `locale` setat explicit
 *     de niciun seeder — migrația `users.locale` are implicit `'en'` — deci
 *     pasul (1) rezolvă oricum la `en` pentru ele; `APP_LOCALE=en` e plasa care
 *     ține pasul (3) determinist pentru orice cerere ANONIMĂ (`/login`, înainte
 *     de autentificare), unde pașii (1)-(2) nu se aplică încă.
 *
 *     Deliberat NU un cookie `locale=en` injectat direct în `use.storageState`:
 *     `ThemePreference`/`setUserTheme` (e2e/support/theme.ts) documentează deja
 *     lecția P2-004 — un cookie pus direct e IGNORAT pentru orice cont cu o
 *     alegere persistă (pasul 1 câștigă mereu peste cookie). Un cookie de
 *     config ar da o falsă senzație de fixare, fără s-o garanteze pentru
 *     conturile autentificate — exact contextul în care rulează cele 297 de
 *     selectori.
 *
 *  2. `use.locale: 'en-US'` — layer DIFERIT, la nivel de browser Chromium, nu
 *     de rezoluție a aplicației: fixează `navigator.language`/`Intl` implicit
 *     al motorului de randare, ca formatarea nativă (`<input type="date">`,
 *     orice `toLocaleString()` care ar scăpa neintenționat pe implicitul
 *     mediului CI/local, în loc de propul `locale` rezolvat de server) să nu
 *     depindă de locale-ul mașinii care rulează testele. Nu înlocuiește (1) —
 *     `Accept-Language` e explicit IGNORAT de `LocalePreference` (FR-I18N-01,
 *     decizie non-negociabilă) — e strict igienă de mediu de test, pe un strat
 *     pe care ADR-022 nu-l acoperă.
 *
 * Subsetul FR (Val 5) NU se adaugă aici: primește proiect/config propriu, cu
 * propriile conturi (`locale='fr'` persistă la pasul 1, câștigă peste orice
 * `APP_LOCALE`/cookie de mai sus) — vezi task-ul de reparare din
 * `e2e/setup/auth.setup.ts` pentru cuplarea pe text literal.
 */
export default defineConfig({
    testDir: '.',
    outputDir: './test-results',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],

    use: {
        baseURL: APP_URL,
        // Vezi „Lot I18N, Val 1" mai sus — layer de browser, nu de rezoluție a
        // aplicației.
        locale: 'en-US',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },

    projects: [
        // Autentificare „un click” (DEMO_MODE, FR-PUB-02) — scrie câte un
        // `storageState` per rol în `e2e/.auth/`, citit apoi de `chromium`.
        {
            name: 'setup',
            testDir: './setup',
            testMatch: /.*\.setup\.ts$/,
            use: { ...devices['Desktop Chrome'] },
        },

        {
            name: 'chromium',
            testDir: './specs',
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['setup'],
        },
    ],

    // Independent de `webServer` (rulează chiar dacă serverul e deja pornit
    // dintr-o rulare anterioară — `reuseExistingServer` mai jos).
    globalSetup: './global-setup',

    webServer: [
        {
            // `--no-reload`, altfel `ServeCommand::startProcess()` filtrează $_ENV
            // înainte de a-l pasa procesului copil care chiar răspunde la cereri
            // (vezi `ServeCommand::$passthroughVariables`): cu `.env` prezent și fără
            // `--no-reload`, orice variabilă din `env` de mai jos care NU e pe acea
            // listă albă e ȘTEARSĂ din mediul copilului, care își reîncarcă atunci
            // `.env` de la zero — exact opusul „variabilele de proces câștigă peste
            // .env" din decizia inițială. Găsit local: `REDIS_HOST` din `.env`
            // (`redis`, hostname de container) supraviețuia suprascrierii, DNS-ul
            // ISP-ului rezolvândul spre o adresă moartă, iar cererea agăța 30 s+
            // până la `Maximum execution time exceeded` pe conectarea Redis
            // (Horizon/spatie-permission țin conexiunea `redis` „default" în config,
            // chiar cu CACHE_STORE=array/SESSION_DRIVER=file/QUEUE_CONNECTION=database).
            command: 'php artisan serve --no-reload --host=127.0.0.1 --port=8010',
            url: APP_URL,
            cwd: REPO_ROOT,
            // Local: reutilizează un server deja pornit (`composer run dev`), ca să
            // nu concureze pe portul 8010. CI: pornește mereu unul curat.
            reuseExistingServer: !process.env.CI,
            timeout: 120_000,
            // `APP_LOCALE: 'en'` — vezi „Lot I18N, Val 1" mai sus: pasul (3), de
            // rezervă, al `LocalePreference::resolveForRequest()`, fixat aici ca să
            // nu depindă de `.env`.
            env: { ...e2eEnv(), APP_LOCALE: 'en' },
        },
        {
            // Faza 3, valul 2 — primul val al suitei care dispecerizează joburi reale
            // (`bulk_operations`, exportul PDF, eticheta de expediere) prin
            // `QUEUE_CONNECTION=database`. Fără un worker care le consumă, `PlanBulkOperationJob`/
            // `ExportListJob`/`GenerateShippingLabelJob` rămân `pending` la nesfârșit — pagina de
            // progres/`Exports/Show`/`ShipmentsSection` fac polling degeaba, iar testele care
            // așteaptă o stare terminală ar pica pe timeout, nu pe un defect real.
            //
            // Aceleași cozi ca `config/horizon.php` (`imports, reports, bulk, default`), pe
            // conexiunea `database`, nu `redis` — Horizon nu pornește niciodată pentru suita asta
            // (bugetul de memorie, §project.md: „fără Meilisearch, fără Reverb în MVP, un singur
            // worker de coadă" — aici, PENTRU teste, un al doilea proces PHP e ieftin, nu „încă un
            // serviciu").
            //
            // Fără `url`/`wait`: nu e un server HTTP, deci Playwright pornește procesul și
            // continuă imediat (`WebServerPlugin._waitForProcess()` nu așteaptă nimic fără
            // `isAvailableCallback`/`wait`) — global-setup + autentificarea celor 4 conturi oricum
            // durează câteva secunde înaintea primului test care dispecerizează un job, timp
            // suficient ca `queue:work` să apuce să boot-eze Laravel.
            command: 'php artisan queue:work --queue=default,bulk,imports,reports --sleep=1 --tries=1',
            cwd: REPO_ROOT,
            // `APP_LOCALE` fixat aici mai mult din consecvență decât din necesitate:
            // joburile care generează text (Val 2, ADR-022 consecința 1) primesc
            // `locale` ca scalar de constructor, serializat la dispecerizare — NU
            // citesc `App::getLocale()`/`APP_LOCALE` al worker-ului la momentul
            // execuției (exact capcana deja documentată în tenancy.md pentru
            // `tenantId`, aplicată acum limbii).
            env: { ...e2eEnv(), APP_LOCALE: 'en' },
        },
    ],
});
