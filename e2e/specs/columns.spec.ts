import { expect, test, type Locator, type Page } from '@playwright/test';
import { authFile } from '../support/auth';

/**
 * Faza 3, valul 2 (specs.md §15.1, plan §9 „Selector de coloane") — `ColumnSelector.tsx` +
 * `useListColumns.ts`, generic pe orice listă cu vizualizări salvate, verificat aici pe
 * Accounts (3 coloane configurabile — testul complet, inclusiv salvare/redeschidere și
 * `?columns=` invalid) și pe Orders (6 coloane, unele sortabile — versiune mai ușoară,
 * fiindcă mecanismul de sub el e IDENTIC, nu duplicat testul complet pe fiecare resursă).
 *
 * Reordonarea („Move up"/„Move down") se face EXCLUSIV de la tastatură — `.focus()` direct
 * pe control + `page.keyboard.press(...)`, niciodată `.click()` — WCAG 2.2 SC 2.5.7 (fără
 * drag ca unică metodă) și verificarea explicită a capcanei de focus găsite la auditul
 * Fazei 3 (`.ai/rules/frontend.md`, „Focusul nu se pierde niciodată pe `<body>`"): un buton
 * „Move down" ajuns la capăt devine `aria-disabled`, NU `disabled` nativ — dacă ar fi
 * `disabled`, browserul l-ar bluera exact pe controlul care tocmai avea focusul.
 *
 * Rol: Manager — vede toate coloanele (fără restricția „My accounts" a Agentului, care ar
 * face rândurile mai puține și mai greu de verificat determinist) și poate edita variante
 * de produs pentru testul de „Low stock" (FR-STOCK-02).
 */
test.use({ storageState: authFile('manager') });

const ACCOUNTS_URL = '/marlin/accounts';
const ORDERS_URL = '/marlin/orders';
const PRODUCTS_URL = '/marlin/products';

/** Panoul deschis al selectorului de coloane (`role="group"`, vezi `ColumnSelector.tsx`). */
function columnsPanel(page: Page): Locator {
    return page.getByRole('group', { name: 'Visible columns' });
}

/**
 * Coloanele CONFIGURABILE ale unui tabel, în ordinea de afișare curentă — exclude
 * identitatea fixă (a doua coloană, „Name"/„Order number"), checkbox-ul de bulk (prima,
 * fără text vizibil) și „Actions" (ultima, titlu `sr-only` — tot text, deci apare în
 * `textContent`, dar nu e configurabilă). Structura tabelelor Accounts/Orders/Products e
 * fixă exact în această formă (`Index.tsx` al fiecăreia) — un test mai simplu decât un
 * `data-testid` pe fiecare `<th>`, care n-ar exista pentru nimic altceva în aplicație.
 */
async function configurableHeaders(page: Page): Promise<string[]> {
    const all = await page.locator('table thead th').allTextContents();

    // Săgeata de sortare (`aria-hidden="true"`, dar TOT text — `aria-hidden` scoate un nod
    // doar din arborele de accesibilitate, nu din `textContent`) se atașează antetului cu
    // sortare activă (`Orders/Index.tsx`, implicit „Created" — `-created_at`). Coloanele
    // se identifică după etichetă, nu după direcția de sortare curentă.
    return all.slice(2, -1).map((text) => text.replace(/[↑↓]/g, '').trim());
}

