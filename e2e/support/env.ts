import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));

/**
 * Rădăcina repo-ului, calculată din calea acestui fișier (`e2e/support/env.ts` →
 * `../..`) — nu din `process.cwd()`, ca `webServer`/`globalSetup` să funcționeze
 * indiferent de directorul din care e invocat `playwright test` (`npm run e2e` din
 * rădăcină, dar și un `npx playwright test -c e2e/playwright.config.ts` rulat din
 * altă parte).
 */
export const REPO_ROOT = path.resolve(HERE, '../..');

export const APP_HOST = '127.0.0.1';
export const APP_PORT = 8010;
export const APP_URL = `http://${APP_HOST}:${APP_PORT}`;

/**
 * Secretul cu care `App\Http\Controllers\Webhooks\StripeWebhookController` verifică
 * `Stripe-Signature` (`cashier.webhook.secret`). Valoare LOCALĂ, inventată aici și
 * impusă serverului prin `e2eEnv()` — §22.4/BR-DEMO-03: nicio cheie reală, niciun apel
 * către Stripe, în niciun test. Payload-urile fluxurilor 7 și 8 din specs.md §24.3 se
 * semnează cu ea în `e2e/support/stripe.ts`, exact ca `Tests\Concerns\SignsStripeWebhooks`
 * în suita Pest — acolo `whsec_test_secret_for_pest`, aici altul, ca cele două suite să
 * nu împrumute niciodată același secret dintr-un mediu comun.
 */
export const STRIPE_WEBHOOK_SECRET = 'whsec_e2e_local_secret_for_playwright';

/**
 * `throughput.limits.bulk_chunk_size` pentru suita E2E — 25, nu 500 (implicitul de
 * producție, `config/throughput.php`).
 *
 * Motivul e §24.3 pct. 6, „anulare la jumătate": anularea unui `Bus::batch()` e
 * COOPERATIVĂ (`ProcessBulkChunkJob` verifică `batch()->cancelled()` la începutul lui
 * `handle()`), deci există doar dacă mai sunt chunk-uri NEÎNCEPUTE când sosește anularea.
 * Cu 500 de rânduri per chunk, o operație de 2.000 de rânduri are 4 joburi și se termină
 * în ~1-2 secunde — fereastra de anulare e prea mică pentru orice test care nu ghicește
 * momentul. Cu 25, aceeași operație are 80 de joburi și câteva secunde de rulare, iar
 * testul poate aștepta DETERMINIST un progres parțial înainte de a apăsa „Cancel".
 *
 * Nu schimbă nimic pentru restul suitei în afară de numărul de joburi: testele de prag
 * din `orders-bulk.spec.ts` (126 de rânduri) trec de la 1 chunk la 6, aceeași stare
 * finală.
 */
export const BULK_CHUNK_SIZE = '25';

/**
 * Suprascrierile de mediu pentru `php artisan serve` (webServer) și pentru
 * `global-setup.ts` (reset + seed) — decizii deja luate, nu se redeschid:
 *
 *  - baza dedicată `throughput_e2e`, separată de `throughput_test` (Pest) și de
 *    `throughput` din docker-compose, ca suita Playwright să nu calce peste alt
 *    proces care rulează teste în paralel;
 *  - fără Redis: `CACHE_STORE=array`, `SESSION_DRIVER=file`,
 *    `QUEUE_CONNECTION=database` — bugetul de memorie (§project.md) nu justifică
 *    un al doilea serviciu doar pentru E2E;
 *  - `DEMO_MODE=true` — altfel `/login/demo/{role}` (BR-PUB-01) răspunde 404 și
 *    proiectul `setup` (autentificare) n-are ce apăsa.
 *
 * Variabilele de proces câștigă peste `.env` (Dotenv e immutable în Laravel) —
 * nu trebuie atins `.env`-ul de dezvoltare pentru asta.
 *
 * `DB_HOST`/`DB_PORT` rămân suprascriabile din mediul de proces care lansează
 * Playwright: implicit `127.0.0.1:55432` local (5432 e ocupat de Postgres-ul
 * Homebrew — vezi memoria de mediu local), suprascrise la `127.0.0.1:5432` în
 * CI (serviciul Postgres al jobului `e2e`, vezi .github/workflows/ci.yml).
 */
export function e2eEnv(): NodeJS.ProcessEnv {
    return {
        ...process.env,
        DB_HOST: process.env.DB_HOST ?? '127.0.0.1',
        DB_PORT: process.env.DB_PORT ?? '55432',
        DB_DATABASE: 'throughput_e2e',
        DB_USERNAME: 'throughput_app',
        DB_PASSWORD: 'secret',
        DB_MIGRATIONS_USERNAME: 'throughput_migrator',
        DB_MIGRATIONS_PASSWORD: 'secret',
        CACHE_STORE: 'array',
        SESSION_DRIVER: 'file',
        QUEUE_CONNECTION: 'database',
        DEMO_MODE: 'true',
        STRIPE_WEBHOOK_SECRET,
        BULK_CHUNK_SIZE,
        APP_URL,
    };
}
