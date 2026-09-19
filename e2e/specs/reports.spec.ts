import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { createReport } from '../support/api';
import { authFile } from '../support/auth';
import { inertiaPageProps } from '../support/inertia';

/**
 * Faza 4 (specs.md §16, plan §10) — rapoarte, rulare manuală (US-REP-02) și istoricul de
 * rulări (FR-REP-01), pe raportul built-in „Deal Velocity by Stage" (nu expune cost, deci
 * previzualizarea e vizibilă indiferent de rol — spre deosebire de „Inventory Valuation").
 *
 * **Regresia de polling (`.ai/rules/frontend.md`)** — găsită la review-ul Fazei 4: „Run now"
 * nu actualiza niciodată pagina, deși mesajul flash promitea exact asta („this page will
 * update automatically"), fiindcă `autoStart` se evaluase o singură dată, la montare, când
 * raportul încă n-avea nicio rulare. Testul de mai jos apasă „Run now" pe pagina DEJA
 * deschisă și așteaptă starea finală DOAR prin `usePoll`-ul real (fără `page.reload()`) —
 * dacă fix-ul simetric `start()`/`stop()` ar regresa, testul ar pica pe timeout, nu ar trece
 * „din greșeală".
 *
 * **Defect real de produs găsit de acest lot — REPARAT**: `ReportForm.tsx` (`submit()`)
 * construia un `payload` cu `recipients` SPLIT într-un array și îl trimitea ca
 * `post(action, { data: payload })`/`put(action, { data: payload })`. Semnătura reală a lui
 * `useForm().post()`/`.put()` din `@inertiajs/react` e `post(url, options)`, unde `options`
 * sunt opțiuni de vizită (`onSuccess`, `preserveScroll` etc.), NU o suprascriere a corpului
 * cererii — corpul TRIMIS era mereu `transformRef.current(dataRef.current)`, adică STAREA
 * PROPRIE a hook-ului, netransformată. Cheia `options.data` (aici, `payload`) era pur și
 * simplu IGNORATĂ. Consecință: `recipients` ajungea pe server ca STRING brut din textarea
 * (nu array), iar `StoreReportRequest`/`UpdateReportRequest`
 * (`'recipients' => ['required','array','min:1']`) refuza ORICE trimitere cu „The recipients
 * field must be an array." — **niciun raport nu putea fi creat sau editat din interfață**,
 * indiferent de conținutul introdus, deși toate testele de server (Pest) treceau — nu ating
 * formularul. Fix (aplicat de alt lot, confirmat aici): `transform(() => payload)` ÎNAINTE de
 * `post(action)`/`put(action)` (fără opțiuni) — mecanismul REAL de transformare al Inertia.
 * Testul principal de mai jos creează raportul prin FORMULARUL real (nu prin API): motivul
 * pentru care fixture-ul ocolea formularul (era stricat) a dispărut, iar un test care trece
 * prin `ReportForm.tsx` e exact ce ar fi prins regresia.
 *
 * Are nevoie de worker de coadă (al doilea `webServer`, cozile `default`) — `GenerateReportJob`
 * ȘI `DeliverReportJob` rulează pe `default`.
 */
test.use({ storageState: authFile('manager') });

const BASE = '/marlin';
const REPORTS_URL = `${BASE}/reports`;

const ts = Date.now();
const REPORT_NAME = `E2E Deal Velocity ${ts}`;
const EXTERNAL_RECIPIENT = `external-accountant-e2e-${ts}@example.com`;

/**
 * Regresie IZOLATĂ, minimă și rapidă pentru defectul descris în docblock-ul fișierului
 * (reparat — vezi `ReportForm.tsx`, `transform(() => payload)`): verifică STRICT că
 * formularul „Create report" chiar creează raportul și navighează la `Reports/Show`, prin
 * pașii pe care i-ar face un utilizator real. Fixture propriu (nume separat de testul
 * principal de mai jos), ca cele două teste să rămână independente.
 */
test('regresie — „Create report" se finalizează din UI (recipients ajunge ca array, nu ca string)', async ({ page }) => {
    const name = `E2E Report Form Regression ${ts}`;

    await page.goto(`${REPORTS_URL}/create`);
    await page.getByLabel('Report name').fill(name);
    await page.getByRole('radio', { name: 'Deal Velocity by Stage' }).check();
    await page.getByLabel('Recipients').fill(`report-form-regression-${ts}@example.com`);

    await page.getByRole('button', { name: 'Create report' }).click();

    await expect(page).toHaveURL(/\/reports\/(?!create)[^/]+$/, { timeout: 10_000 });
    await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
});

