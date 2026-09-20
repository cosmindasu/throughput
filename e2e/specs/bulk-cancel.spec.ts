import { expect, test, type Page } from '@playwright/test';
import { tinker, TINKER_OK } from '../support/artisan';
import { authFile } from '../support/auth';
import { bulkStatusRegion, checkByLabel } from '../support/bulk';
import { BULK_CHUNK_SIZE } from '../support/env';
import { inertiaPageProps } from '../support/inertia';

/**
 * §24.3 pct. 6 — „Operație în masă pe 1.000+ rânduri (test) → progres → anulare la
 * jumătate → verificarea celor patru invarianți".
 *
 * Cei patru invarianți sunt citați cuvânt cu cuvânt la fiecare aserțiune de mai jos.
 * Formularea lor a fost adăugată în specs.md la remedierea P3-002, tocmai fiindcă
 * „stare finală coerentă" nu era falsificabil — deci testul ăsta n-are voie să se oprească
 * la „operația zice Cancelled".
 *
 * **Workspace: `northgate`.** 2.000 de conturi noi ar schimba numărul de rânduri, prima
 * pagină și vederile salvate ale fiecărui test care lucrează pe Marlin. Northgate e
 * singurul tenant pe care niciun alt spec nu-l atinge (doar Owner e membru acolo —
 * `UsersAndMembershipsSeeder`), iar `afterAll` șterge oricum fixture-ul.
 *
 * **Rol: Owner.** Pragul de confirmare e `min(round(rowCap × 0,25), 1000)`
 * (`BulkConfirmationThreshold::for()`); Owner n-are plafon de rol, deci pragul e 1.000 —
 * exact sub volumul cerut de §24.3, deci dialogul de confirmare FACE parte din flux.
 *
 * **De ce anularea e testabilă deloc**: e COOPERATIVĂ (§13.2 pct. 7) —
 * `ProcessBulkChunkJob` verifică `batch()->cancelled()` la începutul lui `handle()`, deci
 * joburile deja pornite se termină, cele neîncepute se opresc. Ca să EXISTE joburi
 * neîncepute când sosește anularea, suita rulează cu `BULK_CHUNK_SIZE=25`
 * (`e2e/support/env.ts`) în loc de 500: 80 de chunk-uri în loc de 4.
 */
test.use({ storageState: authFile('owner') });

const BASE = '/northgate';
const ACCOUNTS_URL = `${BASE}/accounts`;

const ts = Date.now();
const NAME_PREFIX = `E2E Bulk Cancel ${ts}`;
const TOTAL_ROWS = 2000;
const CHUNK_SIZE = Number(BULK_CHUNK_SIZE);

/** `UsersAndMembershipsSeeder` — Owner-ul demo, membru în toate cele 3 organizații. */
const OWNER_NAME = 'Olivia Sterling';

interface BulkShowProps {
    operation: {
        id: string;
        status: string;
        totalRows: number;
        totalJobs: number;
        processedJobs: number;
        processedRowsEstimate: number;
        activityLogUrl: string | null;
    };
}

interface ActivityProps {
    entries: { data: Array<{ actor: { name: string } | null; createdAt: string | null; actionLabel: string; newValues: Record<string, unknown> | null }> };
    filters: { bulkOperationId: string | null };
}

/**
 * 2.000 de conturi FĂRĂ responsabil, inserate direct (vezi `e2e/support/artisan.ts` pentru
 * de ce nu prin `POST /accounts`: ar fi 2.000 de cereri pe un `php artisan serve`
 * monoproces). „Fără responsabil" e valoarea VECHE a invariantului 2 — reasignarea de mai
 * jos o schimbă în Owner, iar cele două valori sunt exact cele două stări posibile ale unui
 * rând după anulare, deci se pot număra separat prin filtrul `owner` al listei.
 */
