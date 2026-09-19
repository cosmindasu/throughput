import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { confirmOrder, createDraftOrders, createOrderWithLine, firstAccountId, lookupVariants } from '../support/api';
import { authFile } from '../support/auth';

/**
 * Faza 3, valul 2 (specs.md §13, §24.3 pct. 6) — operații în masă și export pe Orders.
 *
 * Fixture-urile de volum (draft-uri peste pragul de confirmare) se creează direct prin
 * `POST /orders` (`e2e/support/api.ts::createDraftOrders`), nu prin `Orders/Create` din UI:
 * 126+ trimiteri de formular ar costa minute pentru un fixture care nu testează el însuși
 * formularul (acoperit de `order-fulfilment.spec.ts`). Are nevoie de un worker de coadă
 * (`e2e/playwright.config.ts`, al doilea `webServer`) — fără el, `PlanBulkOperationJob`
 * rămâne `pending`, iar pagina de progres nu ajunge NICIODATĂ într-o stare terminală.
 *
 * Testele de prag (peste 125 de rânduri) NU sunt `@smoke`: creează >120 de comenzi fiecare,
 * minute de rulare — potrivite pentru suita completă (`main`/tag-uri), nu pentru fiecare PR.
 * Testul de RBAC (Viewer) e ieftin și rulează pe fiecare PR.
 */

const BASE = '/marlin';
const ORDERS_URL = `${BASE}/orders`;
const ACCOUNTS_URL = `${BASE}/accounts`;

/**
 * `App\Support\Bulk\BulkConfirmationThreshold::for()` — `min(round(rowCap × 0.25), 1000)`.
 * Agent: `rowCap = config('throughput.limits.bulk_agent_row_cap')` = 500 (implicit,
 * `BULK_AGENT_ROW_CAP` nu apare în `.env.example` — verificat direct în sursă, nu presupus)
 * → 125. Owner/Manager: fără plafon de rol → 1.000 (`ABSOLUTE_CAP`). Hardcodat aici cu
 * sursa citată, ca `deals-pipeline.spec.ts` hardcodează etapele din `CatalogSeeder` — un
 * fixture citit din cod, nu inventat.
 */
const AGENT_CONFIRMATION_THRESHOLD = 125;
const OVER_AGENT_THRESHOLD = AGENT_CONFIRMATION_THRESHOLD + 1;

test.describe('Viewer — vede exportul, nu acțiunile de scriere', () => {
    test.use({ storageState: authFile('viewer') });

    test(
        'fără bară de operații în masă și fără „New order", dar cu Export CSV/PDF',
        { tag: ['@smoke'] },
        async ({ page }) => {
            await page.goto(ORDERS_URL);
            await page.getByRole('table').waitFor();

            // §7.4 nota ³ / BR-BULK-03 — exportul e o CITIRE, permisă și Viewer-ului;
            // reasignarea/anularea sunt SCRIERI, absente din DOM (FR-RBAC-01), nu doar
            // dezactivate.
            await expect(page.getByRole('checkbox', { name: 'Select all orders on this page' })).toHaveCount(0);
            await expect(page.getByRole('link', { name: 'New order' })).toHaveCount(0);
            await expect(page.getByRole('link', { name: 'Export CSV' })).toBeVisible();
            await expect(page.getByRole('link', { name: 'Export PDF' })).toBeVisible();
        },
    );
});

