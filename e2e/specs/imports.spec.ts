import { readFile } from 'node:fs/promises';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { authFile, DEMO_ROLE_LABELS, type DemoRole } from '../support/auth';

/**
 * Faza 4 (specs.md §14, plan §10) — import CSV în 4 pași (Imports/Show.tsx), pe resursa cea
 * mai simplă a mecanismului (`accounts`, un singur câmp obligatoriu — `AccountImportResource`).
 *
 * **Defect real de produs găsit de acest lot — REPARAT** (regresia de polling, EXACT clasa
 * de bug cerută explicit pentru acest lot, dar cu o cauză DIFERITĂ de cea documentată în
 * `.ai/rules/frontend.md`): `Imports/Show.tsx` AVEA deja forma corectă `start()`/`stop()`
 * simetrică — codul „arăta" complet corect. Bug-ul real era în BACKEND:
 * `App\Actions\Imports\RunDryRunValidationAction::execute()` nu seta `status =
 * 'validating'` SINCRON, în cererea care răspunde la clic (doar reseta
 * `total_rows`/`valid_rows`/`error_rows`/`completed_at`) — tranziția reală de status se
 * întâmpla abia în `RunDryRunValidationJob::handle()`, ASINCRON, în coadă. Contrasta cu
 * `App\Actions\Imports\CommitImportAction::execute()`, care SETEAZĂ `status = 'importing'`
 * sincron, în același `UPDATE` atomic care verifică starea curentă — tiparul corect. Efect:
 * la randarea care urma imediat clicului pe „Run dry-run validation", `import.status` era
 * ÎNCĂ `mapped`, deci `isRunning` (`RUNNING_STATUSES.includes(...)`) era `false` chiar la
 * acel unic render — `usePoll`-ul corect din cod n-avea ce `start()` să declanșeze, pagina
 * rămânea blocată pe „Step 2 of 4 — Columns mapped" la NESFÂRȘIT, deși serverul termina
 * proba uscată în câteva secunde (verificat direct în bază, la momentul găsirii: `status =
 * validated, total_rows = 4, valid_rows = 2, error_rows = 2`, în timp ce browserul arăta încă
 * „Columns mapped"). Diagnosticul a scos la iveală și un AL DOILEA defect, pe aceeași linie:
 * garda `where('status', MAPPED)` nu serializa nimic cât timp `status` rămânea `mapped`, deci
 * două POST-uri concurente pe „Run dry-run validation" treceau amândouă. Fix (aplicat de alt
 * lot, confirmat aici): `RunDryRunValidationAction::execute()` include acum
 * `'status' => Import::STATUS_VALIDATING` în ACELAȘI `UPDATE` atomic — repară ambele defecte
 * cu o singură linie. Testul principal de mai jos verifică asta FĂRĂ reload (regresie
 * blocată, nu doar reparată o dată).
 *
 * Are nevoie de un worker de coadă (`e2e/playwright.config.ts`, al doilea `webServer`,
 * cozile `imports`/`default`) — fără el, `RunDryRunValidationJob`/`FinalizeImportDryRunJob`/
 * `CommitImportJob` rămân `pending`, iar polling-ul de mai jos ar pica pe timeout, nu pe un
 * defect real.
 *
 * Fixture: un CSV de 4 rânduri, cu erori PLANTATE determinist (nu bazate pe seed aleator):
 *   - rândul 2 — valid.
 *   - rândul 3 — „Company Name" gol → eroare de câmp obligatoriu.
 *   - rândul 4 — același domeniu ca rândul 2 → duplicat ÎN FIȘIER (`ImportDryRunFinalizer`).
 *   - rândul 5 — valid.
 * Domeniile poartă un sufix `Date.now()`, ca „duplicat" să fie garantat un duplicat ÎN
 * FIȘIER, nu o coincidență cu un cont deja semănat de `DemoDatasetSeeder`.
 */
test.use({ storageState: authFile('manager') });

const BASE = '/marlin';
const IMPORTS_URL = `${BASE}/imports`;

const ts = Date.now();