test.describe('Selector de coloane — Accounts', () => {
    test('ascunde o coloană, o reordonează de la tastatură până la capăt, salvează vederea și o redeschide', async ({ page }) => {
        await page.goto(ACCOUNTS_URL);
        await page.getByRole('table').waitFor();

        await expect.poll(() => configurableHeaders(page)).toEqual(['Owner', 'Status', 'Created']);

        const columnsButton = page.getByRole('button', { name: 'Columns', exact: true });
        await columnsButton.click();

        const panel = columnsPanel(page);
        await expect(panel).toBeVisible();

        // Ascunde „Status" — checkbox-ul e ÎN interiorul `<label>`, numele accesibil vine din
        // textul etichetei (`ColumnSelector.tsx`), fără capcana asteriscului de `required`
        // (coloanele astea nu sunt un câmp de formular obligatoriu).
        const statusCheckbox = panel.getByRole('checkbox', { name: 'Status', exact: true });
        await statusCheckbox.focus();
        await page.keyboard.press('Space');

        await expect(page.getByRole('columnheader', { name: 'Status', exact: true })).toHaveCount(0);
        await expect.poll(() => configurableHeaders(page)).toEqual(['Owner', 'Created']);

        // Re-arată „Status" — `useListColumns.toggle` o ADAUGĂ LA CAPĂT (`[...columns, key]`),
        // nu-i restaurează poziția canonică: ordinea devine [Owner, Created, Status], nu
        // ordinea implicită. Comportament real, verificat, nu o presupunere — restul
        // testului verifică REORDONAREA pe cele 3 coloane, pornind de aici.
        await statusCheckbox.focus();
        await page.keyboard.press('Space');
        await expect.poll(() => configurableHeaders(page)).toEqual(['Owner', 'Created', 'Status']);

        // Mută „Owner" în jos, o dată: [Owner, Created, Status] → [Created, Owner, Status].
        const moveOwnerDown = panel.getByRole('button', { name: 'Move Owner down', exact: true });
        await moveOwnerDown.focus();
        await page.keyboard.press('Enter');
        await expect.poll(() => configurableHeaders(page)).toEqual(['Created', 'Owner', 'Status']);

        // A doua oară: „Owner" ajunge ULTIMA coloană selectată — [Created, Status, Owner].
        await moveOwnerDown.focus();
        await page.keyboard.press('Enter');
        await expect.poll(() => configurableHeaders(page)).toEqual(['Created', 'Status', 'Owner']);

        // Capătul listei: butonul devine `aria-disabled`, RĂMÂNE focusabil (nu `disabled`
        // nativ) — a treia apăsare e un no-op, ordinea nu se schimbă, iar
        // `document.activeElement` trebuie să rămână chiar acest buton, NICIODATĂ `<body>`.
        await expect(moveOwnerDown).toHaveAttribute('aria-disabled', 'true');
        await page.keyboard.press('Enter');
        await expect.poll(() => configurableHeaders(page)).toEqual(['Created', 'Status', 'Owner']);
        await expect(moveOwnerDown).toBeFocused();

        const activeElementTag = await page.evaluate(() => document.activeElement?.tagName ?? null);
        expect(activeElementTag, 'focusul NU trebuie să fi căzut pe <body> după reordonarea până la capăt').not.toBe('BODY');
        const activeElementLabel = await page.evaluate(() => document.activeElement?.getAttribute('aria-label'));
        expect(activeElementLabel).toBe('Move Owner down');

        // SC 2.4.11 (Focus Not Obscured) — panoul rămâne vizual deschis (nimic în
        // `ColumnSelector.tsx` îl închide la Tab, doar Escape/click în afara lui), deci
        // controlul următor din ordinea de tab NU trebuie să fie ascuns sub el.
        // `elementFromPoint` pe centrul elementului nou focalizat e testul real al
        // criteriului: dacă panoul stă vizual deasupra, punctul din mijloc întoarce un nod
        // din panou, nu elementul focalizat însuși.
        await page.keyboard.press('Tab');
        const isObscured = await page.evaluate(() => {
            const el = document.activeElement;
            if (!el || el === document.body) {
                return true;
            }

            const rect = el.getBoundingClientRect();
            const atCenter = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);

            return !(atCenter === el || (atCenter?.contains(el) ?? false) || el.contains(atCenter));
        });
        expect(isObscured, 'controlul de după panou nu trebuie acoperit vizual de panoul încă deschis').toBe(false);

        // Panoul a rămas vizual deschis după Tab (nimic din `ColumnSelector.tsx` nu-l
        // închide la ieșirea din el prin tastatură, doar Escape/click în afara lui) —
        // reintră explicit în el ca handler-ul de Escape (pe `onKeyDown`-ul panoului, care
        // ascultă doar evenimente ce urcă din INTERIORUL lui) să aibă de unde să prindă
        // apăsarea. Focusul revine pe declanșator la închidere (tiparul comun de disclosure
        // din suită, `SavedViewPicker`/`GlobalSearch`).
        await moveOwnerDown.focus();
        await expect(panel).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(panel).toBeHidden();
        await expect(columnsButton).toBeFocused();

        // Salvează vederea cu ordinea curentă — [Created, Status, Owner] — și redeschide-o
        // dintr-un URL fără NICIUN `columns=` explicit, ca redeschiderea să dovedească
        // efectiv că ordinea a supraviețuit salvării, nu doar că a rămas în URL-ul curent.
        const viewName = `Reordered accounts ${Date.now()}`;
        const viewsButton = page.getByRole('button', { name: 'Views', exact: true });
        await viewsButton.click();
        await page.getByRole('button', { name: 'Save view' }).click();

        const saveDialog = page.getByRole('dialog', { name: 'Save view' });
        await saveDialog.getByLabel('Name').fill(viewName);
        await saveDialog.getByRole('button', { name: 'Save' }).click();
        await expect(saveDialog).toBeHidden();

        await page.goto(ACCOUNTS_URL);
        await page.getByRole('table').waitFor();
        await expect.poll(() => configurableHeaders(page)).toEqual(['Owner', 'Status', 'Created']);

        // Declanșatorul rămâne „Views" (nu numele vederii) — `SavedViewPicker.sameListState()`
        // nu recunoaște propria vedere ca „activă" imediat după salvare, când autorul are
        // filtrul de owner NERESTRÂNS (defect real, documentat pe larg în
        // `saved-views.spec.ts`, aceeași cauză: `pinRoleDependentFiltersForSharing()` pune
        // `owner: "all"` în vederea salvată, absent din `current.filter`). Deschidem panoul
        // cu ACELAȘI declanșator, fără să depindem de indicatorul de „activă".
        await viewsButton.click();
        const viewLink = page.getByRole('link', { name: viewName, exact: true });
        await viewLink.click();

        // Ordinea salvată supraviețuiește redeschiderii — verificarea reală e randarea
        // (antetele), nu forma exactă de encoding a query string-ului `?columns=`.
        await expect(page).toHaveURL(/[?&]columns=/);
        await page.getByRole('table').waitFor();
        await expect.poll(() => configurableHeaders(page)).toEqual(['Created', 'Status', 'Owner']);
    });

    test('un ?columns= cu o cheie inexistentă nu strică pagina — cade pe coloanele implicite', async ({ page }) => {
        await page.goto(`${ACCOUNTS_URL}?columns=doesNotExist`);

        await page.getByRole('table').waitFor();
        await expect.poll(() => configurableHeaders(page)).toEqual(['Owner', 'Status', 'Created']);
    });
});

