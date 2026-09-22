import { expect, test, type Page } from '@playwright/test';
import { authFile } from '../support/auth';
import { setUserLocale, type AppLocale } from '../support/locale';

/**
 * Lot I18N, Val 5 — QA vizual bilingv MĂSURAT (specs.md §15.8, ADR-022). Tag `@fr` (rulează
 * doar pe push la `main`/tag-uri, la fel ca `locale-fr.spec.ts` — vezi docblock-ul de-acolo
 * pentru mecanismul `--grep`): testele de-aici n-au sens fără catalogul `fr`, deci nu
 * merită cost de CI pe fiecare PR.
 *
 * Planul presupunea franceza „cu 15-20% mai lungă". Re-măsurat pe arborele curent (script de
 * sondare, șters după măsurătoare — nu rulează în CI): **+25,6% global**, cu vârfuri pe
 * `dashboard` (+19,0%, `kpis.openPipelineValue` = „Valeur du pipeline ouvert", 25 caractere
 * față de „Open pipeline value", 19, +32%) și `bulk` (+22,0%).
 *
 * --- Ce s-a găsit efectiv (măsurat, nu presupus) -------------------------------------------
 *
 * 1. **Placa KPI `openPipelineValue` — defect REAL, reparat** (`Components/KpiTile.tsx`).
 *    Eticheta e text normal, pe rânduri (nu se trunchiază — decizie de produs). La
 *    lățimi de viewport ~410-425px (grid pe 2 coloane — include lățimea STANDARD a
 *    telefoanelor Pixel, 412px), eticheta franceză de 25 caractere trece pe DOUĂ rânduri
 *    (`height: 40px`) în timp ce vecina ei de pe același rând (`ordersThisMonth`, „Commandes
 *    ce mois-ci", 21 caractere) rămâne pe UNUL (`height: 20px`) — cele două VALORI numerice
 *    de pe același rând vizual ajungeau la `top` diferit cu exact o înălțime de rând (20px).
 *    Reparat cu `min-h-10` pe eticheta din `KpiTile.tsx` (rezervă 2×20px indiferent dacă
 *    textul chiar se rupe) — verificat prin sondare pe TOATĂ plaja 375-1280px, în ambele
 *    limbi: 0 dezalinieri după reparație (`misaligned=false` pe 56/56 combinații lățime×limbă
 *    sondate, pas de 5-60px).
 *
 * 2. **`BulkSelectionBar` — franceza crește pe verticală, NIMIC nu iese din container.**
 *    Măsurat pe Orders/Marlin (Owner, „select all on page" + reasignare + anulare draft-uri —
 *    cea mai „încărcată" stare a bării, cu toate blocurile opționale prezente): pe O rulare cu
 *    seed fix, la 1280px, engleza încăpea pe UN rând (`barBox.height = 62px`) și franceza pe
 *    DOUĂ (`108px`, +74%). Numărul EXACT de rânduri NU e stabil între rulări — seed-ul demo nu
 *    fixează sămânța aleatoare (`--scale=0.001`, fără `mt_srand`, ca în `orders-bulk.spec.ts`),
 *    deci volumul de comenzi/draft-uri/proprietari variază — testul de mai jos verifică
 *    invariantul care RĂMÂNE adevărat indiferent de volum: `flex-wrap`
 *    (`BulkSelectionBar.tsx:219`) funcționează exact cum trebuie — niciun copil nu depășește
 *    vreodată lățimea containerului (`scrollWidth === clientWidth` în AMBELE limbi), doar
 *    înălțimea/numărul de rânduri cresc, iar franceza nu ocupă NICIODATĂ mai puțin spațiu
 *    decât engleza pe același conținut. **Nicio reparație necesară aici** — comportamentul e
 *    cel proiectat (bara „se rupe pe rânduri"), nu un defect.
 *
 * --- Ce NU s-a măsurat direct, și de ce ----------------------------------------------------
 *
 * `bulk:selectionBar.overRowCap` (100c FR / 88c EN, cel mai lung șir din namespace-ul `bulk`)
 * apare DOAR când `effectiveCount > rowCap`, iar `rowCap` e `null` (fără plafon) pentru
 * Owner/Manager — SINGURELE roluri cu vizibilitate largă pe listă. Doar Agentul are un
 * plafon (`BULK_AGENT_ROW_CAP`, implicit 500), iar seed-ul de test (`--scale=0.001`) are
 * ~40-60 rânduri per tenant — mult sub prag. A-l provoca real ar cere fie un seed dedicat de
 * 500+ rânduri (cost de rulare disproporționat pentru o verificare vizuală), fie schimbarea
 * `BULK_AGENT_ROW_CAP` în `e2eEnv()` — RESPINSĂ deliberat, fiindcă e o variabilă PARTAJATĂ pe
 * care `orders-bulk.spec.ts` își calculează pragul de confirmare hardcodat (125 =
 * round(500×0,25)); a o schimba ar rupe acele teste. `selectAllMatching` (47c FR — al doilea
 * cel mai lung, direct accesibil prin „select all on page" pe o listă cu peste 50 de rânduri)
 * e folosit mai jos ca variantă reprezentativă, reproductibilă fără fixture suplimentar.
 */