test('creează un raport built-in prin FORMULARUL real, îl rulează manual pe pagina deja deschisă și vede rezultatul + istoricul, fără reload', async ({
    page,
}) => {
    test.setTimeout(60_000);

    // Fluxul REAL prin `ReportForm.tsx` — nu mai există motiv să ocolim formularul (era
    // stricat, vezi docblock-ul fișierului; acum e reparat și confirmat de testul de mai sus).
    await page.goto(`${REPORTS_URL}/create`);
    await page.getByLabel('Report name').fill(REPORT_NAME);
    await page.getByRole('radio', { name: 'Deal Velocity by Stage' }).check();
    // Format rămâne implicit „CSV", frecvența implicită „Manual only" — US-REP-02 nu cere
    // programare pentru „Run now".
    await page.getByLabel('Recipients').fill(EXTERNAL_RECIPIENT);
    await page.getByRole('button', { name: 'Create report' }).click();

    await expect(page).toHaveURL(/\/reports\/(?!create)[^/]+$/, { timeout: 10_000 });
    await expect(page.getByRole('heading', { name: REPORT_NAME, level: 1 })).toBeVisible();

    // US-REP-02 — rezultatul built-in, randat sincron în pagină, fără să aștepte email.
    await expect(page.getByRole('heading', { name: 'Current result' })).toBeVisible();

    await expect(page.getByRole('heading', { name: 'Run history' })).toBeVisible();
    await expect(page.getByText('This report hasn’t run yet.')).toBeVisible();

    // `getByRole('status')` e ambiguu pe acest ecran: bannerul `flash.success` de mai jos
    // („Report queued…") folosește TOT `role="status"` — scopăm strict pe regiunea de status
    // a rulării (`Reports/Show.tsx`, `statusRef`), la fel ca `orders-bulk.spec.ts`.
    const runStatusRegion = page.locator('div[aria-live="polite"]');

    await page.getByRole('button', { name: 'Run now' }).click();

    // Focus „după submit" — declanșatorul rămâne pe pagină (nu dispare), dar starea se
    // schimbă sub el: `onSuccess` mută focusul explicit pe regiunea de status VIZIBILĂ
    // (`.ai/rules/frontend.md`, „declanșatorul dispare după succes"/rândul din listă e
    // înlocuit — aici declanșatorul RĂMÂNE, dar mutarea explicită a focusului rămâne totuși
    // necesară fiindcă pagina nu se remontează).
    await expect(runStatusRegion).toBeFocused();

    // REGRESIA DE POLLING — fără reload, fără `waitForTimeout`: doar `usePoll`-ul paginii.
    await expect(runStatusRegion).toContainText('Success', { timeout: 30_000 });
    expect(await page.evaluate(() => document.activeElement?.tagName ?? null), 'focusul nu trebuie să cadă pe <body> după succes').not.toBe('BODY');

    // Istoricul de rulări — cea mai recentă rulare (`orderByDesc('id')`) e primul rând.
    const runRows = page.getByRole('table', { name: `Run history for ${REPORT_NAME}` }).locator('tbody tr');
    await expect(runRows).toHaveCount(1);
    await expect(runRows.first()).toContainText('Success');
    await expect(runRows.first()).toContainText('manual');

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        runRows.first().getByRole('link', { name: /^Download the run from/ }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/\.csv$/);
    const path = await download.path();
    expect(path, 'fișierul rulării trebuie să fi ajuns pe disc').toBeTruthy();

    // Axe-core pe conținutul BOGAT al `Reports/Show` (previzualizare built-in + istoric cu o
    // rulare reușită) — temă implicită (închisă) doar, bonus față de matricea completă pe 2
    // teme din `a11y.spec.ts` (care scanează Reports doar în starea GOALĂ, pe DB proaspătă).
    const axeResults = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'])
        .analyze();
    const criticalViolations = axeResults.violations.filter((violation) => violation.impact === 'critical');
    expect(criticalViolations, JSON.stringify(criticalViolations, null, 2)).toEqual([]);
});

/**
 * §7.4/§7.5 — Agent are „R (doar cele unde e destinatar)", o îngustare ABAC peste
 * `reports.view` (`ReportRecipients::scopeVisibleTo`), aplicată și în LISTĂ, nu doar pe
 * pagina de detaliu. Verificat cu DOUĂ rapoarte (nu unul): un Agent care nu vede NIMIC ar
 * trece testul și dacă mecanismul ar ascunde pur și simplu tot ecranul de la Agent,
 * indiferent de destinatari — al doilea raport, cu Agentul chiar în `recipients`, dovedește
 * că filtrarea e pe CONȚINUT, nu pe rol în bloc.
 */
test.describe('RBAC — Reports (Agent, doar destinatar)', () => {
    test('Agent nu vede raportul unde NU e destinatar, dar vede unul unde E destinatar', async ({ page, browser }) => {
        const notRecipientName = `E2E Report Not Recipient ${ts}`;
        const isRecipientName = `E2E Report Is Recipient ${ts}`;

        await createReport(page, BASE, { name: notRecipientName, reportType: 'deal_velocity', recipients: [EXTERNAL_RECIPIENT] });

        const agentContext = await browser.newContext({ storageState: authFile('agent') });
        try {
            const agentPage = await agentContext.newPage();
            const props = await inertiaPageProps<{ auth: { user: { email: string } } }>(agentPage, `${BASE}/dashboard`);
            const agentEmail = props.auth.user.email;

            await createReport(page, BASE, { name: isRecipientName, reportType: 'deal_velocity', recipients: [agentEmail] });

            await agentPage.goto(REPORTS_URL);
            await expect(agentPage.getByRole('link', { name: notRecipientName })).toHaveCount(0);
            await expect(agentPage.getByRole('link', { name: isRecipientName })).toBeVisible();
        } finally {
            await agentContext.close();
        }
    });
});

/** Golul explicit din task: Viewer n-are `reports.view`/`reports.manage` deloc (§7.4). */
test.describe('RBAC — Reports (Viewer)', () => {
    test.use({ storageState: authFile('viewer') });

    test('„Reports" absent din navigație pentru Viewer', { tag: ['@smoke'] }, async ({ page }) => {
        await page.goto(`${BASE}/dashboard`);
        await expect(page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'Reports' })).toHaveCount(0);
    });
});
