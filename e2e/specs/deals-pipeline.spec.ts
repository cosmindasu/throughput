import { expect, test, type Locator, type Page } from '@playwright/test';
import { authFile } from '../support/auth';

/**
 * §24.3 pct. 3 / plan §8, Livrabile — un deal creat din `/{w}/deals/create?account={id}`
 * (id preluat din UI, nu construit din memorie) și mutat prin 3 etape, cu istoricul
 * verificat pe `Deals/Show`. Două variante, teste separate: drag & drop pe kanban (a) și
 * exclusiv de la tastatură, prin meniul „Move to stage…" (b) — FR-DEAL-01, WCAG 2.2 SC 2.5.7.
 *
 * Rol: Manager (`authFile('manager')`) — cel mai simplu rol care poate muta ORICE deal
 * (`DealPolicy::isWithinOwnRecords` nu-l restrânge, spre deosebire de Agent) și poate crea
 * deal-uri fără să aleagă alt owner. Titluri unice (sufix `Date.now()`), ca testele să nu
 * depindă de ordinea de rulare și să nu calce peste `roles.spec.ts`/`workspace-switch.spec.ts`
 * (alt tenant, alte assert-uri, dar cardurile kanban ale acestui tenant sunt împărțite cu
 * `a11y.spec.ts`, care doar CITEȘTE ecranul, nu mută nimic).
 *
 * Etapele canonice ale pipeline-ului implicit (`CatalogSeeder::run()`, aceleași la orice
 * scară de seed): New(1) → Qualified(2) → Proposal Sent(3) → Negotiation(4) → Won(5, terminal)
 * → Lost(6, terminal). Mutăm STRICT prin cele patru etape deschise (New→Qualified→Proposal
 * Sent→Negotiation) — niciodată pe Won/Lost: `MoveDealStageAction` cere `value` pentru Won
 * și un `lost_reason` pentru Lost, ambele în afara scopului acestui test (verifică DOAR
 * tranziția + istoricul, nu regulile de stare terminală, deja acoperite de
 * `tests/Feature/Deals/MoveDealStageActionTest.php`).
 *
 * Numărul de rânduri așteptat în `deal_stage_events`: 4, NU 3. `CreateDealAction` inserează
 * deja primul rând la creare (`from_stage_id = null`, evenimentul „Created on New" —
 * vezi docblock-ul clasei), deci cele 3 mutări de mai jos ADAUGĂ 3 rânduri peste acela,
 * total 4. `Deals/Show` le arată pe toate patru, cele mai recente primele
 * (`orderByDesc('changed_at')` în `DealController::show()`), deci ordinea vizibilă e
 * inversă față de ordinea cronologică a mutărilor.
 */
test.use({ storageState: authFile('manager') });

const WORKSPACE = '/marlin';

/**
 * Creează un deal din UI: Accounts → primul cont din listă → „New deal" → completează
 * Title → „Create deal". Contul e „luat din UI" (primul rând al listei), nu dintr-un id
 * memorat — dacă `AccountCombobox` (schimbare în paralel, alt pachet) a înlocuit între timp
 * câmpul de cont din `Deals/Create`, fluxul de mai jos NU-l atinge deloc: `?account=` e deja
 * pus pe URL de linkul „New deal" de pe `Accounts/Show`, înainte ca formularul să se randeze.
 *
 * Valoarea deal-ului rămâne necompletată (US-DEAL-01 — „Leave blank until qualified"): niciuna
 * din cele 3 etape vizate (Qualified/Proposal Sent/Negotiation) nu e terminală, deci
 * `MoveDealStageAction` nu cere `value` aici.
 */
async function createDealFromFirstAccount(page: Page, title: string): Promise<string> {
    await page.goto(`${WORKSPACE}/accounts`);
    await page.getByRole('table').waitFor();

    const firstAccountLink = page.locator('table tbody tr').first().getByRole('link').first();
    await firstAccountLink.click();

    const newDealLink = page.getByRole('link', { name: 'New deal' });
    await expect(newDealLink).toBeVisible();
    await newDealLink.click();

    await expect(page).toHaveURL(/\/deals\/create\?account=/);

    // `getByLabel` s-a dovedit nesigur aici: eticheta „Title" e obligatorie
    // (`Field label="Title" required`), deci DOM-ul ei conține și un `<span aria-hidden>
    // *</span>` — textul BRUT al `<label>` e „Title *", nu „Title" (asteriscul e exclus
    // doar din NUMELE ACCESIBIL calculat pentru INPUT, nu din textul etichetei folosit de
    // `getByLabel`). `getByRole('textbox', { name: 'Title' })` citește exact numele
    // accesibil calculat de browser (confirmat în ARIA snapshot-ul eșecului inițial:
    // `textbox "Title"`), deci nu are aceeași capcană.
    await page.getByRole('textbox', { name: 'Title', exact: true }).fill(title);
    await page.getByRole('button', { name: 'Create deal' }).click();

    // `DealController::store()` redirige spre `deals.show` — un ULID pe URL, nu `/create`.
    // Lookahead negativ, NU doar `[^/?]+$`: acel pattern ar accepta și literalul „create"
    // (rămas pe ecran la un 422 de validare) — regresie prinsă manual la scrierea acestui
    // test pe fluxul de conturi (`global-search.spec.ts`, vezi bug-ul reparat în
    // `AccountForm.tsx`).
    await expect(page).toHaveURL(/\/deals\/(?!create)[^/?]+$/);

    return page.url();
}