const BASE = '/marlin';
const DASHBOARD_URL = `${BASE}/dashboard`;
const ORDERS_URL = `${BASE}/orders`;

test.use({ storageState: authFile('owner') });

test.afterEach(async ({ page }) => {
    await setUserLocale(page, 'en');
});

interface KpiViewport {
    label: string;
    width: number;
    height: number;
    /** La >=1024px `Dashboard.tsx` trece pe `lg:grid-cols-4` — un singur rând de 4 plăci. */
    columns: 2 | 4;
}

const KPI_VIEWPORTS: KpiViewport[] = [
    { label: 'desktop (lg:grid-cols-4)', width: 1280, height: 800, columns: 4 },
    // ~400px cerut de task — 416px e ÎN CHIAR banda (410-425px) unde a reprodus defectul
    // istoric pe `openPipelineValue` (măsurat, nu rotunjit la o cifră convenabilă). E și
    // lățimea CSS standard a telefoanelor Pixel (412px) — un dispozitiv real, nu un artefact
    // de test.
    { label: '~400px (grid-cols-2, banda exactă a defectului istoric)', width: 416, height: 900, columns: 2 },
];

async function kpiValueTops(page: Page): Promise<number[]> {
    const tiles = page.locator('main .grid.grid-cols-2 > div');
    await expect(tiles).toHaveCount(4);

    const tops: number[] = [];

    for (let i = 0; i < 4; i++) {
        const value = tiles.nth(i).locator('p').nth(1);
        const box = await value.boundingBox();

        if (!box) {
            throw new Error(`Placa KPI #${i} nu are cutie de delimitare — nerandată?`);
        }

        tops.push(Math.round(box.y));
    }

    return tops;
}

for (const viewport of KPI_VIEWPORTS) {
    for (const locale of ['en', 'fr'] as const) {
        test(
            `plăcile KPI rămân aliniate pe rând — ${viewport.label}, ${locale}`,
            { tag: ['@fr'] },
            async ({ page }) => {
                await page.setViewportSize({ width: viewport.width, height: viewport.height });
                await setUserLocale(page, locale);
                await page.goto(DASHBOARD_URL);
                await page.waitForSelector('h1');
                // Elimină o sursă de flaky nelegată de layout-ul propriu-zis: metricile de
                // linie ale IBM Plex Sans pot varia cât fontul se încarcă (măsurat direct în
                // sondare — fără această așteptare, PRIMA citire după `goto` putea vedea
                // metrici tranzitorii ale fontului de rezervă).
                await page.evaluate(() => document.fonts.ready);

                const tops = await kpiValueTops(page);

                if (viewport.columns === 4) {
                    // Măsurat post-reparație: toate cele 4 valori la `top: 282` (1280px),
                    // identic EN/FR — un singur rând, nicio placă „coborâtă".
                    expect(new Set(tops).size, `cele 4 valori KPI trebuie să fie pe ACELAȘI rând (${locale}, ${viewport.width}px) — ${JSON.stringify(tops)}`).toBe(1);
                } else {
                    // 2 coloane — perechile (0,1) și (2,3) trebuie să rămână aliniate ÎN
                    // INTERIORUL propriului rând, chiar dacă doar UNA din cele două etichete
                    // ale perechii s-ar rupe pe două rânduri (asta era exact defectul găsit:
                    // `openPipelineValue` rupt, `ordersThisMonth` nu, pe același rând).
                    expect(tops[0], `rândul 1 (openPipelineValue/ordersThisMonth) trebuie aliniat — ${locale}, ${viewport.width}px`).toBe(tops[1]);
                    expect(tops[2], `rândul 2 (overdueInvoices/lowStockAlerts) trebuie aliniat — ${locale}, ${viewport.width}px`).toBe(tops[3]);
                }
            },
        );
    }
}

/** Accesibil pe ambele limbi — evită să repete regex-urile de trei ori mai jos. */
const SELECT_ALL_ON_PAGE = /^Select all orders on this page$|^Sélectionner toutes les commandes de cette page$/;
const CLEAR_SELECTION = /^Clear selection$|^Effacer la sélection$/;

