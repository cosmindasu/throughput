import { execFileSync } from 'node:child_process';
import { e2eEnv, REPO_ROOT } from './env';

/**
 * Un `php artisan …` rulat SINCRON din interiorul unui test, cu exact mediul suitei
 * (`e2eEnv()` — aceeași bază `throughput_e2e`, același `CACHE_STORE=array`). Tiparul e
 * deja folosit de `e2e/global-setup.ts` (`migrate:fresh` + `demo:seed-volume`); fișierul
 * ăsta îl face refolosibil, nu îl introduce.
 *
 * Codul de ieșire se citește DIRECT — `execFileSync` aruncă la orice cod diferit de 0.
 * Nicio conductă (`| tail`), care ar întoarce codul ultimului proces din conductă:
 * capcană deja plătită în acest proiect.
 */
function artisan(args: string[]): string {
    return execFileSync('php', ['artisan', ...args], {
        cwd: REPO_ROOT,
        env: e2eEnv(),
        encoding: 'utf-8',
    });
}

/**
 * PHP executat în contextul aplicației, pentru fixture-uri care nu au NICIUN drum prin
 * interfață — singurele două din această suită:
 *
 *  1. `tenants.stripe_id` (fluxurile 7 și 8 din specs.md §24.3). Fără el,
 *     `StripeWebhookController` nu găsește tenantul și marchează evenimentul `ignored`,
 *     deci fluxul n-are ce verifica. Coloana se scrie în producție de Cashier, la primul
 *     `createAsStripeCustomer()` — adică printr-un APEL REAL către Stripe, exact ce
 *     §22.4/BR-DEMO-03 interzice în teste.
 *  2. volumul de peste 1.000 de rânduri al fluxului 6. Prin `POST /accounts`, 2.000 de
 *     cereri pe `php artisan serve` (un singur proces) ar costa minute pentru un fixture
 *     care nu testează el însuși formularul de creare.
 *
 * Preferința generală a proiectului rămâne inversă (comenzi Artisan existente înaintea
 * codului ad-hoc); aici nu există comandă care să facă asta, iar alternativa — o comandă
 * nouă în `app/Console/Commands/` — ar fi cod de PRODUCȚIE scris exclusiv pentru teste.
 *
 * Afișează ce a rulat la eșec: `tinker` scrie eroarea PHP pe stdout și tot iese cu 0 în
 * unele cazuri, deci fiecare apelant termină codul cu un marcaj pe care îl verificăm.
 */
export function tinker(code: string): string {
    const output = artisan(['tinker', '--execute', code]);

    if (!output.includes(TINKER_OK)) {
        throw new Error(`Fixture-ul de tinker nu a raportat succes.\n--- cod ---\n${code}\n--- ieșire ---\n${output}`);
    }

    return output;
}

/** Marcajul pe care fiecare fragment de `tinker()` îl tipărește pe ultima linie. */
export const TINKER_OK = '__E2E_OK__';
