import { expect, test, type Page } from '@playwright/test';
import { authFile } from '../support/auth';

/**
 * §24.3 / plan §8, Livrabile — panoul de ajutor (`HelpPanel.tsx`, FR-HELP-01…04) pe fiecare
 * ecran al Fazei 2 care are subiect (`resources/js/help/index.ts`): `?` deschide, cele patru
 * secțiuni sunt prezente, `Esc` închide și focusul revine pe declanșator. O SINGURĂ buclă de
 * test (cerut explicit), nu câte un test per ecran — `HelpTopicCoverageTest.php` (Pest)
 * acoperă deja „fiecare rută din navigație ARE un subiect"; testul de aici verifică
 * COMPORTAMENTUL interactiv, la fel pe orice ecran care are unul.
 *
 * Textele efective ale subiectelor (`resources/js/help/topics/*.ts`) sunt rescrise în
 * paralel de alt agent — se verifică STRUCTURA fixă din `HelpPanel.tsx` (titlurile celor
 * patru secțiuni, fixe în cod, nu în conținut), niciodată un paragraf anume.
 *
 * Rol: Manager — singurul, în afară de Owner, cu acces la toate cele 11 ecrane vizate
 * (inclusiv `Pipeline/Index`, restrâns Owner/Manager — `PipelinePolicy::viewAny`).
 */
test.use({ storageState: authFile('manager') });

interface HelpScreen {
    label: string;
    goto: (page: Page) => Promise<void>;
}

/**
 * Ecranele Fazei 2 cu subiect de ajutor (`HELP_TOPICS_BY_COMPONENT`), MINUS
 * `Accounts/Create`/`Accounts/Edit`/`Contacts/Create`/`Contacts/Edit`/`Deals/Create`/
 * `Deals/Edit` — împart exact același subiect cu ecranul „Show" corespunzător (un
 * formular aproape identic „nu merită două texte separate", `help/index.ts`), deci n-ar
 * verifica nimic în plus aici. Ecranele „Show" se ating navigând din listă (primul rând),
 * niciodată cu un id memorat — dacă lista e goală pe un tenant, testul pică vizibil pe
 * `waitFor`, nu tăcut pe un id inventat.
 */
const SCREENS: HelpScreen[] = [
    { label: 'Dashboard', goto: (page) => page.goto('/marlin/dashboard') },
    { label: 'Accounts/Index', goto: (page) => page.goto('/marlin/accounts') },
    {
        label: 'Accounts/Show',
        goto: async (page) => {
            await page.goto('/marlin/accounts');
            await page.getByRole('table').waitFor();
            await page.locator('table tbody tr').first().getByRole('link').first().click();
            await page.waitForURL(/\/accounts\/[^/]+$/);
        },
    },
    { label: 'Contacts/Index', goto: (page) => page.goto('/marlin/contacts') },
    {
        label: 'Contacts/Show',
        goto: async (page) => {
            await page.goto('/marlin/contacts');
            await page.getByRole('table').waitFor();
            await page.locator('table tbody tr').first().getByRole('link').first().click();
            await page.waitForURL(/\/contacts\/[^/]+$/);
        },
    },
    { label: 'Deals/Kanban', goto: (page) => page.goto('/marlin/deals/board') },
    { label: 'Deals/Index', goto: (page) => page.goto('/marlin/deals') },
    {
        label: 'Deals/Show',
        goto: async (page) => {
            await page.goto('/marlin/deals');
            await page.getByRole('table').waitFor();
            await page.locator('table tbody tr').first().getByRole('link').first().click();
            await page.waitForURL(/\/deals\/[^/]+$/);
        },
    },
    { label: 'Pipeline/Index', goto: (page) => page.goto('/marlin/pipeline') },
    { label: 'Settings/Index', goto: (page) => page.goto('/marlin/settings') },
    { label: 'Settings/Preferences', goto: (page) => page.goto('/marlin/settings/preferences') },
];

test('panoul de ajutor — deschis cu "?", cele patru secțiuni, Esc readuce focusul, pe fiecare ecran al fazei', async ({ page }) => {
    for (const screen of SCREENS) {
        await test.step(screen.label, async () => {
            await screen.goto(page);

            const trigger = page.getByRole('button', { name: 'Help for this page' });
            await expect(trigger).toBeVisible();

            // Panoul (`<aside aria-label="Help: {titlu}">`) e SINGURUL conținut scopat
            // pentru asertările de mai jos — ecrane precum `Deals/Show` au propriul `<h2>`
            // („Stage history"), deci un `getByRole('heading', { level: 2 })` neascopat pe
            // pagină ar fi ambiguu de îndată ce panoul se deschide.
            const panel = page.locator('aside[aria-label^="Help:"]');

            // Deschis EXCLUSIV de la tastatură (FR-HELP-01) — focusul curent e pe
            // `<body>`/document imediat după navigare, niciodată într-un câmp de text.
            await page.keyboard.press('?');

            const heading = panel.getByRole('heading', { level: 2 });
            await expect(heading).toBeFocused();

            // Structura fixă în patru părți (FR-HELP-02), NU conținutul — titlurile sunt
            // literale în `HelpPanel.tsx`, deci stabile indiferent de rescrierea textelor.
            await expect(panel.getByRole('heading', { name: 'What you can do here' })).toBeVisible();
            await expect(panel.getByRole('heading', { name: 'Rules that apply here' })).toBeVisible();

            // `<summary>`, NU `getByText(...)` neascopat: textul „How it's built" al
            // secțiunii se poate regăsi și ca SUBSTRING în conținutul altui subiect (ex.
            // `deals-list.ts` trimite cititorul la „the Kanban topic's «How it's built»"),
            // deci un `getByText` simplu devine ambiguu — `<summary>` e unic în panou.
            await expect(panel.locator('summary')).toHaveText("How it's built");

            await page.keyboard.press('Escape');
            await expect(trigger).toBeFocused();
            await expect(panel.getByRole('heading', { name: 'What you can do here' })).toHaveCount(0);
        });
    }
});

test('"?" tastat într-un câmp de text nu deschide panoul de ajutor', async ({ page }) => {
    await page.goto('/marlin/accounts');

    // `exact: true` — fără el, „Search" e substring și în `aria-label="Global search"`
    // (dialogul Cmd+K) și în `aria-label="Search results"` (listbox-ul lui), ambele
    // prezente în DOM (chiar închise) — `getByLabel` neascopat devine ambiguu.
    const search = page.getByLabel('Search', { exact: true });
    await search.click();
    await search.pressSequentially('?');

    await expect(search).toHaveValue('?');
    await expect(page.getByRole('heading', { name: 'What you can do here' })).toHaveCount(0);
});
