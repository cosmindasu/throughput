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
 *
 * Lot I18N (ADR-022) — cuplarea pe text literal de mai jos rămâne DELIBERATĂ
 * (vezi comentariul din `../support/auth.ts:12-17`, nemodificat): dacă eticheta
 * din backend se schimbă, testul de setup trebuie să pice, nu să tacă printr-un
 * selector „mai tolerant" (`data-testid`). Mitigarea din ADR-022 NU slăbește
 * cuplarea, ci garantează premisa ei: `/login` se randează în engleză pentru
 * suita asta (fixat la nivel de configurație, `playwright.config.ts` —
 * `APP_LOCALE=en`), nu per test și nu aici.
 *
 * Aserțiunea de mai jos verifică EXACT acea premisă, la locul unde selectorul
 * literal depinde de ea — dacă vreodată `LocalePreference` ar servi `/login`
 * în altă limbă pentru acest cont (regresie de configurare, nu de traducere),
 * testul pică AICI, cu un mesaj clar („expected lang=en, primit lang=fr"), nu
 * mai târziu, pe un timeout opac de „buton negăsit" care ar ascunde cauza reală
 * în spatele unui fals „butonul s-a tradus".
 */
for (const role of DEMO_ROLES) {
    setup(`autentificare demo — ${role}`, async ({ page }) => {
        await page.goto('/login');

        await expect(page.locator('html')).toHaveAttribute('lang', 'en');

        await page.getByRole('button', { name: `Log in as ${DEMO_ROLE_LABELS[role]}` }).click();

        // DemoLoginController -> redirect()->intended(route('dashboard')) ->
        // DashboardController::redirectToDefaultWorkspace() -> /{workspace}/dashboard.
        await expect(page).toHaveURL(/\/[a-z-]+\/dashboard$/);

        await page.context().storageState({ path: authFile(role) });
    });
}