test.describe('Selector de coloane — Orders', () => {
    test('ascunde și reordonează coloanele de la tastatură, pe o listă cu mai multe coloane sortabile', async ({ page }) => {
        await page.goto(ORDERS_URL);
        await page.getByRole('table').waitFor();

        // Implicitul din `SavedViewResourceType::REGISTRY['orders']['defaultColumns']` —
        // DIFERIT de ordinea canonică a meniului (`permittedColumns`), care începe cu
        // „status": ordinea VIZUALĂ inițială era deja asta înainte de selector.
        await expect.poll(() => configurableHeaders(page)).toEqual(['Grand total', 'Placed at', 'Created', 'Status', 'Account', 'Owner']);

        await page.getByRole('button', { name: 'Columns', exact: true }).click();
        const panel = columnsPanel(page);

        const accountCheckbox = panel.getByRole('checkbox', { name: 'Account', exact: true });
        await accountCheckbox.focus();
        await page.keyboard.press('Space');
        await expect(page.getByRole('columnheader', { name: 'Account', exact: true })).toHaveCount(0);

        const moveStatusUp = panel.getByRole('button', { name: 'Move Status up', exact: true });
        await moveStatusUp.focus();
        await page.keyboard.press('Enter');

        // „Account" ascuns, „Status" mutat cu o poziție înaintea lui „Created": ordinea
        // rămasă (5 coloane vizibile din 6) reflectă ambele schimbări simultan.
        await expect.poll(() => configurableHeaders(page)).toEqual(['Grand total', 'Placed at', 'Status', 'Created', 'Owner']);

        // Antetele sortabile (`grandTotal`/`placedAt`/`createdAt`) rămân operabile după
        // reordonare — regresia plauzibilă ar fi un `sortKey` legat de POZIȚIE, nu de
        // cheia coloanei.
        // `exact: true` — fără el, `getByRole('button', { name: 'Placed at' })` e ambiguu:
        // butoanele „Move Placed at up/down" din panou conțin același substring în eticheta lor.
        await page.getByRole('button', { name: 'Placed at', exact: true }).click();
        await expect(page).toHaveURL(/sort=placed_at/);
    });

    test('un ?columns= cu o cheie inexistentă nu strică pagina Orders', async ({ page }) => {
        await page.goto(`${ORDERS_URL}?columns=bogus`);

        await page.getByRole('table').waitFor();
        await expect.poll(() => configurableHeaders(page)).toEqual(['Grand total', 'Placed at', 'Created', 'Status', 'Account', 'Owner']);
    });
});