function seedUnassignedAccounts(): void {
    const code = [
        `$t = \\App\\Models\\Tenant::query()->where('slug', 'northgate')->firstOrFail();`,
        `\\App\\Services\\Tenancy\\TenantContext::run($t, function () use ($t) {`,
        `  $u = \\App\\Models\\User::query()->where('email', 'demo.owner@throughput.dev')->firstOrFail();`,
        `  $now = now();`,
        `  $rows = [];`,
        `  for ($i = 1; $i <= ${TOTAL_ROWS}; $i++) {`,
        `    $rows[] = ['id' => strtolower((string) \\Illuminate\\Support\\Str::ulid()), 'tenant_id' => $t->getKey(),`,
        `      'name' => '${NAME_PREFIX} ' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),`,
        `      'owner_user_id' => null, 'status' => 'prospect', 'credit_terms' => 'net_30',`,
        `      'created_by' => $u->getKey(), 'created_at' => $now, 'updated_at' => $now];`,
        `  }`,
        `  foreach (array_chunk($rows, 500) as $chunk) { \\Illuminate\\Support\\Facades\\DB::table('accounts')->insert($chunk); }`,
        `});`,
        `echo '${TINKER_OK}', PHP_EOL;`,
    ].join('');

    tinker(code);
}

function removeSeededAccounts(): void {
    const code = [
        `$t = \\App\\Models\\Tenant::query()->where('slug', 'northgate')->firstOrFail();`,
        `\\App\\Services\\Tenancy\\TenantContext::run($t, function () use ($t) {`,
        `  \\Illuminate\\Support\\Facades\\DB::table('accounts')->where('tenant_id', $t->getKey())->where('name', 'like', '${NAME_PREFIX}%')->delete();`,
        `});`,
        `echo '${TINKER_OK}', PHP_EOL;`,
    ].join('');

    tinker(code);
}

/**
 * Numără conturile fixture-ului pe responsabil, prin EXPORTUL CSV al listei filtrate —
 * exact fișierul pe care îl primește un utilizator care apasă „Export CSV" cu același
 * filtru (`AccountList::exportHeaders()`: … `Owner` … ; sincron sub 5.000 de rânduri,
 * `ListExport::respond()`).
 *
 * De ce nu numărăm din tabel: lista e paginată pe cursor, deci 2.000 de rânduri ar
 * însemna zeci de pagini. Exportul e răspunsul pe care produsul însuși îl dă la „dă-mi
 * lista asta întreagă", nu un drum ocolit inventat de test.
 */
async function countAccountsByOwner(page: Page): Promise<{ total: number; withOwner: number; unassigned: number }> {
    const response = await page.request.get(`${ACCOUNTS_URL}/export?filter[q]=${encodeURIComponent(NAME_PREFIX)}`);
    expect(response.ok(), 'exportul CSV al listei filtrate').toBeTruthy();

    const lines = (await response.text()).split(/\r?\n/).filter((line) => line !== '');
    const header = lines[0].split(',').map((cell) => cell.replace(/^"|"$/g, ''));
    const ownerColumn = header.indexOf('Owner');
    expect(ownerColumn, 'exportul de conturi trebuie să conțină coloana Owner').toBeGreaterThan(-1);

    let withOwner = 0;
    let unassigned = 0;

    for (const line of lines.slice(1)) {
        const owner = line.split(',')[ownerColumn]?.replace(/^"|"$/g, '') ?? '';

        if (owner === OWNER_NAME) {
            withOwner++;
        } else if (owner === '') {
            unassigned++;
        } else {
            throw new Error(`Rând cu un responsabil neașteptat („${owner}") — fixture-ul are doar două stări posibile.`);
        }
    }

    return { total: lines.length - 1, withOwner, unassigned };
}

test.beforeAll(() => {
    seedUnassignedAccounts();
});

test.afterAll(() => {
    removeSeededAccounts();
});

