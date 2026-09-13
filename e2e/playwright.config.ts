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

    webServer: {
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
        env: e2eEnv(),
    },
});