test.describe('Low stock — badge pe lista de produse (FR-STOCK-02)', () => {
    /**
     * Determinist, NU bazat pe seed: la scara suitei E2E (`--scale=0.001`,
     * `global-setup.ts`), pragurile de stoc scăzut din `StockAndOrdersSeeder` sunt
     * ALEATORII (fără `mt_srand`, spre deosebire de `ImportFixtureSeeder`) — pe ~15% din
     * variante, fără garanție că vreuna „low" există la o rulare dată. Testul își creează
     * singur un rând „low", editând pragul unei variante reale peste disponibilul ei
     * curent (citit din pagină, nu presupus), exact fluxul pe care un utilizator l-ar
     * folosi din `VariantForm.tsx`.
     */
    test('editarea pragului unei variante peste disponibil face să apară „N low" pe listă', async ({ page }) => {
        await page.goto(PRODUCTS_URL);
        await page.getByRole('table').waitFor();

        const firstProductLink = page.locator('table tbody tr').first().getByRole('link').first();
        const productName = (await firstProductLink.textContent())?.trim();
        expect(productName).toBeTruthy();
        await firstProductLink.click();

        await expect(page).toHaveURL(/\/products\/[^/]+$/);
        const variantRow = page.locator('table tbody tr').first();
        await variantRow.waitFor();

        // Coloana „Available" (specs.md §10.5) — a treia celulă vizibilă pentru Manager
        // (SKU, Price, Cost, Available…), citită direct din DOM, nu recalculată.
        const availableText = await variantRow.getByRole('cell').nth(3).textContent();
        const available = Number((availableText ?? '0').trim());
        expect(Number.isFinite(available)).toBe(true);

        await variantRow.getByRole('link', { name: 'Edit' }).click();
        await expect(page).toHaveURL(/\/variants\/[^/]+\/edit$/);

        const threshold = available + 1000;
        await page.getByLabel('Low stock threshold').fill(String(threshold));
        await page.getByRole('button', { name: 'Save changes' }).click();

        await expect(page).toHaveURL(/\/products\/[^/]+$/);
        await expect(page.locator('table tbody tr').first().getByText('Low stock')).toBeVisible();

        await page.goto(PRODUCTS_URL);
        await page.getByRole('table').waitFor();

        const productRow = page.locator('table tbody tr').filter({ hasText: productName! });
        await expect(productRow.getByText(/\d+ low/)).toBeVisible();
    });
});

test.describe('Focus la navigare (AppLayout, .ai/rules/frontend.md)', () => {
    test('un click pe un link din navigația principală mută focusul pe <main>', async ({ page }) => {
        await page.goto(ACCOUNTS_URL);
        await page.getByRole('table').waitFor();

        const primaryNav = page.getByRole('navigation', { name: 'Primary' });
        await primaryNav.getByRole('link', { name: 'Deals', exact: true }).click();

        await expect(page).toHaveURL(/\/marlin\/deals(\?.*)?$/);
        await expect(page.locator('#main-content')).toBeFocused();
    });

    test('un reload parțial (filtru schimbat pe listă) NU mută focusul de pe control', async ({ page }) => {
        await page.goto(ACCOUNTS_URL);
        await page.getByRole('table').waitFor();

        const statusFilter = page.getByLabel('Status');
        await statusFilter.focus();
        await statusFilter.selectOption('active');

        // `filter[status]=active` pe query string — encodat (`filter%5Bstatus%5D=active`),
        // deci fără paranteze literale în regex.
        await expect(page).toHaveURL(/filter%5Bstatus%5D=active/);
        // Aceeași componentă + aceeași cale (`AppLayout` — cheia navigării, `page.component`
        // + `url.split('?')[0]`) — filtrul e un `preserveState: true`, deci `<main>` nu
        // trebuie să fure focusul din `<select>`.
        await expect(statusFilter).toBeFocused();
    });
});
