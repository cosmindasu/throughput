import { expect, test } from '@playwright/test';
import { authFile } from '../support/auth';

/**
 * §24.3 / plan §8, Livrabile — „Vizualizare salvată creată, partajată prin link, redeschisă
 * cu filtrele/coloanele/sortarea intacte". Pe `Accounts/Index` (`SavedViewPicker.tsx`):
 * Manager filtrează + sortează, salvează ca vizualizare de ECHIPĂ (`visibility: team` —
 * singura formă cu adevărat „partajată": o vedere privată n-ar fi vizibilă Agentului din
 * pasul următor, vezi `SavedViewPolicy::view()`), ia linkul EXACT cum îl oferă UI-ul
 * (`href`-ul de pe numele vederii, nu un URL construit manual), îl deschide într-un
 * context de browser nou, autentificat ca Agent, și verifică filtrele/sortarea — atât în
 * controale, cât și în rândurile afișate.
 *
 * Discrepanță plan ↔ cod, raportată explicit (nu inventată): plan §8 cere și „coloanele"
 * intacte, dar `Accounts/Index.tsx` nu are niciun selector de coloane — tabelul are un set
 * fix (Name/Owner/Status/Created/Actions), iar `SavedView.columns` (populat de
 * `SavedViewController::store()` cu `SavedViewResourceType::defaultColumns()`) nu e citit
 * de nicio pagină încă. Nimic de testat aici pentru „coloane" — vezi raportul agentului.
 *
 * Filtrul de Owner NU e atins deliberat: `list.filter.owner ?? 'all'` face ca opțiunea
 * „All accounts" să apară deja selectată din start pentru Manager (neconstrâns de
 * `AccountList::defaultFilters()`), deci re-alegerea EI din `<select>` nu schimbă valoarea
 * DOM-ului și browserul nu emite `change` — nimic de salvat explicit pe cheia asta. Status
 * și Sort pornesc amândouă de la o valoare DIFERITĂ de cea aleasă mai jos (`''`→`active`,
 * `name`→`-created_at`), deci selecția produce mereu un `change` real, indiferent de
 * ordinea de rulare a testelor din suită.
 */
test.use({ storageState: authFile('manager') });

const ACCOUNTS_URL = '/marlin/accounts';

test('vizualizare de echipă creată pe Accounts, partajată prin link, redeschisă de Agent cu filtrele/sortarea intacte', async ({ page, browser }) => {
    const viewName = `Active accounts, newest first ${Date.now()}`;

    await page.goto(ACCOUNTS_URL);
    await page.getByRole('table').waitFor();

    await page.getByLabel('Status').selectOption('active');
    await expect(page.getByLabel('Status')).toHaveValue('active');

    await page.getByLabel('Sort by').selectOption('-created_at');
    await expect(page.getByLabel('Sort by')).toHaveValue('-created_at');

    // Rândurile s-au reîmprospătat pe noul filtru înainte de a salva vederea — altfel am
    // salva un URL corect, dar n-am ști încă dacă tabelul chiar l-a aplicat.
    const statusCells = page.locator('table tbody tr td:nth-child(3)');
    await expect(statusCells.first()).toBeVisible();
    for (const text of await statusCells.allTextContents()) {
        expect(text.trim().toLowerCase()).toBe('active');
    }

    // `aria-haspopup="true"` e SINGURUL buton din pagină cu acest atribut
    // (`SavedViewPicker.tsx`) — mai stabil decât numele lui accesibil, care se schimbă din
    // „Views" în numele vederii active imediat ce una se potrivește cu starea curentă.
    const viewsButton = page.locator('button[aria-haspopup="true"]');
    await viewsButton.click();

    await page.getByRole('button', { name: 'Save view' }).click();

    const saveDialog = page.getByRole('dialog', { name: 'Save view' });
    await expect(saveDialog).toBeVisible();

    await saveDialog.getByLabel('Name').fill(viewName);
    await saveDialog.getByLabel(/^Team/).check();
    await saveDialog.getByRole('button', { name: 'Save' }).click();

    await expect(saveDialog).toBeHidden();

    await viewsButton.click();
    const viewLink = page.getByRole('link', { name: viewName });
    await expect(viewLink).toBeVisible();

    const href = await viewLink.getAttribute('href');
    expect(href, 'linkul „Views" trebuie să aibă un href real, nu un handler JS').toBeTruthy();
    const applyUrl = new URL(href!, page.url()).toString();

    // Context de browser NOU (nu doar o filă nouă): sesiunea de Agent trebuie să fie
    // complet separată de cea de Manager de mai sus, ca fiecare `storageState` să rămână
    // propriul lui cookie de sesiune.
    const agentContext = await browser.newContext({ storageState: authFile('agent') });

    try {
        const agentPage = await agentContext.newPage();
        await agentPage.goto(applyUrl);

        await expect(agentPage).toHaveURL(/\/marlin\/accounts\?/);
        await agentPage.getByRole('table').waitFor();

        // Controalele — starea salvată de Manager, intactă pentru Agent.
        await expect(agentPage.getByLabel('Status')).toHaveValue('active');
        await expect(agentPage.getByLabel('Sort by')).toHaveValue('-created_at');

        // Rândurile — filtrul de status chiar s-a aplicat pentru Agent, nu doar controlul.
        const agentStatusCells = agentPage.locator('table tbody tr td:nth-child(3)');
        const agentStatusTexts = await agentStatusCells.allTextContents();
        expect(agentStatusTexts.length).toBeGreaterThan(0);
        for (const text of agentStatusTexts) {
            expect(text.trim().toLowerCase()).toBe('active');
        }

        // Sortarea — „Newest" înseamnă `created_at` descrescător; verificăm ordinea TOTALĂ
        // a coloanei „Created", nu doar primele două rânduri.
        const createdTexts = await agentPage.locator('table tbody tr td:nth-child(4)').allTextContents();
        const createdDates = createdTexts.map((text) => new Date(text.trim()).getTime());
        const sortedDescending = [...createdDates].sort((a, b) => b - a);
        expect(createdDates).toEqual(sortedDescending);
    } finally {
        await agentContext.close();
    }
});
