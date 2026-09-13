import { expect, test } from '@playwright/test';
import { authFile, DEMO_ROLE_LABELS, type DemoRole } from '../support/auth';

/**
 * §24.3 pct. 1 / §7.3 — „login succesiv cu fiecare din cele 4 conturi demo pe
 * ACELAȘI ecran → assert pe diferența așteptată între roluri", nu doar 403 la
 * API (research §2, citat în specs.md). Fiecare rol rulează ca test separat, cu
 * `storageState`-ul scris o singură dată de proiectul `setup` — „login succesiv"
 * e satisfăcut la nivelul suitei (patru autentificări, în ordine, în
 * `auth.setup.ts`), nu prin login/logout repetat în interiorul unui singur test,
 * care ar testa `LoginController`, nu RBAC-ul ecranului.
 *
 * Textele sunt citite din paginile reale (`Accounts/Index.tsx`,
 * `Settings/Index.tsx`) — `getByRole` cu numele accesibil exact, nu selectori
 * fragili pe clase CSS.
 */

const ACCOUNTS_URL = '/marlin/accounts';
const SETTINGS_URL = '/marlin/settings';

test.describe('RBAC — lista de conturi', () => {
    test.describe('Owner', () => {
        test.use({ storageState: authFile('owner') });

        test('vede "New account"', async ({ page }) => {
            await page.goto(ACCOUNTS_URL);
            await expect(page.getByRole('link', { name: 'New account' })).toBeVisible();
        });
    });

    test.describe('Manager', () => {
        test.use({ storageState: authFile('manager') });

        test('vede "New account"', { tag: ['@smoke'] }, async ({ page }) => {
            await page.goto(ACCOUNTS_URL);
            await expect(page.getByRole('link', { name: 'New account' })).toBeVisible();
        });
    });

    test.describe('Agent', () => {
        test.use({ storageState: authFile('agent') });

        // US-CRM-02 — Agentul pornește pe filtrul „My accounts", nu pe tot
        // tenantul (AccountList::defaultFilters()).
        test('pornește pe filtrul "My accounts"', async ({ page }) => {
            await page.goto(ACCOUNTS_URL);
            await expect(page.getByLabel('Owner')).toHaveValue('me');
        });
    });

    test.describe('Viewer', () => {
        test.use({ storageState: authFile('viewer') });

        // §7.4 nota ³ / BR-BULK-03 — exportul e o CITIRE, permisă și Viewer-ului;
        // „New account" ar fi o scriere, absentă din DOM (FR-RBAC-01), nu doar
        // dezactivată.
        test('fără "New account", dar cu export', { tag: ['@smoke'] }, async ({ page }) => {
            await page.goto(ACCOUNTS_URL);
            await expect(page.getByRole('link', { name: 'New account' })).toHaveCount(0);
            await expect(page.getByRole('link', { name: 'Export CSV' })).toBeVisible();
        });
    });
});

test.describe('RBAC — Settings', () => {
    test.describe('Owner', () => {
        test.use({ storageState: authFile('owner') });

        test('vede "Billing & Subscription"', async ({ page }) => {
            await page.goto(SETTINGS_URL);
            await expect(page.getByRole('heading', { name: 'Billing & Subscription' })).toBeVisible();
        });
    });

    // §7.1/§7.3, corectat v1.13 (specs.md, nota⁴ de la §7.4): Manager are acces
    // operațional complet, FĂRĂ billing — singurul rol, în afară de Owner, cu
    // drept la `settings.view` complet dar fără cardul de billing.
    const rolesWithoutBilling: DemoRole[] = ['manager', 'agent', 'viewer'];

    for (const role of rolesWithoutBilling) {
        test.describe(DEMO_ROLE_LABELS[role], () => {
            test.use({ storageState: authFile(role) });

            test('NU vede "Billing & Subscription"', async ({ page }) => {
                await page.goto(SETTINGS_URL);
                await expect(page.getByRole('heading', { name: 'Billing & Subscription' })).toHaveCount(0);
            });
        });
    }
});
