import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import type { Result } from 'axe-core';
import { authFile } from '../support/auth';
import { APP_HOST } from '../support/env';
import { setUserTheme } from '../support/theme';

/**
 * §20.3 / §24.4 — axe-core pe ecranele principale, PE AMBELE TEME: o violare de
 * contrast apare exact într-una dintre ele (tokens diferite per temă,
 * `.ai/rules/frontend.md`), deci o scanare pe o singură temă ratează jumătate
 * din suprafață.
 *
 * Rulează cu sesiunea de Manager: singurul rol, în afară de Owner, care vede
 * interfața COMPLETĂ (butoane de acțiune incluse) pe toate cele patru ecrane —
 * Viewer ar ascunde elementele de scriere, Agent ar porni filtrat pe „My
 * accounts" (mai puține rânduri de tabel randate).
 *
 * Criteriu de acceptanță (specs.md §20.3): 0 violări CRITICE. Violările
 * „serious" se atașează la raportul Playwright și se enumeră în raportul final
 * al agentului, fără să pice testul — nu sunt ignorate, doar nu blochează CI
 * cât nu există un buget dedicat să le rezolve pe toate deodată.
 */

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

type Theme = 'dark' | 'light';

const THEMES: Theme[] = ['dark', 'light'];

interface AxeTarget {
    label: string;
    path: string;
    /** Ecranele cu date deferite (FR-PERF-01) au nevoie de un selector de „gata încărcat" — axe trebuie să vadă rândurile reale, nu `TableSkeleton`. */
    waitFor: (page: Page) => Promise<unknown>;
}

const TARGETS: AxeTarget[] = [
    {
        label: 'Dashboard',
        path: '/marlin/dashboard',
        waitFor: (page) => page.getByRole('region', { name: 'Recent activity' }).waitFor(),
    },
    {
        label: 'Accounts list',
        path: '/marlin/accounts',
        waitFor: (page) => page.getByRole('table').waitFor(),
    },
    {
        label: 'Contacts list',
        path: '/marlin/contacts',
        waitFor: (page) => page.getByRole('table').waitFor(),
    },
    {
        label: 'Pipeline',
        path: '/marlin/pipeline',
        waitFor: (page) => page.getByRole('heading', { level: 1 }).waitFor(),
    },
    {
        label: 'Deals kanban',
        path: '/marlin/deals/board',
        // Coloanele reale, nu un skeleton: `Deals/Kanban.tsx` NU e deferred (spre
        // deosebire de listele Accounts/Contacts) — `columns` vine sincron din
        // `DealController::board()` — dar prima coloană a pipeline-ului implicit
        // (`New`, poziția 1, `CatalogSeeder::run()`) tot trebuie așteptată explicit:
        // fără asta, axe ar putea scana un `<main>` gol pe randarea inițială.
        waitFor: (page) => page.getByRole('heading', { name: 'New', level: 2 }).waitFor(),
    },
    // Faza 4 — ecranele noi ale acestui lot (§14 import, §16 rapoarte, §22.3 jurnalul de
    // email). Rulează pe DB proaspătă (acest fișier e primul alfabetic din `specs/`,
    // `workers: 1`): Imports/Reports/Sent Emails sunt încă GOALE aici (`EmptyState`), deci
    // scanarea acoperă structura de bază (titlu, buton „New …", stare goală), NU tabelul cu
    // date real — acela e deja verificat separat, o singură dată, temă implicită (dark), în
    // `imports.spec.ts`/`reports.spec.ts` (ecranul „Show", cu conținut real: pași de mapare,
    // rezultat built-in, istoric de rulări).
    {
        label: 'Imports list',
        path: '/marlin/imports',
        waitFor: (page) => page.getByRole('heading', { name: 'Imports', level: 1 }).waitFor(),
    },
    {
        label: 'New import',
        path: '/marlin/imports/create',
        waitFor: (page) => page.getByRole('heading', { name: 'New import' }).waitFor(),
    },
    {
        label: 'Reports list',
        path: '/marlin/reports',
        waitFor: (page) => page.getByRole('heading', { name: 'Reports', level: 1 }).waitFor(),
    },
    {
        label: 'New report',
        path: '/marlin/reports/create',
        waitFor: (page) => page.getByRole('heading', { name: 'New report' }).waitFor(),
    },
    {
        label: 'Sent emails',
        path: '/marlin/settings/sent-emails',
        // Deferred (`Inertia::defer`, `SentEmailController::index()`) — axe trebuie să vadă
        // starea REZOLVATĂ (tabel SAU mesajul de listă goală), nu `TableSkeleton`.
        waitFor: (page) =>
            page
                .locator('table')
                .or(page.getByText('No emails have been sent or intercepted yet.'))
                .first()
                .waitFor(),
    },
];

