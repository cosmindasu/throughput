import { expect, test } from '@playwright/test';
import { authFile } from '../support/auth';

/**
 * Test de fum peste TOATE destinațiile de navigație: fiecare se deschide, randează și nu
 * aruncă nimic necaptat.
 *
 * **De ce există.** Auditul din 2026-09-29, pe deployment-ul live, a găsit `/…/unassigned`
 * complet ALB. Cauza: `deals` și `orders` sunt amânate server-side (`Inertia::defer`), dar
 * pagina citea `deals.data.length` direct, în afara unui `<Deferred>`. La prima randare
 * prop-ul lipsește, `Cannot read properties of undefined (reading 'data')` urcă prin React,
 * iar React demontează TOT arborele — deci nu lipsea un tabel, lipsea pagina.
 *
 * Nimic din gate-urile existente nu putea vedea asta:
 *
 * - `tsc` nu, fiindcă tipurile generate declară prop-urile amânate NON-opționale
 *   (`UnassignedIndexPageProps.deals: CursorPage<DealSummary>`) — pentru compilator prop-ul
 *   e mereu acolo, deci lipsa gărzii nu e o eroare de tip;
 * - testele Pest nu, fiindcă răspunsul HTTP era corect — 200, cu prop-urile amânate absente
 *   exact cum cere protocolul. Defectul e ÎN RANDAREA din browser;
 * - celelalte specuri E2E nu, fiindcă niciunul nu trecea pe aici.
 *
 * De-asta garda e la nivel de rută și pe două semnale, nu pe un `expect` de conținut: un
 * `pageerror` necaptat și un `<main>` gol sunt exact cele două urme pe care le lasă clasa
 * asta de defect, indiferent de pagina care o reintroduce.
 *
 * Owner, fiindcă e singurul rol care vede toate intrările (§7.4) — `roles.spec.ts` acoperă
 * separat cine ce are voie să vadă; aici întrebarea e „se randează?", nu „are dreptul?".
 */
test.use({ storageState: authFile('owner') });

/**
 * Destinațiile din navigația principală plus paginile de Settings. Doar rute de PAGINĂ:
 * `…/export` și `…/lookup` întorc fișiere sau JSON, nu componente Inertia.
 */
const ROUTES = [
    '/cascade/dashboard',
    '/cascade/accounts',
    '/cascade/contacts',
    '/cascade/deals',
    '/cascade/deals/board',
    '/cascade/pipeline',
    '/cascade/products',
    '/cascade/orders',
    '/cascade/invoices',
    '/cascade/reports',
    '/cascade/imports',
    '/cascade/unassigned',
    '/cascade/activity',
    '/cascade/settings',
    '/cascade/settings/members',
    '/cascade/settings/billing',
    '/cascade/settings/shipping',
    '/cascade/settings/api-tokens',
    '/cascade/settings/data-export',
    '/cascade/settings/sent-emails',
    '/cascade/settings/webhooks',
    '/cascade/settings/preferences',
] as const;

for (const route of ROUTES) {
    test(`${route} se randează fără eroare necaptată`, async ({ page }) => {
        const pageErrors: string[] = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));

        await page.goto(route);

        // `<h1>` vizibil = arborele React chiar a ajuns să randeze pagina, nu doar layoutul.
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

        // Al doilea semnal, independent de primul: pe defectul din 2026-09-29 React demonta
        // tot, deci `<main>` rămânea gol. Pragul e deliberat mic — testul întreabă „e ceva
        // acolo?", nu „e conținutul corect" (aia e treaba specurilor dedicate).
        const mainText = await page.locator('#main-content').innerText();
        expect(mainText.trim().length).toBeGreaterThan(20);

        // Abia la final: un `pageerror` apărut ORIUNDE pe durata încărcării pică testul,
        // chiar dacă pagina a apucat să randeze ceva înainte să crape.
        expect(pageErrors, `erori necaptate pe ${route}`).toEqual([]);
    });
}

/**
 * Completarea celor de mai sus pentru listele cu prop-uri amânate: nu e destul ca shell-ul
 * să se randeze, scheletul trebuie și ÎNLOCUIT de conținut. O a doua cerere care eșuează
 * (sau un `<Deferred>` legat de un nume de prop greșit) lasă scheletul pe ecran la
 * nesfârșit — o pagină care „se încarcă" pentru totdeauna, nu una care cade.
 */
const DEFERRED_ROUTES = [
    '/cascade/accounts',
    '/cascade/contacts',
    '/cascade/deals',
    '/cascade/products',
    '/cascade/orders',
    '/cascade/invoices',
    '/cascade/unassigned',
] as const;

for (const route of DEFERRED_ROUTES) {
    test(`${route} își înlocuiește scheletul cu date`, async ({ page }) => {
        await page.goto(route);

        // `TableSkeleton` se anunță ca `role="status"` cu eticheta de încărcare; când datele
        // amânate sosesc, `<Deferred>` îl scoate din DOM.
        await expect(page.getByRole('status', { name: 'Loading' })).toHaveCount(0, {
            timeout: 15_000,
        });
    });
}