async function measureBulkBar(page: Page, locale: AppLocale): Promise<{ rows: number; height: number; overflowed: boolean }> {
    await setUserLocale(page, locale);
    await page.goto(ORDERS_URL);
    await page.getByRole('table').waitFor();

    // `checkByLabel` (`e2e/support/bulk.ts`) ia un nume FIX — aici numele variază cu limba,
    // deci reluăm tiparul lui direct (eticheta, nu `input`-ul — pseudo-elementul de atingere
    // extinsă interceptează click-ul pe `input`, vezi docblock-ul helper-ului original).
    const selectAllCheckbox = page.getByRole('checkbox', { name: SELECT_ALL_ON_PAGE });
    await selectAllCheckbox.locator('xpath=ancestor::label[1]').click();
    await expect(selectAllCheckbox).toBeChecked();

    const clearButton = page.getByRole('button', { name: CLEAR_SELECTION });
    await clearButton.waitFor();

    // Bara e părintele direct al butonului „Clear selection"/„Effacer la sélection"
    // (`BulkSelectionBar.tsx:219`) — ancoră stabilă, nu o combinație de clase Tailwind care
    // s-ar putea regăsi și în altă parte a ecranului.
    const bar = clearButton.locator('xpath=..');
    const barBox = await bar.boundingBox();

    if (!barBox) {
        throw new Error(`Bara de selecție nu are cutie de delimitare (${locale}).`);
    }

    const overflow = await bar.evaluate((el) => ({ scrollWidth: el.scrollWidth, clientWidth: el.clientWidth }));
    const overflowed = overflow.scrollWidth > overflow.clientWidth + 1;

    const children = bar.locator(':scope > *');
    const childCount = await children.count();
    const rowTops = new Set<number>();

    for (let i = 0; i < childCount; i++) {
        const box = await children.nth(i).boundingBox();
        // Elementele ASCUNSE (dialogurile de confirmare, închise) nu au `boundingBox` —
        // exclus corect din numărătoarea de „rânduri vizuale".
        if (box) {
            rowTops.add(Math.round(box.y));
        }
    }

    return { rows: rowTops.size, height: barBox.height, overflowed };
}

test('bara de operații în masă (Orders) — franceza crește pe verticală, nimic nu iese din container', { tag: ['@fr'] }, async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });

    const en = await measureBulkBar(page, 'en');
    const fr = await measureBulkBar(page, 'fr');

    // NU comparăm cu un număr ABSOLUT de rânduri hardcodat: `DemoDatasetSeeder` nu fixează
    // sămânța aleatoare (`--scale=0.001`, fără `mt_srand` — confirmat și în docblock-ul
    // `orders-bulk.spec.ts`), deci numărul de comenzi/draft-uri/proprietari — și deci
    // numărul exact de rânduri pe care le ocupă bara — DIFERĂ între rulări. Măsurătoarea
    // originală (o singură rulare, seed fix) a dat EN 62px/1 rând, FR 108px/2 rânduri
    // (+74% înălțime); o altă rulare a dat EN pe 4 rânduri direct — motivul e volumul de
    // date, nu un defect de layout. Ce RĂMÂNE adevărat, indiferent de volum, e comparația
    // RELATIVĂ: franceza (text mai lung) nu poate ocupa MAI PUȚINE rânduri sau MAI PUȚINĂ
    // înălțime decât engleza pe ACELAȘI conținut, și NIMIC nu iese vreodată din container —
    // astea sunt invariantele verificate mai jos.
    expect(en.overflowed, 'engleza nu trebuie să aibă overflow orizontal').toBe(false);
    expect(fr.overflowed, 'franceza nu trebuie să aibă overflow orizontal').toBe(false);

    expect(fr.rows, `franceza (${fr.rows} rânduri) nu poate ocupa MAI PUȚINE rânduri decât engleza (${en.rows} rânduri) pe același conținut`).toBeGreaterThanOrEqual(en.rows);
    expect(fr.height, `franceza (${fr.height}px) trebuie să fie cel puțin la fel de înaltă ca engleza (${en.height}px) — creșterea e AȘTEPTATĂ (flex-wrap), nu un defect`).toBeGreaterThanOrEqual(en.height);

    // Gardă de sănătate independentă de volumul de date: bara nu „explodează" pe zeci de
    // rânduri — dacă vreodată un control ar înceta să se potrivească deloc în lățimea
    // containerului (regresie CSS, nu volum de date), numărul de rânduri ar sări brusc mult
    // peste orice măsurătoare rezonabilă pentru un singur grup de controale.
    expect(en.rows, `engleza nu ar trebui să aibă nevoie de peste 8 rânduri pentru un singur grup de controale (măsurat: ${en.rows})`).toBeLessThanOrEqual(8);
    expect(fr.rows, `franceza nu ar trebui să aibă nevoie de peste 8 rânduri pentru un singur grup de controale (măsurat: ${fr.rows})`).toBeLessThanOrEqual(8);
});
