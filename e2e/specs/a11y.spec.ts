import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import type { Result } from 'axe-core';
import { authFile } from '../support/auth';
import { APP_HOST } from '../support/env';

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
];

test.use({ storageState: authFile('manager') });

async function setTheme(page: Page, theme: Theme): Promise<void> {
    // §15.6 — cookie necriptat `theme`, citit server-side
    // (`ThemePreference::resolveForRequest`) ÎNAINTE de orice randare: setat
    // înaintea navigării, nu prin `ThemeToggle` din UI, ca fiecare scanare să
    // vadă o singură temă, izolat.
    await page.context().addCookies([{ name: 'theme', value: theme, domain: APP_HOST, path: '/' }]);
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

        for (const target of TARGETS) {
            // Subset @smoke (PR-uri): un singur ecran, tema implicită (închisă) —
            // suficient să prindă o regresie de accesibilitate introdusă de un PR,
            // fără costul întregii matrice de 4 ecrane × 2 teme pe fiecare push.
            const tag = theme === 'dark' && target.label === 'Dashboard' ? ['@smoke'] : [];

            test(`${target.label} — 0 violări critice (axe, WCAG 2.x A/AA)`, { tag }, async ({ page }, testInfo) => {
                await page.goto(target.path);
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

/**
 * Ecranul de kanban (`/{workspace}/deals`, pachetul de deals) nu există încă pe
 * `main` la data scrierii acestei suite (2026-09-13) — `resources/js/Pages` nu
 * are un ecran de kanban, doar configurarea de pipeline/etape (`Pipeline/Index.tsx`,
 * deja acoperită mai sus). Când pachetul de deals aterizează pe `main`, se extinde
 * `TARGETS` de mai sus cu ruta de kanban (probabil `/{workspace}/deals/board` sau
 * echivalent) și acest `test.fixme` se șterge.
 */
test.fixme('Kanban board — axe pe ambele teme (blocat pe pachetul de deals, încă nemerged pe main)', async () => {});