/** Rândurile secțiunii „Stage history" de pe `Deals/Show`, cele mai recente primele. */
function stageHistoryTexts(page: Page): Locator {
    return page.locator('section[aria-label="Stage history"] li > span').filter({ hasNotText: '·' });
}

async function expectFullStageHistory(page: Page, dealUrl: string): Promise<void> {
    await page.goto(dealUrl);

    const history = stageHistoryTexts(page);
    await expect(history).toHaveCount(4);
    await expect(history).toHaveText([
        'Proposal Sent → Negotiation',
        'Qualified → Proposal Sent',
        'New → Qualified',
        'Created on New',
    ]);
}

test('Manager creează un deal și îl mută prin 3 etape cu drag & drop pe kanban', async ({ page }) => {
    const title = `Drag deal ${Date.now()}`;
    const dealUrl = await createDealFromFirstAccount(page, title);
    const dealId = new URL(dealUrl).pathname.split('/').pop();

    // Un singur eveniment la creare — baza de comparație pentru „4, nu 3" din docblock-ul
    // de mai sus.
    await expect(stageHistoryTexts(page)).toHaveCount(1);
    await expect(stageHistoryTexts(page)).toHaveText(['Created on New']);

    await page.goto(`${WORKSPACE}/deals/board`);

    const cardSelector = `#deal-card-${dealId}`;

    /**
     * `DealCard.tsx` folosește HTML5 drag NATIV (`draggable`, `onDragStart` pe `<div>`) —
     * nu un handler „pointer" (mousedown/mousemove/mouseup) pe care `locator.dragTo()`
     * l-ar putea simula direct. Încercat întâi `locator.dragTo()`: Playwright îl
     * implementează ca o secvență de evenimente de MOUSE (`mouse.move` + `mouse.down` +
     * `mouse.move` pas cu pas + `mouse.up`), care NU produce evenimentele native
     * `dragstart`/`dragover`/`drop` pe care browserul le declanșează doar în timpul unui
     * gest de drag OS real — Chromium nu „promovează" o secvență de mouse simulată prin
     * CDP la un drag HTML5 nativ (limitare cunoscută, documentată chiar de Playwright:
     * https://playwright.dev/docs/input#drag-and-drop, secțiunea „manual" pentru exact
     * acest caz). Confirmat local: `dragTo()` mișcă mouse-ul peste coloana țintă, dar
     * `Kanban.tsx::handleDrop` nu se apelează niciodată (`draggedDeal` rămâne `null`).
     *
     * Soluția documentată de Playwright pentru acest caz: se dispecerizează manual
     * evenimentele `dragstart`/`dragover`/`drop`/`dragend`, cu un `DataTransfer` REAL
     * (creat în pagină, prin `evaluateHandle`, ca `dispatchEvent` să poată trece un obiect
     * complex, nu doar valori serializabile JSON). `Kanban.tsx::handleDrop` nu citește
     * conținutul `DataTransfer` (folosește starea React `draggedDeal`, setată de
     * `onDragStart`), deci un `DataTransfer` gol e suficient — doar TIPUL evenimentului
     * contează pentru handler-ele React, nu payload-ul lui.
     */
    async function dragDealCardTo(targetStageName: string): Promise<void> {
        const source = page.locator(cardSelector);
        const target = page.getByRole('region', { name: targetStageName });

        const dataTransfer = await page.evaluateHandle(() => new DataTransfer());

        await source.dispatchEvent('dragstart', { dataTransfer });
        await target.dispatchEvent('dragover', { dataTransfer });
        await target.dispatchEvent('drop', { dataTransfer });
        await source.dispatchEvent('dragend', { dataTransfer });
    }

    await dragDealCardTo('Qualified');
    await expect(page.getByRole('region', { name: 'Qualified' }).locator(cardSelector)).toBeVisible();
    await expect(page.getByText(`Moved ${title} to Qualified`)).toBeVisible();

    await dragDealCardTo('Proposal Sent');
    await expect(page.getByRole('region', { name: 'Proposal Sent' }).locator(cardSelector)).toBeVisible();
    await expect(page.getByText(`Moved ${title} to Proposal Sent`)).toBeVisible();

    await dragDealCardTo('Negotiation');
    await expect(page.getByRole('region', { name: 'Negotiation' }).locator(cardSelector)).toBeVisible();
    await expect(page.getByText(`Moved ${title} to Negotiation`)).toBeVisible();

    await expectFullStageHistory(page, dealUrl);
});