const CSV_HEADERS = ['Company Name', 'Domain', 'Industry', 'Phone', 'Source'];

const HEADER_TO_FIELD: Record<string, string> = {
    'Company Name': 'name',
    Domain: 'domain',
    Industry: 'industry',
    Phone: 'phone',
    Source: 'source',
};

const DUPLICATE_DOMAIN = `e2e-import-acme-${ts}.example`;

const CSV_ROWS = [
    CSV_HEADERS,
    [`E2E Import Acme ${ts}`, DUPLICATE_DOMAIN, 'Manufacturing', '555-0100', 'Import test'],
    ['', `e2e-import-beta-${ts}.example`, 'Retail', '555-0101', 'Import test'],
    [`E2E Import Gamma ${ts}`, DUPLICATE_DOMAIN, 'Services', '555-0102', 'Import test'],
    [`E2E Import Delta ${ts}`, `e2e-import-delta-${ts}.example`, 'Logistics', '555-0103', 'Import test'],
];

const CSV_CONTENT = CSV_ROWS.map((row) => row.join(',')).join('\n');

/** Rândul din tabelul de mapare (Pasul 2) al cărui prim `<td>` e antetul CSV dat. */
/**
 * Scopat strict pe PRIMUL `<td>` (antetul CSV al rândului) — `<select>`-ul din al doilea
 * `<td>` conține TOATE etichetele câmpurilor ca `<option>` (inclusiv „Company name
 * (required)"), deci un `hasText` neascopat pe orice `td` din rând potrivea, greșit, TOATE
 * rândurile pentru orice antet (defect de test găsit la prima rulare, nu un defect de produs
 * — corectat aici, nu mascat).
 */
function mappingRow(page: Page, header: string) {
    return page.locator('table tbody tr').filter({ has: page.locator('td:first-child').getByText(header, { exact: true }) });
}