test('reasignare în masă pe 2.000 de rânduri, anulată la jumătate — cei patru invarianți din §24.3 pct. 6', async ({ page }) => {
    test.setTimeout(240_000);

    // Starea de PLECARE, măsurată, nu presupusă: toate rândurile au valoarea VECHE.
    const initial = await countAccountsByOwner(page);
    expect(initial.total).toBe(TOTAL_ROWS);
    expect(initial.unassigned).toBe(TOTAL_ROWS);
    expect(initial.withOwner).toBe(0);

    // ---------------------------------------------------------------- Pornirea operației
    await page.goto(`${ACCOUNTS_URL}?filter[q]=${encodeURIComponent(NAME_PREFIX)}&filter[owner]=unassigned`);
    await page.getByRole('table').waitFor();

    await checkByLabel(page, 'Select all accounts on this page');

    const selectAllMatching = page.getByRole('button', { name: /Select all [\d,]+ accounts matching this filter/ });
    await expect(selectAllMatching).toBeVisible();
    await expect(selectAllMatching).toHaveText(`Select all ${TOTAL_ROWS.toLocaleString('en-US')} accounts matching this filter`);
    await selectAllMatching.click();

    await page.getByLabel('Reassign to').selectOption({ label: OWNER_NAME });
    await page.getByRole('button', { name: 'Reassign owner' }).click();

    // Peste pragul de 1.000 al Owner-ului (FR-BULK-01) — confirmarea e obligatorie.
    const confirmDialog = page.getByRole('dialog', { name: `Reassign ${TOTAL_ROWS.toLocaleString('en-US')} accounts?` });
    await expect(confirmDialog).toBeVisible();
    await confirmDialog.getByRole('button', { name: 'Reassign' }).click();

    await page.waitForURL(/\/bulk\/[^/]+$/);
    const bulkUrl = page.url();

    // ------------------------------------------------------------------ Progres + anulare
    // „Anulare la jumătate" e verificabilă doar dacă testul ȘTIE că operația a început și
    // NU s-a terminat. Polling pe props-urile reale ale paginii (`page.goto` repetat, ~200
    // ms), nu `waitForTimeout`: condiția e „măcar un chunk gata, dar nu toate".
    //
    // `totalJobs` e 0 cât timp `PlanBulkOperationJob` n-a scris încă `batch_id`, deci
    // condiția de mai jos e falsă și în faza `pending`: o singură verificare acoperă și
    // „a început", și „n-a terminat".
    await expect
        .poll(
            async () => {
                const { operation } = await inertiaPageProps<BulkShowProps>(page, bulkUrl);

                return operation.processedJobs > 0 && operation.processedJobs < operation.totalJobs;
            },
            { timeout: 120_000, intervals: [200] },
        )
        .toBe(true);

    // Butonul e randat cât timp starea nu e terminală ȘI utilizatorul poate anula
    // (`BulkOperationPolicy::cancel()` — doar autorul).
    await page.getByRole('button', { name: 'Cancel' }).click({ timeout: 10_000 });

    // Starea terminală: joburile deja în lucru se termină, restul se opresc; `finally()`
    // scrie `cancelled` prin `FinalizeBulkOperationJob`.
    await expect(bulkStatusRegion(page)).toContainText('Cancelled', { timeout: 60_000 });

    const finalProps = await inertiaPageProps<BulkShowProps>(page, bulkUrl);

    // ============================ INVARIANTUL 4 (prima jumătate) =======================
    // „`bulk_operations.status = cancelled`" — citit din starea pe care o vede utilizatorul.
    expect(finalProps.operation.status).toBe('cancelled');
    expect(finalProps.operation.totalRows).toBe(TOTAL_ROWS);
    await expect(page.getByText('Cancelled — rows already processed keep their change, the rest were left unchanged.')).toBeVisible();

    // ======================== INVARIANȚII 1, 2 și 3 ====================================
    const after = await countAccountsByOwner(page);

    // 1. „Fiecare rând marcat `processed` are valoarea nouă; niciunul nu are valoarea
    //    veche." + 2. „Fiecare rând neprocesat are EXACT valoarea veche."
    //    `countAccountsByOwner()` aruncă la orice a treia valoare, deci ajunge să verificăm
    //    că cele două grupuri acoperă exact fixture-ul: nu există rând nici-nici.
    expect(after.total).toBe(TOTAL_ROWS);
    expect(after.withOwner + after.unassigned).toBe(TOTAL_ROWS);

    // 3. „`processed + skipped + failed` = numărul de rânduri atinse până la anulare, iar
    //    suma e STRICT MAI MICĂ decât `total_rows`."
    expect(after.withOwner, 'anularea a prins operația în lucru, nu înainte de primul chunk').toBeGreaterThan(0);
    expect(after.withOwner, 'anularea a oprit ceva — altfel n-a fost o anulare').toBeLessThan(TOTAL_ROWS);
    expect(after.unassigned).toBe(TOTAL_ROWS - after.withOwner);

    // „Anularea nu lasă rânduri pe jumătate scrise" (motivarea invariantului 2), verificată
    // în forma cea mai tare disponibilă: un chunk se aplică în ÎNTREGIME sau deloc
    // (`ProcessBulkChunkJob` — `insertOrIgnore` + `apply()` în ACEEAȘI tranzacție), deci
    // numărul de rânduri schimbate e obligatoriu un multiplu al mărimii de chunk. Orice
    // rest ar însemna o tranzacție ruptă la mijloc.
    expect(after.withOwner % CHUNK_SIZE, `${after.withOwner} rânduri schimbate nu e multiplu de ${CHUNK_SIZE} — un chunk s-a aplicat parțial`).toBe(0);

    // ============================ INVARIANTUL 4 (a doua jumătate) ======================
    // „…iar `activity_log` conține o intrare […] cu autorul și momentul."
    //
    // ATENȚIE, nepotrivire raportată separat: §24.3 cere literal „o intrare de ANULARE".
    // Nu există — `BulkOperationController::cancel()` nu scrie nimic în `activity_log`, iar
    // `FinalizeBulkOperationJob` doar actualizează `bulk_operations.status`. Ce EXISTĂ (și
    // ce se verifică mai jos) e instrumentarea din `BulkChunkActivityRecorder`: un rând per
    // înregistrare efectiv modificată, cu autor și moment, filtrabil pe operație — adică
    // exact urma după care se poate spune CÂT a apucat să facă operația înainte de anulare.
    expect(finalProps.operation.activityLogUrl, 'US-BULK-01 — link către jurnalul filtrat pe această operație').not.toBeNull();

    const activityUrl = `${BASE}/activity?bulkOperationId=${finalProps.operation.id}`;
    await page.goto(bulkUrl);
    await page.getByRole('link', { name: 'View in Activity Log' }).click();
    await expect(page).toHaveURL(new RegExp(`bulkOperationId=${finalProps.operation.id}`));
    await expect(page.getByText('Showing only rows written by a single bulk operation.')).toBeVisible();

    const activity = await inertiaPageProps<ActivityProps>(page, activityUrl);
    expect(activity.filters.bulkOperationId).toBe(finalProps.operation.id);
    expect(activity.entries.data.length, 'operația a scris rânduri de jurnal').toBeGreaterThan(0);

    for (const entry of activity.entries.data) {
        expect(entry.actor?.name, 'AUTORUL fiecărei intrări — Owner-ul care a pornit operația').toBe(OWNER_NAME);
        expect(entry.createdAt, 'MOMENTUL fiecărei intrări').not.toBeNull();
        expect(entry.newValues, 'intrarea poartă valoarea NOUĂ a rândului modificat').toHaveProperty('owner_user_id');
    }

    // Rândurile NEATINSE chiar sunt neatinse și în listă, nu doar în export: filtrul
    // „Unassigned" le mai arată.
    await page.goto(`${ACCOUNTS_URL}?filter[q]=${encodeURIComponent(NAME_PREFIX)}&filter[owner]=unassigned`);
    await page.getByRole('table').waitFor();
    await checkByLabel(page, 'Select all accounts on this page');
    await expect(page.getByRole('button', { name: /Select all [\d,]+ accounts matching this filter/ })).toHaveText(
        `Select all ${after.unassigned.toLocaleString('en-US')} accounts matching this filter`,
    );
});
