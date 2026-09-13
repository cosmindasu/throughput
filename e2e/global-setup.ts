import { execFileSync } from 'node:child_process';
import { e2eEnv, REPO_ROOT } from './support/env';

/**
 * Reset + seed ÎNAINTEA oricărui test, independent de `webServer` — decizie deja
 * luată. Rulează o singură dată per `playwright test` (nu per proiect: proiectul
 * `setup` — autentificare — și proiectul `chromium` — specs — lucrează pe
 * ACELAȘI seed, altfel `storageState`-urile scrise de `setup` ar referi
 * utilizatori dintr-un dataset pe care `chromium` îl resetează sub el).
 *
 * Rulează prin `php artisan`, nu prin HTTP: joburile de sistem (§tenancy.md,
 * „Două familii de joburi”) nu au nevoie de server pornit, iar `migrate:fresh`
 * pe conexiunea `pgsql_migrations` (rolul cu BYPASSRLS) e exact ce rulează
 * `quality` în CI — același bootstrap, altă bază (`throughput_e2e`, nu
 * `throughput_test`).
 */
export default async function globalSetup(): Promise<void> {
    const env = e2eEnv();

    const artisan = (args: string[]): void => {
        execFileSync('php', ['artisan', ...args], {
            cwd: REPO_ROOT,
            env,
            stdio: 'inherit',
        });
    };

    // `pgsql_migrations`: rolul aplicației (`throughput_app`) nu poate crea tabele
    // și RLS i-ar bloca oricum INSERT-urile seed-ului fără context de tenant
    // (ADR-014) — migrarea trebuie să treacă pe rolul cu BYPASSRLS.
    artisan(['migrate:fresh', '--database=pgsql_migrations', '--force']);

    // 0.001 — minimul care ține fluxurile E2E posibile, nu o fracțiune aritmetică
    // a volumului de demo (vezi DemoDatasetSeeder::tenantConfigs()): 40 conturi /
    // 60 comenzi per tenant, Agentul cu ≥5 conturi proprii în Marlin, Owner membru
    // în Marlin și Cascade — exact ce cere workspace-switch.spec.ts.
    artisan(['demo:seed-volume', '--scale=0.001']);
}