test.use({ storageState: authFile('manager') });

/**
 * P1 (code review) — un cookie SINGUR nu ajunge: `ThemePreference::resolveForRequest()`
 * citește ÎNTÂI alegerea explicită a utilizatorului AUTENTIFICAT curent (`users.theme`),
 * ÎNAINTE de cookie — toate conturile demo au deja `theme='dark'` explicit (migrația
 * `2026_09_13_120000_change_users_theme_default_to_dark.php`, implicit nou pentru
 * TOȚI utilizatorii, seederele nu trec `theme`), deci cookie-ul singur era IGNORAT: ambele
 * treceri „dark" și „light" randau de fapt tema închisă — verificat empiric, vezi raportul
 * agentului. `setUserTheme()` (`e2e/support/theme.ts`) schimbă preferința REALĂ, prin
 * `PATCH /preferences/theme`. Cookie-ul rămâne pus și el (nu strică, dar nu mai e sursa de
 * adevăr aici).
 */
async function setTheme(page: Page, theme: Theme): Promise<void> {
    await setUserTheme(page, theme);
    await page.context().addCookies([{ name: 'theme', value: theme, domain: APP_HOST, path: '/' }]);
}

/**
 * Dovadă că tema chiar s-a aplicat PE ACEST răspuns (FR-PREF-03 — randată server-side, din
 * `resources/views/app.blade.php`: `<html class="{{ $theme === 'dark' ? 'dark' : '' }}">`) —
 * fără ea, testul poate trece „verde" scanând din nou tema greșită, exact regresia găsită la
 * review.
 */
async function expectHtmlThemeClass(page: Page, theme: Theme): Promise<void> {
    await expect(page.locator('html')).toHaveClass(theme === 'dark' ? 'dark' : '');
}

function slug(label: string): string {
    return label.toLowerCase().replace(/[^a-z0-9]+/g, '-');
}

function formatViolations(violations: Result[]): string {
    if (violations.length === 0) {
        return '';
    }

    return violations
        .map((violation) => {
            const nodes = violation.nodes.map((node) => `    - ${node.target.join(' ')}`).join('\n');

            return `[${violation.impact}] ${violation.id} — ${violation.help}\n${nodes}`;
        })
        .join('\n');
}

for (const theme of THEMES) {
    test.describe(`temă ${theme}`, () => {
        test.beforeEach(async ({ page }) => {
            await setTheme(page, theme);
        });

        // Restaurează `dark` după fiecare test „light": `users.theme` e o coloană
        // PERSISTĂ pe contul de Manager, comun tuturor spec-urilor (`workers: 1`, aceeași
        // bază `throughput_e2e`) — fără restaurare, orice spec de după acest fișier care
        // reautentifică Managerul ar rula, din greșeală, pe tema deschisă.
        if (theme === 'light') {
            test.afterEach(async ({ page }) => {
                await setUserTheme(page, 'dark');
            });
        }

        for (const target of TARGETS) {
            // Subset @smoke (PR-uri): un singur ecran, tema implicită (închisă) —
            // suficient să prindă o regresie de accesibilitate introdusă de un PR,
            // fără costul întregii matrice de 5 ecrane × 2 teme pe fiecare push.
            const tag = theme === 'dark' && target.label === 'Dashboard' ? ['@smoke'] : [];

            test(`${target.label} — 0 violări critice (axe, WCAG 2.x A/AA)`, { tag }, async ({ page }, testInfo) => {
                await page.goto(target.path);
                await expectHtmlThemeClass(page, theme);
                await target.waitFor(page);

                const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();

                const critical = results.violations.filter((violation) => violation.impact === 'critical');
                const serious = results.violations.filter((violation) => violation.impact === 'serious');

                await testInfo.attach(`axe-${slug(target.label)}-${theme}.json`, {
                    body: JSON.stringify(results.violations, null, 2),
                    contentType: 'application/json',
                });

                if (serious.length > 0) {
                    // Nu pică testul (decizie deja luată) — dar nu trece neobservat:
                    // apare în output-ul `list` reporter-ului și în atașamentul de mai sus.
                    console.warn(
                        `[axe] ${target.label} (${theme}): ${serious.length} violare(i) "serious":\n${formatViolations(serious)}`,
                    );
                }

                expect(critical, formatViolations(critical)).toEqual([]);
            });
        }
    });
}
