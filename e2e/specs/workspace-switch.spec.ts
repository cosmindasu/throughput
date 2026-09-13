import { expect, test } from '@playwright/test';
import { authFile } from '../support/auth';

/**
 * §24.3 pct. 2 — Owner comută workspace prin comutatorul din antet
 * (`WorkspaceSwitcher.tsx`), verificare că titlul workspace-ului ȘI datele
 * dashboard-ului se schimbă complet, nu doar segmentul din URL.
 *
 * Numele/industria sunt literale din seed (`DemoDatasetSeeder::TENANTS`),
 * randate direct în `<h1>` (`Dashboard.tsx`) — verificare deterministă, fără să
 * depindă de valorile numerice ale KPI-urilor (aleatorii per seed).
 */
test.use({ storageState: authFile('owner') });

const MARLIN_HEADING = 'Marlin Fasteners & Supply Co. — Industrial Fasteners Distributor';
const CASCADE_HEADING = 'Cascade Hydraulic Components — Hydraulic Components Distributor';

test('Owner comută din Marlin în Cascade prin comutatorul de workspace', async ({ page }) => {
    await page.goto('/marlin/dashboard');

    const heading = page.getByRole('heading', { level: 1 });
    await expect(heading).toHaveText(MARLIN_HEADING);

    // Conținutul complet al paginii ÎNAINTE de comutare — bază de comparație
    // pentru „datele dashboard-ului se schimbă complet", nu doar titlul.
    const marlinContent = await page.locator('main').innerText();

    await page.getByRole('button', { name: 'Marlin Fasteners & Supply Co.' }).click();
    await page.getByRole('option', { name: 'Cascade Hydraulic Components' }).click();

    await expect(page).toHaveURL(/\/cascade\/dashboard$/);
    await expect(heading).toHaveText(CASCADE_HEADING);

    const cascadeContent = await page.locator('main').innerText();
    expect(cascadeContent).not.toBe(marlinContent);

    // Fostul nume/industrie nu mai apar deloc pe ecran — nu doar titlul „cel
    // nou" e corect, cel vechi chiar a dispărut (randare completă, nu un merge
    // parțial de props).
    await expect(page.getByText('Marlin Fasteners & Supply Co.')).toHaveCount(0);
});
