import type { Page } from '@playwright/test';

export type ResolvedTheme = 'light' | 'dark';

/**
 * P1 (code review) — setează PREFERINȚA persistă (`users.theme`), nu doar cookie-ul de
 * rezoluție. `ThemePreference::resolveForRequest()` citește ÎNTÂI alegerea explicită a
 * utilizatorului AUTENTIFICAT curent, ÎNAINTE de cookie-ul `theme` — un cookie pus direct
 * (`context().addCookies()`) e complet IGNORAT pentru orice cont cu o alegere `light`/`dark`
 * deja salvată, adică toate conturile demo: migrația
 * `2026_09_13_120000_change_users_theme_default_to_dark.php` pune `users.theme` implicit pe
 * `'dark'`, iar seederele nu trec `theme` explicit. Verificat empiric: cu cookie-ul
 * `theme=light` pus înainte și un cont demo, ecranul iese cu tema ÎNCHISĂ, comutatorul arată
 * „Dark" — exact bug-ul semnalat la review, `a11y.spec.ts` nu scana niciodată tema deschisă.
 *
 * `PATCH /preferences/theme` (`ThemeController::update`) e ruta REALĂ prin care
 * `ThemeToggle.tsx` schimbă preferința — trecută aici prin `page.request`, cu CSRF-ul din
 * cookie-ul `XSRF-TOKEN` (același tipar ca `resources/js/lib/api.ts`: Laravel scrie cookie-ul
 * CRIPTAT pe orice răspuns din grupul `web`, `VerifyCsrfToken` acceptă aceeași valoare înapoi
 * pe `X-XSRF-TOKEN`), nu o simulare de formular — dacă ruta/mijlocitorii se schimbă, testul
 * pică vizibil (răspuns non-2xx), nu tăcut.
 */
export async function setUserTheme(page: Page, theme: ResolvedTheme): Promise<void> {
    const cookies = await page.context().cookies();
    const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;

    const response = await page.request.patch('/preferences/theme', {
        headers: token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {},
        data: { theme },
        // `ThemeController::update()` întoarce `back()` — un 302, nu un 200. NU urmărim
        // redirect-ul (fără `Referer` de pagină reală, `back()` ar nimeri oriunde) — un
        // status < 400 e deja dovada că `PATCH`-ul a fost acceptat.
        maxRedirects: 0,
        failOnStatusCode: false,
    });

    if (response.status() >= 400) {
        throw new Error(
            `PATCH /preferences/theme a răspuns ${response.status()} pentru theme="${theme}" — CSRF sau sesiune invalidă?`,
        );
    }
}
