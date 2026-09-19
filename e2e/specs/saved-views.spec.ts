import { expect, test, type Page } from '@playwright/test';
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
 * Coloanele — construite în Faza 3, valul 2 (specs.md §15.1, notă de secvențiere v1.18):
 * `Accounts/Index.tsx` are acum `ColumnSelector`, dar cu doar 3 coloane configurabile
 * (owner/status/createdAt, toate implicit vizibile) n-ar avea nimic de ascuns relevant
 * pentru un test despre FILTRE/SORTARE — verificarea dedicată selectorului de coloane
 * (ascundere, reordonare de la tastatură, salvare/redeschidere) trăiește separat, în
 * `columns.spec.ts`, pe Accounts ȘI pe Orders. Aici rămâne doar asertarea că `columns`
 * (implicitul, neatins) supraviețuiește neschimbat prin salvare/aplicare, ca parte din
 * `sameListState()`.
 *
 * Filtrul de Owner NU e atins deliberat: `list.filter.owner ?? 'all'` face ca opțiunea
 * „All accounts" să apară deja selectată din start pentru Manager (neconstrâns de
 * `AccountList::defaultFilters()`), deci re-alegerea EI din `<select>` nu schimbă valoarea
 * DOM-ului și browserul nu emite `change` — nimic de salvat explicit pe cheia asta. Status
 * și Sort pornesc amândouă de la o valoare DIFERITĂ de cea aleasă mai jos (`''`→`active`,
 * `name`→`-created_at`), deci selecția produce mereu un `change` real, indiferent de
 * ordinea de rulare a testelor din suită.
 *
 * **Defect real găsit la rularea acestei suite, reparat aici** (nu era vina coloanelor —
 * vezi raportul agentului pentru detalii): testul citea coloanele Nume/Status/Created prin
 * `td:nth-child(1|3|4)`, poziții FIXE, valabile doar cât timp tabelul n-avea checkbox de
 * bulk. `9e22a17` (Faza 2, „operații în masă pe conturi") a adăugat coloana de checkbox
 * ÎNAINTEA lui „Name" — indicii nu s-au mai actualizat niciodată. Efectul: `nth-child(1) a`
 * nu mai nimerea NICIUN link (checkbox-ul n-are `<a>`), deci `managerNames`/comparația cu
 * Agentul rulau pe DOUĂ liste goale și treceau vacuu; `nth-child(3)`/`nth-child(4)` citeau
 * de fapt coloanele „Owner"/„Status", nu „Status"/„Created" — de-acolo eșecul (poll-ul
 * aștepta valori „active" într-o coloană de nume de owneri, care n-avea cum să apară
 * vreodată). Coloanele de mai jos se localizează acum după TEXTUL antetului, nu după
 * poziție — rezistă la orice coloană adăugată/mutată în față (checkbox de bulk, selectorul
 * de coloane din `columns.spec.ts`).
 */
test.use({ storageState: authFile('manager') });

const ACCOUNTS_URL = '/marlin/accounts';

/** Celulele unei coloane, localizate după TEXTUL antetului — nu după poziție (vezi docblock-ul fișierului). */
async function columnCells(page: Page, headerName: string) {
    const headers = await page.locator('table thead th').allTextContents();
    const index = headers.findIndex((text) => text.trim() === headerName);
    expect(index, `nicio coloană „${headerName}" în antetul tabelului (${headers.map((text) => text.trim()).join(', ')})`).toBeGreaterThanOrEqual(0);

    return page.locator(`table tbody tr td:nth-child(${index + 1})`);
}

/** Linkurile spre conturi, un rând per link — primul link din fiecare rând (checkbox-ul de bulk n-are `<a>`, „Edit" vine după). */
async function accountNameTexts(page: Page): Promise<string[]> {
    const rows = page.locator('table tbody tr');
    const count = await rows.count();
    const names: string[] = [];

    for (let index = 0; index < count; index++) {
        names.push(((await rows.nth(index).getByRole('link').first().textContent()) ?? '').trim());
    }

    return names;
}

test('vizualizare de echipă creată pe Accounts, partajată prin link, redeschisă de Agent cu filtrele/sortarea intacte', async ({ page, browser }) => {
    const viewName = `Active accounts, newest first ${Date.now()}`;

    await page.goto(ACCOUNTS_URL);
    await page.getByRole('table').waitFor();

    await page.getByLabel('Status').selectOption('active');
    await expect(page.getByLabel('Status')).toHaveValue('active');

    await page.getByLabel('Sort by').selectOption('-created_at');
    await expect(page.getByLabel('Sort by')).toHaveValue('-created_at');

    // Rândurile s-au reîmprospătat pe noul filtru înainte de a salva vederea — altfel am
    // salva un URL corect, dar n-am ști încă dacă tabelul chiar l-a aplicat. `expect.poll`,
    // nu o citire unică: rândurile sunt un prop deferred, reîncărcat după schimbarea URL-ului.
    await expect(page).toHaveURL(/sort=-created_at/);
    // Logica lui `columnCells` INLINE, nu apelată — funcția aruncă (`expect(...)` intern)
    // dacă antetul „Status" lipsește, iar un `expect.poll` NU reîncearcă pe o excepție
    // aruncată de callback-ul lui (doar pe o valoare care nu se potrivește încă) — tabelul
    // dispare tranzitoriu sub `TableSkeleton` cât `accounts` (prop deferred) se
    // reîncarcă după schimbarea de filtru/sortare, exact fereastra pe care polling-ul
    // trebuie s-o traverseze, nu s-o rateze la prima citire.
    await expect
        .poll(async () => {
            const headers = await page.locator('table thead th').allTextContents();
            const index = headers.findIndex((text) => text.trim() === 'Status');
            if (index === -1) {
                return false;
            }

            const texts = await page.locator(`table tbody tr td:nth-child(${index + 1})`).allTextContents();
            return texts.length > 0 && texts.every((text) => text.trim().toLowerCase() === 'active');
        })
        .toBe(true);

    // Rândurile exacte pe care le vede autorul: termenul de comparație pentru Agent. Fără
    // scopul de owner fixat la salvare (`ResourceList::pinRoleDependentFiltersForSharing()`),
    // Agentul primea propriul implicit, „My accounts", și vedea doar o parte din ele.
    const managerNames = await accountNameTexts(page);

    // Selector STABIL — rol + nume accesibil, nu un atribut ARIA incidental
    // (`aria-haspopup="true"` a fost scos din `SavedViewPicker.tsx`: era un rest de pe
    // vremea când testul avea nevoie de el, semnalat de auditul de accesibilitate —
    // disclosure-ul ăsta n-are meniu, are controale native în panou). Numele accesibil al
    // declanșatorului e „Views" cât nicio vedere salvată nu se potrivește cu starea
    // curentă — RĂMÂNE „Views" chiar și DUPĂ salvarea de mai jos, pentru motivul explicat
    // acolo (defect real găsit, nu o alegere de test).
    const viewsButton = page.getByRole('button', { name: 'Views', exact: true });
    await viewsButton.click();

    await page.getByRole('button', { name: 'Save view' }).click();

    const saveDialog = page.getByRole('dialog', { name: 'Save view' });
    await expect(saveDialog).toBeVisible();

    await saveDialog.getByLabel('Name').fill(viewName);
    await saveDialog.getByLabel(/^Team/).check();
    await saveDialog.getByRole('button', { name: 'Save' }).click();

    await expect(saveDialog).toBeHidden();

    // **Defect real găsit aici, NU o alegere de test** — raportat, nu ascuns cu o așteptare
    // mai lungă (ar aștepta la nesfârșit, nu e o problemă de timing): declanșatorul NU-și
    // schimbă numele accesibil în cel al vederii proaspăt salvate, deși starea curentă a
    // ecranului e EXACT ce tocmai s-a salvat. `SavedViewPicker.sameListState()` compară
    // `current.filter` (fără cheia `owner` — Managerul e nerestrâns, `AccountList` n-o pune
    // implicit) cu `view.filter`, care ARE cheia `owner: "all"`: `SavedViewController::store()`
    // trece filtrul prin `ResourceList::pinRoleDependentFiltersForSharing()` (P2-004, deja
    // documentat acolo) — CORECT pentru partajare (linkul trebuie să impună „all", nu
    // implicitul celui care-l deschide), dar `sameListState()` din React nu reproduce
    // aceeași normalizare, deci `currentKeys.length !== viewKeys.length` și „activă" nu
    // devine niciodată adevărat pentru o vedere salvată de un rol nerestrâns. Verificat
    // direct (JSON de la `GET /saved-views/accounts` comparat cu URL-ul curent) — nu e o
    // presupunere. Vezi raportul agentului; testul de mai jos deschide din nou panoul cu
    // ACELAȘI declanșator („Views"), fără să depindă de indicatorul de „activă".
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

        // Controalele — starea salvată de Manager, intactă pentru Agent, inclusiv scopul de
        // owner: „All accounts", nu implicitul Agentului („My accounts"). `<label>` învelește
        // `<select>`-ul, deci numele accesibil include și textele opțiunilor — potrivire pe
        // prefix, nu exactă.
        await expect(agentPage.getByRole('combobox', { name: /^Owner/ })).toHaveValue('all');
        await expect(agentPage.getByLabel('Status')).toHaveValue('active');
        await expect(agentPage.getByLabel('Sort by')).toHaveValue('-created_at');

        // Rândurile — EXACT ce a văzut Managerul, în aceeași ordine. Doar „toate au status
        // active" ar trece și cu Agentul restrâns la propriile conturi.
        await expect.poll(() => accountNameTexts(agentPage)).toEqual(managerNames);
        expect(managerNames.length, 'lista de nume nu trebuie să fie goală — altfel comparația de mai sus trece vacuu').toBeGreaterThan(0);

        const agentStatusTexts = await (await columnCells(agentPage, 'Status')).allTextContents();
        for (const text of agentStatusTexts) {
            expect(text.trim().toLowerCase()).toBe('active');
        }

        // Sortarea — „Newest" înseamnă `created_at` descrescător; verificăm ordinea TOTALĂ
        // a coloanei „Created", nu doar primele două rânduri.
        const createdTexts = await (await columnCells(agentPage, 'Created')).allTextContents();
        const createdDates = createdTexts.map((text) => new Date(text.trim()).getTime());
        const sortedDescending = [...createdDates].sort((a, b) => b - a);
        expect(createdDates).toEqual(sortedDescending);
    } finally {
        await agentContext.close();
    }
});