test('import de conturi în 4 pași: auto-mapare, probă uscată cu erori cunoscute, commit, raport de erori — polling REAL, fără reload', async ({
    page,
}) => {
    test.setTimeout(120_000);

    // Pasul 1 — Upload.
    await page.goto(`${IMPORTS_URL}/create`);
    await expect(page.getByRole('heading', { name: 'New import' })).toBeVisible();

    // Implicitul `resources[0]` e deja „Accounts" (`ImportableResources::map()`, prima cheie).
    await expect(page.getByLabel('What are you importing?')).toHaveValue('accounts');

    await page.getByLabel('File (CSV or XLSX)').setInputFiles({
        name: 'e2e-accounts-import.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(CSV_CONTENT, 'utf-8'),
    });
    await page.getByRole('button', { name: 'Upload' }).click();

    await expect(page).toHaveURL(/\/imports\/[^/]+$/);
    await expect(page.getByRole('heading', { name: 'Step 1 of 4 — Upload done' })).toBeVisible();

    // Pasul 2 — Mapare. US-IMP-02: verifică întâi că auto-maparea a nimerit toate cele 5
    // coloane evidente, cu încredere „High" (potrivire exactă pe alias, `ColumnMappingSuggester`).
    for (const header of CSV_HEADERS) {
        const row = mappingRow(page, header);
        await expect(row.getByText('High confidence')).toBeVisible();
        await expect(row.locator('select')).toHaveValue(HEADER_TO_FIELD[header]);
    }

    // Defect deliberat: demapează „Company Name" (singurul câmp obligatoriu al resursei) și
    // verifică refuzul serverului — `UpdateImportMappingRequest::withValidator()`. Verificare
    // de FOCUS „după eroare" (`.ai/rules/frontend.md`, defectul cel mai repetat al
    // proiectului): butonul de submit e `aria-disabled`, NICIODATĂ `disabled` nativ cât
    // procesează, deci rămâne focusabil — focusul NU are voie să cadă pe `<body>`.
    const saveMappingButton = page.getByRole('button', { name: 'Save mapping and continue' });
    await page.getByLabel('Column for Company Name').selectOption('');
    await saveMappingButton.click();

    await expect(page.getByRole('alert')).toContainText('is required for Accounts and must be mapped to a column');
    await expect(saveMappingButton).toBeFocused();
    expect(await page.evaluate(() => document.activeElement?.tagName ?? null), 'focusul nu trebuie să cadă pe <body> după o eroare de validare').not.toBe(
        'BODY',
    );

    // Corectează maparea și continuă — starea rămâne `uploaded` până la acest submit reușit.
    await page.getByLabel('Column for Company Name').selectOption('name');
    await saveMappingButton.click();

    // Succes — tranziție de status (`uploaded` → `mapped`), deci focusul se mută pe titlul
    // pasului curent (`statusHeadingRef`, `.ai/rules/frontend.md`).
    const mappedHeading = page.getByRole('heading', { name: 'Step 2 of 4 — Columns mapped' });
    await expect(mappedHeading).toBeVisible();
    await expect(mappedHeading).toBeFocused();

    // Pasul 3 — Probă uscată. Pagina e DEJA deschisă, aceeași rută Inertia pe tot parcursul —
    // exact fluxul real al regresiei de polling.
    await page.getByRole('button', { name: 'Run dry-run validation' }).click();

    // REGRESIA DE POLLING #1 — fără `page.reload()`, fără `waitForTimeout`: doar `usePoll`-ul
    // real al paginii poate produce această tranziție (`validating`/`validated` intermediar,
    // posibil netrecut prin DOM dacă workerul e rapid — nu se verifică starea tranzitorie,
    // la fel ca în `order-fulfilment.spec.ts`). Dacă `RunDryRunValidationAction` ar regresa la
    // forma fără tranziție sincronă de status (vezi docblock-ul fișierului), asertarea de mai
    // jos ar pica pe timeout, nu ar trece „din greșeală".
    const validatedHeading = page.getByRole('heading', { name: 'Step 3 of 4 — Dry run complete' });
    await expect(validatedHeading).toBeVisible({ timeout: 30_000 });
    await expect(validatedHeading).toBeFocused();

    await expect(page.getByText('2 rows ready to import, 2 invalid, out of 4 total.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download the 2 failed rows as CSV' })).toBeVisible();

    // Rândurile invalide — numărul de rând FIZIC din fișier și mesajul EXACT, în ordine.
    const invalidRowsTable = page.getByRole('table', { name: 'Rows that need attention' });
    const invalidRows = invalidRowsTable.locator('tbody tr');
    await expect(invalidRows).toHaveCount(2);
    await expect(invalidRows.nth(0).locator('td').first()).toHaveText('3');
    await expect(invalidRows.nth(0)).toContainText('name: The Company name field is required.');
    await expect(invalidRows.nth(1).locator('td').first()).toHaveText('4');
    await expect(invalidRows.nth(1)).toContainText('domain: Duplicate value — already used by an earlier row in this file.');

    // Axe-core pe conținutul BOGAT al pasului 3 (tabelul de mapare + rezultatul probei uscate
    // + rândurile invalide) — temă implicită (închisă) doar, bonus față de matricea completă
    // pe 2 teme din `a11y.spec.ts` (care scanează Imports/Reports doar în starea GOALĂ, pe DB
    // proaspătă — vezi comentariul de-acolo). 0 violări critice, la fel ca restul suitei.
    const axeResults = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'])
        .analyze();
    const criticalViolations = axeResults.violations.filter((violation) => violation.impact === 'critical');
    expect(criticalViolations, JSON.stringify(criticalViolations, null, 2)).toEqual([]);

    // Pasul 4 — Commit, DECLANȘAT de pe pagina DEJA deschisă (a treia tranziție de status
    // consecutivă fără reload).
    await page.getByRole('button', { name: 'Import 2 valid rows' }).click();

    // REGRESIA DE POLLING #2 — starea terminală, tot fără reload.
    const finalHeading = page.getByRole('heading', { name: 'Step 4 of 4 — Completed with errors' });
    await expect(finalHeading).toBeVisible({ timeout: 30_000 });
    await expect(finalHeading).toBeFocused();

    await expect(page.getByText('2 rows imported, 2 skipped, out of 4 total.')).toBeVisible();

    // Raportul de erori descărcabil — reimportabil (antetele originale + coloana `error`).
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('link', { name: 'Download the 2 skipped rows as CSV — correct them and re-import' }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/^import-.+-errors\.csv$/);
    const path = await download.path();
    expect(path, 'raportul de erori trebuie să fi ajuns pe disc').toBeTruthy();
    const content = await readFile(path!, 'utf-8');
    // `fputcsv()` pune ghilimele doar pe câmpurile cu spațiu ("Company Name", "Import test") —
    // conținut REAL, nu o presupunere de formatare „fără ghilimele".
    expect(content).toContain('"Company Name",Domain,Industry,Phone,Source,error');
    expect(content).toContain('Duplicate value — already used by an earlier row in this file.');

    // Cele 2 rânduri valide au fost chiar scrise ca `accounts` — dovadă independentă de
    // contoarele afișate mai sus.
    await page.goto(`${BASE}/accounts?sort=-created_at`);
    await page.getByRole('table').waitFor();
    await expect(page.getByRole('link', { name: `E2E Import Acme ${ts}` })).toBeVisible();
    await expect(page.getByRole('link', { name: `E2E Import Delta ${ts}` })).toBeVisible();
    await expect(page.getByRole('link', { name: `E2E Import Gamma ${ts}` })).toHaveCount(0);
});