test('Manager mută un deal prin 3 etape exclusiv de la tastatură, cu meniul "Move to stage…"', async ({ page }) => {
    const title = `Keyboard deal ${Date.now()}`;
    const dealUrl = await createDealFromFirstAccount(page, title);

    const trigger = page.getByRole('button', { name: 'Move to stage…' });

    /**
     * Punctul de plecare „focusul a ajuns pe card": cardul kanban are `tabIndex={-1}`
     * (focus DOAR programatic — vezi `DealCard.tsx`), deci pe `Deals/Show` (unde același
     * `MoveStageMenu` există direct în antet, fără card-wrapper) singurul element
     * relevant pentru FR-DEAL-01 e chiar declanșatorul „Move to stage…". `.focus()`
     * stabilește acest punct de start FĂRĂ niciun click — de aici încolo, absolut orice
     * acțiune e prin `page.keyboard`, niciodată `.click()`.
     */
    await trigger.focus();
    await expect(trigger).toBeFocused();

    // Esc — deschide, apoi anulează fără nicio mutare (demonstrează tasta cerută explicit
    // de FR-DEAL-01/WCAG 2.5.7, separat de fluxul de alegere reală de mai jos).
    await page.keyboard.press('Enter');
    const menu = page.getByRole('menu', { name: 'Move to stage' });
    await expect(menu).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(stageHistoryTexts(page)).toHaveText(['Created on New']);

    /**
     * Alege o etapă din meniu EXCLUSIV cu tastatura: Enter deschide (focusul intră automat
     * pe prima opțiune — `MoveStageMenu.tsx`), săgețile navighează, Enter alege. Indexul
     * țintei se CITEȘTE din meniul randat (nu se presupune o poziție fixă): opțiunile sunt
     * „toate etapele pipeline-ului, în afară de cea curentă, în ordinea `position`" — poziția
     * etapei țintă în această listă depinde de etapa curentă, deci se recalculează la fiecare
     * mutare (după prima mutare, „New" reintră în listă, „Qualified" dispare etc).
     */
    async function chooseStageViaKeyboard(targetStageName: string): Promise<void> {
        await trigger.focus();
        await page.keyboard.press('Enter');
        await expect(menu).toBeVisible();

        const items = menu.getByRole('menuitem');
        const labels = await items.allTextContents();
        const targetIndex = labels.indexOf(targetStageName);
        expect(targetIndex, `"${targetStageName}" ar trebui să fie o opțiune a meniului (opțiuni: ${labels.join(', ')})`).toBeGreaterThanOrEqual(0);

        for (let i = 0; i < targetIndex; i++) {
            await page.keyboard.press('ArrowDown');
        }

        await page.keyboard.press('Enter');

        // Sincronizare cu răspunsul serverului (`router.patch`, Inertia) ÎNAINTE de a
        // continua: fără ea, a doua deschidere a meniului ar citi încă `currentStageId`
        // vechi din props-urile neactualizate. Fără `waitForTimeout` — se așteaptă direct
        // efectul observabil (rândul nou din istoric, verificat exact de apelant imediat
        // după fiecare chemare a acestei funcții).
        await expect(stageHistoryTexts(page).first()).toContainText('→');
        await expect(stageHistoryTexts(page).first()).toContainText(targetStageName);
    }

    await chooseStageViaKeyboard('Qualified');
    await expect(stageHistoryTexts(page).first()).toHaveText('New → Qualified');
    await expect(trigger).toBeFocused();

    await chooseStageViaKeyboard('Proposal Sent');
    await expect(stageHistoryTexts(page).first()).toHaveText('Qualified → Proposal Sent');

    await chooseStageViaKeyboard('Negotiation');
    await expect(stageHistoryTexts(page).first()).toHaveText('Proposal Sent → Negotiation');

    await expectFullStageHistory(page, dealUrl);
});