test.describe('Export CSV/PDF de pe lista filtrată (§13.5)', () => {
    test.use({ storageState: authFile('manager') });

    test('CSV sincron și PDF prin coadă, amândouă respectând filtrul curent', async ({ page }) => {
        // Fixture: cel puțin O comandă `confirmed`, garantată — nu presupusă din ce a
        // semănat aleatoriu seed-ul la scara suitei (`--scale=0.001`, fără `mt_srand`).
        const accountId = await firstAccountId(page, ACCOUNTS_URL);
        const variants = await lookupVariants(page, BASE);
        const variant = variants.find((candidate) => candidate.available > 0);
        expect(variant, 'are nevoie de cel puțin o variantă cu stoc disponibil în seed').toBeTruthy();

        const orderId = await createOrderWithLine(page, BASE, { accountId, variantId: variant!.id, quantity: 1 });
        await confirmOrder(page, BASE, orderId);

        await page.goto(`${ORDERS_URL}?filter[status]=confirmed`);
        await page.getByRole('table').waitFor();

        // CSV — sub `export_sync_max_rows` (5.000, `.env.example`), răspunde SINCRON, în
        // cererea curentă (`ListExport::respond()`).
        const [csvDownload] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Export CSV' }).click(),
        ]);
        expect(csvDownload.suggestedFilename()).toMatch(/^orders-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/);
        const csvPath = await csvDownload.path();
        expect(csvPath, 'CSV-ul trebuie să fi ajuns pe disc').toBeTruthy();

        // PDF — NICIODATĂ sincron (ADR-019, DomPDF): clic pe „Export PDF" doar
        // NAVIGHEAZĂ, către `/exports/{id}` (`Exports/Show`, polling la 2s).
        await page.getByRole('link', { name: 'Export PDF' }).click();
        await page.waitForURL(/\/exports\/[^/]+$/);

        // `[aria-live="polite"]` — `getByRole('status')` e ambiguu pe această pagină (bannerul
        // `flash.success` are și el `role="status"`; la fel prima vizită a `HelpPanel`).
        const status = page.locator('div[aria-live="polite"]');
        await expect(status).toContainText('Ready to download', { timeout: 30_000 });

        // **Defect real găsit aici, reparat în `Exports/Show.tsx`** — „Download {format}"
        // randa cu `ButtonLink` (`<Inertia Link>`), nu cu un `<a>` simplu ca „Export
        // CSV"/„Export PDF" din `Orders/Index.tsx`. Un `<Link>` intercepta click-ul pe un
        // răspuns BINAR (`Content-Disposition: attachment`, fără antet `X-Inertia`):
        // clientul Inertia (`handleNonInertiaResponse()`, `@inertiajs/core`) îl trata ca o
        // excepție HTTP neașteptată și arăta propriul dialog de eroare — niciun eveniment
        // de download nu se declanșa în browser. Fix: `<a href>` simplu, ca la exportul
        // sincron. Testul de mai jos e dovada fixului: `waitForEvent('download')` REAL,
        // exact ca la CSV mai sus, nu o cerere directă către rută.
        const [pdfDownload] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Download PDF' }).click(),
        ]);
        expect(pdfDownload.suggestedFilename()).toBe('orders-export.pdf');

        const pdfPath = await pdfDownload.path();
        expect(pdfPath, 'PDF-ul trebuie să fi ajuns pe disc').toBeTruthy();
        // Primii 5 octeți ai oricărui PDF valid — dovada că fișierul nu e corupt/gol, nu
        // doar că descărcarea „s-a întâmplat".
        const header = await readFile(pdfPath!, { encoding: 'latin1' });
        expect(header.slice(0, 5)).toBe('%PDF-');
    });
});

