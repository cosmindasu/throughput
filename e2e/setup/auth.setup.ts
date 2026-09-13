import { expect, test as setup } from '@playwright/test';
import { authFile, DEMO_ROLE_LABELS, DEMO_ROLES } from '../support/auth';

/**
 * Autentificare „un click" (FR-PUB-02, BR-PUB-01) — intră pe `/login` și apasă
 * butonul „Log in as …" al fiecărui rol, apoi salvează `storageState`-ul
 * rezultat. Testele din `e2e/specs/` NU se loghează niciodată singure: preiau
 * sesiunea gata autentificată prin `test.use({ storageState: authFile(role) })`.
 *
 * Un singur fișier, patru teste (nu patru fișiere `.setup.ts`): rulează
 * secvențial oricum (`workers: 1`), iar un singur loc de citit ține cele patru
 * conturi vizibil grupate — exact „login succesiv cu cele 4 conturi" din
 * §24.3 pct. 1.
 *
 * Numele accesibil al butonului NU e doar „Log in as {rol}": include și
 * propoziția descriptivă din `<span>`-ul următor (fără `aria-label` separat pe
 * buton, cele două `<span>` se concatenează în numele accesibil) — de aceea
 * potrivirea de mai jos e pe substring (implicit în `getByRole`), nu `exact`.
 */
for (const role of DEMO_ROLES) {
    setup(`autentificare demo — ${role}`, async ({ page }) => {
        await page.goto('/login');

        await page.getByRole('button', { name: `Log in as ${DEMO_ROLE_LABELS[role]}` }).click();

        // DemoLoginController -> redirect()->intended(route('dashboard')) ->
        // DashboardController::redirectToDefaultWorkspace() -> /{workspace}/dashboard.
        await expect(page).toHaveURL(/\/[a-z-]+\/dashboard$/);

        await page.context().storageState({ path: authFile(role) });
    });
}
