import { expect, test } from '@playwright/test';
import { authFile } from '../support/auth';

/**
 * Shell-ul pe pagina PUBLICĂ (`Welcome.tsx`, FR-PUB-01). `AppLayout` o îmbracă și pe ea,
 * deci tot ce presupune un utilizator autentificat trebuie să se comporte și fără unul.
 *
 * Auditul din 2026-09-29: comutatorul de workspace se randa pentru un vizitator anonim și,
 * la clic, deschidea o listă de 10px cu zero opțiuni — raportat ca „opțiunile se deschid în
 * spate". Nu era stivuire: lista era pur și simplu goală.
 */
test.describe('vizitator anonim', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('pagina publică nu arată un comutator de workspace fără workspace-uri', async ({ page }) => {
        await page.goto('/');

        await expect(page.getByRole('heading', { level: 1, name: 'Throughput' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Enter the demo' })).toBeVisible();

        // Controlul în sine nu există — nu „există, dar e gol".
        await expect(page.getByRole('button', { name: /Select workspace/i })).toHaveCount(0);
        await expect(page.getByRole('button', { expanded: false }).filter({ hasText: 'Select workspace' })).toHaveCount(0);
    });
});

/**
 * Reversul, ca ascunderea de mai sus să nu se lățească peste criteriul de acceptanță real
 * (FR-TEN-01: comutatorul e vizibil pentru ORICE rol autentificat, chiar și cu un singur
 * workspace — consecvență vizuală între roluri).
 */
test.describe('utilizator autentificat', () => {
    test.use({ storageState: authFile('owner') });

    test('comutatorul de workspace rămâne în header și se deschide cu opțiuni', async ({ page }) => {
        await page.goto('/cascade/dashboard');

        const trigger = page.getByRole('button', { name: /Cascade Hydraulic Components/ });
        await expect(trigger).toBeVisible();

        await trigger.click();

        const listbox = page.getByRole('listbox');
        await expect(listbox).toBeVisible();
        // Poanta defectului: lista trebuie să aibă CONȚINUT, nu doar să existe.
        expect(await listbox.getByRole('option').count()).toBeGreaterThan(0);
    });
});