/**
 * Regresie IZOLATĂ, minimă și rapidă pentru defectul descris în docblock-ul fișierului
 * (reparat — vezi `RunDryRunValidationAction::execute()`): un fixture de UN singur rând, o
 * singură coloană, fiindcă acest test verifică STRICT simptomul de polling (pagina se
 * actualizează singură, fără reload), nu conținutul probei uscate — deja acoperit exhaustiv
 * de testul principal de mai sus, cu fixture-ul complet de 4 rânduri. Dacă
 * `RunDryRunValidationAction` ar regresa la forma fără tranziție sincronă de status, testul
 * de mai jos ar pica pe timeout aici, separat de testul principal.
 */
test('regresie — după „Run dry-run validation", pagina se actualizează singură, fără reload (fixture minim)', async ({ page }) => {
    test.setTimeout(30_000);

    await page.goto(`${IMPORTS_URL}/create`);
    await page.getByLabel('File (CSV or XLSX)').setInputFiles({
        name: 'e2e-defect-repro.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`Company Name\nE2E Defect Repro ${ts}`, 'utf-8'),
    });
    await page.getByRole('button', { name: 'Upload' }).click();
    await expect(page).toHaveURL(/\/imports\/[^/]+$/);

    await page.getByRole('button', { name: 'Save mapping and continue' }).click();
    await expect(page.getByRole('heading', { name: 'Step 2 of 4 — Columns mapped' })).toBeVisible();

    await page.getByRole('button', { name: 'Run dry-run validation' }).click();

    // Fără reload, fără `waitForTimeout` — doar `usePoll`-ul real al paginii.
    await expect(page.getByRole('heading', { name: 'Step 3 of 4 — Dry run complete' })).toBeVisible({ timeout: 10_000 });
});

/**
 * §7.4, rândul „Import CSV" — Agent și Viewer „—" (fără CRUD, fără subset propriu). Cheap,
 * fără coadă — `@smoke`.
 */
test.describe('RBAC — Imports', () => {
    const rolesWithoutAccess: DemoRole[] = ['agent', 'viewer'];

    for (const role of rolesWithoutAccess) {
        test.describe(DEMO_ROLE_LABELS[role], () => {
            test.use({ storageState: authFile(role) });

            test('„Imports" absent din navigație, refuz (403) pe linkuri directe', { tag: ['@smoke'] }, async ({ page }) => {
                await page.goto(`${BASE}/dashboard`);
                await expect(page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'Imports' })).toHaveCount(0);

                const indexResponse = await page.goto(IMPORTS_URL);
                expect(indexResponse?.status()).toBe(403);

                const createResponse = await page.goto(`${IMPORTS_URL}/create`);
                expect(createResponse?.status()).toBe(403);
            });
        });
    }
});
