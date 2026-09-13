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
        APP_URL,
    };
}
