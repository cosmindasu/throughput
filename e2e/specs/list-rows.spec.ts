import { expect, test } from '@playwright/test';
import { authFile } from '../support/auth';
import { setUserTheme } from '../support/theme';

/**
 * Starea vizuală a rândurilor de listă: dungi alternante, hover, selecție.
 *
 * Totul trăiește în `.data-table` / `.data-rows` (`resources/css/app.css`) — o regulă CSS
 * fără niciun `className` pe rând, deci nimic din ce verifică `tsc` sau eslint nu o atinge.
 * Fără testul ăsta, o clasă `data-table` scăpată la un tabel nou, o reordonare a celor trei
 * reguli, sau un utilitar `bg-*` pus pe un rând ar strica-o complet tăcut.
 *
 * Aserțiunile sunt pe culoarea CALCULATĂ, nu pe prezența claselor: o clasă prezentă care nu
 * pictează nimic e exact felul în care defectul ăsta ar trece neobservat.
 */
test.use({ storageState: authFile('owner') });

/**
 * Culoarea AȘEZATĂ a rândului.
 *
 * Așteptarea nu e „flakiness insurance", e parte din măsurătoare: rândurile au
 * `transition: background-color 120ms`, iar în timpul tranziției `getComputedStyle` întoarce
 * valoarea INTERPOLATĂ — de la transparent spre culoarea finală, deci un `rgba(...)` cu alfa,
 * diferit la fiecare citire. Prima versiune a testului compara exact astfel de valori
 * intermediare și pica pe o diferență care dispărea în 120 ms.
 */
async function rowBackground(page: import('@playwright/test').Page, index: number): Promise<string> {
    await page.waitForTimeout(200);

    return page.locator('table.data-table tbody tr').nth(index).evaluate(
        (el) => getComputedStyle(el).backgroundColor,
    );
}

test('rândurile pare și impare au fundaluri diferite', async ({ page }) => {
    await page.goto('/cascade/accounts');
    await expect(page.locator('table.data-table tbody tr').first()).toBeVisible();

    const odd = await rowBackground(page, 0);
    const even = await rowBackground(page, 1);
    const nextOdd = await rowBackground(page, 2);

    expect(even, `rândul par și cel impar au aceeași culoare (${odd})`).not.toBe(odd);
    // Alternanța continuă — nu doar „al doilea rând e altfel".
    expect(nextOdd).toBe(odd);
});

test('un rând bifat se colorează, și rămâne colorat sub cursor', async ({ page }) => {
    await page.goto('/cascade/accounts');
    await expect(page.locator('table.data-table tbody tr').first()).toBeVisible();

    const before = await rowBackground(page, 0);

    // `force`: caseta are un `<label>` cu zonă de atingere mărită (SC 2.5.5) care
    // interceptează clicul — ținta reală a utilizatorului, nu inputul.
    await page.locator('table.data-table tbody input[type="checkbox"]').first().check({ force: true });

    const row = page.locator('table.data-table tbody tr').first();
    await expect(row).toHaveAttribute('data-selected', 'true');

    const selected = await rowBackground(page, 0);
    expect(selected, 'rândul bifat arată exact ca înainte de bifare').not.toBe(before);

    // Poanta ordinii regulilor din CSS: selecția bate hover-ul. Înainte de lotul ăsta,
    // `hover:bg-row-hover` era un utilitar pe rând și ar fi câștigat, deci rândul bifat
    // își pierdea culoarea taman când treceai cu mouse-ul peste el.
    await row.hover();
    expect(await rowBackground(page, 0), 'hover-ul a acoperit starea de selectat').toBe(selected);
});

test('dungile există și pe tema deschisă, nu doar pe cea închisă', async ({ page }) => {
    await page.goto('/cascade/accounts');
    await setUserTheme(page, 'light');
    await page.goto('/cascade/accounts');
    await expect(page.locator('table.data-table tbody tr').first()).toBeVisible();

    expect(await rowBackground(page, 1)).not.toBe(await rowBackground(page, 0));

    await setUserTheme(page, 'dark');
});