test.describe('Prag de confirmare peste plafonul rolului (FR-BULK-01) — Agent vs. Manager', () => {
    test.describe('Agent', () => {
        test.use({ storageState: authFile('agent') });

        test('peste 125 de draft-uri proprii: dialogul apare, iar anularea atinge DOAR draft-urile', async ({ page }) => {
            test.setTimeout(180_000);

            const accountId = await firstAccountId(page, ACCOUNTS_URL);

            // Comandă de control, CONFIRMATĂ — dovada că `BulkChunkActions::narrowQuery()`
            // chiar îngustează la `draft` server-side, nu doar filtrul din UI (mai jos,
            // selecția pornește pe „toate statusurile", nu doar „draft").
            const variants = await lookupVariants(page, BASE);
            const variant = variants.find((candidate) => candidate.available > 0);
            expect(variant, 'are nevoie de cel puțin o variantă cu stoc disponibil în seed').toBeTruthy();
            const controlOrderId = await createOrderWithLine(page, BASE, { accountId, variantId: variant!.id, quantity: 1 });
            await confirmOrder(page, BASE, controlOrderId);

            await createDraftOrders(page, BASE, accountId, OVER_AGENT_THRESHOLD);

            await page.goto(`${ORDERS_URL}?filter[owner]=me&filter[status]=`);
            await page.getByRole('table').waitFor();

            await page.getByRole('checkbox', { name: 'Select all orders on this page' }).check();
            await page.getByRole('button', { name: /Select all \d+ orders matching this filter/ }).click();

            // „Select all N" numără TOT filtrul (draft-uri + comanda de control); butonul
            // de anulare numără DOAR draft-urile (`draftTotal`, deferred separat) — cele
            // două cifre diferă intenționat, vezi docblock-ul `BulkSelectionBar.tsx`.
            const cancelButton = page.getByRole('button', { name: /Cancel \d+ draft orders?/ });
            await expect(cancelButton).toBeVisible();
            const draftCount = Number((await cancelButton.textContent())?.match(/\d+/)?.[0]);
            expect(draftCount, 'draft-urile create de fixture trebuie să fie toate în selecție').toBeGreaterThan(AGENT_CONFIRMATION_THRESHOLD);

            await cancelButton.click();

            const dialog = page.getByRole('dialog', { name: `Cancel ${draftCount} draft orders?` });
            await expect(dialog).toBeVisible();
            await dialog.getByRole('button', { name: 'Cancel orders' }).click();
            await expect(dialog).toBeHidden();

            // Pagina de progres ajunge într-o stare terminală (§24.3 pct. 6).
            await page.waitForURL(/\/bulk\/[^/]+$/);
            await expect(page.locator('div[aria-live="polite"]')).toContainText('Done', { timeout: 30_000 });

            // Comanda de control rămâne `confirmed` — anularea n-a atins-o. Starea comenzii
            // e randată de DOUĂ ori pe `Orders/Show` (chip-ul din antet ȘI istoricul
            // „Order timeline"), deci scopăm strict pe chip (`StatusBadge`, lângă `<h1>`).
            await page.goto(`${BASE}/orders/${controlOrderId}`);
            const orderStatusBadge = page.locator('h1').locator('xpath=following-sibling::p[1]').locator('span.rounded-full');
            await expect(orderStatusBadge).toHaveText('Confirmed');

            // Toate draft-urile Agentului sunt acum `cancelled` — filtrul „draft" e gol.
            await page.goto(`${ORDERS_URL}?filter[owner]=me&filter[status]=draft`);
            await expect(page.getByText('No orders match this filter.')).toBeVisible();
        });
    });

    test.describe('Manager', () => {
        test.use({ storageState: authFile('manager') });

        test('peste 125 de rânduri (pragul Agentului), sub pragul de 1.000 al rolului — operația pornește direct, fără dialog', async ({
            page,
        }) => {
            test.setTimeout(180_000);

            const accountId = await firstAccountId(page, ACCOUNTS_URL);
            await createDraftOrders(page, BASE, accountId, OVER_AGENT_THRESHOLD);

            await page.goto(`${ORDERS_URL}?filter[owner]=me&filter[status]=draft`);
            await page.getByRole('table').waitFor();

            await page.getByRole('checkbox', { name: 'Select all orders on this page' }).check();
            await page.getByRole('button', { name: /Select all \d+ orders matching this filter/ }).click();

            const cancelButton = page.getByRole('button', { name: /Cancel \d+ draft orders?/ });
            const draftCount = Number((await cancelButton.textContent())?.match(/\d+/)?.[0]);
            expect(draftCount, 'draft-urile create de fixture trebuie să fie toate în selecție').toBeGreaterThan(AGENT_CONFIRMATION_THRESHOLD);

            await cancelButton.click();

            // Exact contrastul cerut: ACEEAȘI formă de operație, ACELAȘI ordin de mărime
            // al selecției (>125) — dar Managerul n-are plafon de rol sub 1.000, deci
            // pornește direct, fără dialogul de confirmare pe care Agentul îl vede mai sus.
            await expect(page.getByRole('dialog')).toHaveCount(0);
            await page.waitForURL(/\/bulk\/[^/]+$/);
            await expect(page.locator('div[aria-live="polite"]')).toContainText('Done', { timeout: 30_000 });
        });
    });
});
