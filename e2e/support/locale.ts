import type { Page } from '@playwright/test';

export type AppLocale = 'en' | 'fr';

/**
 * Lot I18N, Val 5 — oglinda EXACTĂ a lui `setUserTheme` (`e2e/support/theme.ts`), pentru
 * `PATCH /preferences/locale` (`LocaleController::update`) în loc de `/preferences/theme`.
 *
 * De ce prin cerere directă și nu prin `LocaleToggle` din UI, în majoritatea testelor de aici:
 * `App\Support\LocalePreference::resolveForRequest()` citește ÎNTÂI `users.locale` al
 * contului AUTENTIFICAT curent (nivelul 1, câștigă mereu peste cookie) — exact ca la temă
 * (P2-004). Testele de layout (`i18n-layout.spec.ts`) au nevoie doar de EFECTUL comutării
 * (randare server-side în franceză), nu de mecanismul ei — deja acoperit separat, la nivel de
 * browser, în `locale-fr.spec.ts` (`LocaleToggle`). A trece prin UI de fiecare dată ar cupla
 * fiecare test de layout la implementarea butonului, fără folos suplimentar.
 *
 * ATENȚIE — stare PARTAJATĂ: `users.locale` e o coloană pe rândul contului demo, RE-FOLOSIT de
 * toate cele 69+ teste ale suitei prin `storageState`-urile scrise o singură dată de
 * `e2e/setup/auth.setup.ts` (rămas fixat pe engleză, § docblock-ul lui). Orice test care apelează
 * `setUserLocale(page, 'fr')` TREBUIE să revină la `'en'` înainte de a se termina (succes SAU
 * eșec — `test.afterEach`, nu ultima linie a testului), altfel following testele care refolosesc
 * ACELAȘI rol demo (owner/manager/agent/viewer, pe orice tenant) ar randa neașteptat în franceză
 * — cei 297 de selectori pe text englez ai suitei existente ar pica la autentificare tăcut mai
 * departe, nu chiar în testul care a lăsat starea murdară.
 */
export async function setUserLocale(page: Page, locale: AppLocale): Promise<void> {
    const cookies = await page.context().cookies();
    const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;

    const response = await page.request.patch('/preferences/locale', {
        headers: token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {},
        data: { locale },
        // `LocaleController::update()` întoarce `back()` — un 302, nu un 200 (vezi
        // `setUserTheme` pentru exact același raționament).
        maxRedirects: 0,
        failOnStatusCode: false,
    });

    if (response.status() >= 400) {
        throw new Error(`PATCH /preferences/locale a răspuns ${response.status()} pentru locale="${locale}" — CSRF sau sesiune invalidă?`);
    }
}