/**
 * Feed-ul de activitate e un `<ul>`, nu un tabel — vizual e tot o listă de rânduri, deci
 * primește aceeași tratare prin `.data-rows`. Testul separat fiindcă e un selector diferit,
 * și tocmai de-aia e ușor de uitat.
 */
test('feed-ul de activitate alternează la fel', async ({ page }) => {
    await page.goto('/cascade/dashboard');

    const rows = page.locator('ul.data-rows > li');
    await expect(rows.first()).toBeVisible();

    const odd = await rows.nth(0).evaluate((el) => getComputedStyle(el).backgroundColor);
    const even = await rows.nth(1).evaluate((el) => getComputedStyle(el).backgroundColor);

    expect(even).not.toBe(odd);
});

/**
 * Capul de tabel rămâne pe ecran cât derulezi rândurile.
 *
 * Testul e pe POZIȚIE, nu pe `position: sticky` în stiluri — iar diferența nu e academică:
 * la prima implementare, `Contacts/Index` avea `sticky` calculat corect pe `th` și tot nu
 * funcționa, fiindcă tabelul purta el însuși `overflow-hidden` și devenea astfel containerul
 * de referință. Măsurat, `th` cobora de la 328 la 28 după o derulare de 300px, în timp ce pe
 * celelalte opt liste rămânea neclintit. O aserțiune pe `getComputedStyle(...).position` ar
 * fi trecut voioasă.
 */
const STICKY_LISTS = [
    '/cascade/accounts',
    '/cascade/contacts',
    '/cascade/deals',
    '/cascade/orders',
    '/cascade/invoices',
    '/cascade/activity',
];

for (const route of STICKY_LISTS) {
    test(`${route}: capul de tabel nu pleacă la derulare`, async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto(route);

        const box = page.locator('.data-table-scroll').first();
        await expect(box).toBeVisible();

        const measured = await box.evaluate((el) => {
            const th = el.querySelector('thead th') as HTMLElement | null;
            if (!th || el.scrollHeight <= el.clientHeight) {
                return null; // lista e mai scurtă decât containerul: nimic de derulat
            }

            const before = th.getBoundingClientRect().top;
            el.scrollTop = 300;

            return new Promise<{ before: number; after: number; scrolled: number }>((resolve) => {
                requestAnimationFrame(() => resolve({
                    before,
                    after: th.getBoundingClientRect().top,
                    scrolled: el.scrollTop,
                }));
            });
        });

        if (measured === null) {
            return;
        }

        expect(measured.scrolled, 'containerul nu a derulat deloc').toBeGreaterThan(0);
        expect(Math.round(measured.after), 'capul a plecat cu rândurile').toBe(Math.round(measured.before));
    });
}

/**
 * Fiecare rând de jurnal poartă un punct colorat după tipul acțiunii. `action` vine brut din
 * `ActivityEntryResource` / `HistoryEntryResource`; `DashboardTest` verifică contractul
 * server-side, testul ăsta verifică faptul că ajunge pe ecran.
 */
test('feed-ul de activitate are un semnal vizual pe fiecare rând', async ({ page }) => {
    await page.goto('/cascade/dashboard');

    const rows = page.locator('ul.data-rows > li');
    await expect(rows.first()).toBeVisible();

    const rowCount = await rows.count();
    const dots = await page.locator('ul.data-rows > li span[aria-hidden="true"].rounded-full').count();

    expect(dots, 'lipsesc puncte de pe unele rânduri').toBe(rowCount);

    // Punctul e DECORATIV: eticheta acțiunii e text, lângă el. Dacă ar ajunge în arborele de
    // accesibilitate, fiecare rând s-ar anunța de două ori.
    await expect(page.locator('ul.data-rows > li span.rounded-full[aria-hidden="true"]').first()).toBeAttached();
});
