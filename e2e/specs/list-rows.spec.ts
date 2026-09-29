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
