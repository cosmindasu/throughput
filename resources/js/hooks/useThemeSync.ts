import { useEffect } from 'react';

export type ResolvedTheme = 'light' | 'dark';

const THEME_COOKIE = 'theme';
const COOKIE_MAX_AGE = 60 * 60 * 24 * 365; // un an

/**
 * Rezolvă preferința de SISTEM chiar acum, în acest browser. `light` explicit, altfel
 * `dark` — coerentă cu regula server-side din App\Support\ThemePreference pentru absența
 * oricărui semnal de sistem.
 *
 * Rezolvarea rămâne client-side, deliberat, nu din necunoaștere (corecție P2-004,
 * 2026-09-13 — afirmația anterioară de aici era falsă): Chromium (Chrome/Edge/Opera, de
 * la Chrome 93) LIVREAZĂ `Sec-CH-Prefers-Color-Scheme`, opt-in prin `Accept-CH`/
 * `Critical-CH`; doar Firefox și Safari nu. Motivul pentru care Client Hints tot nu se
 * implementează: decizia proprietarului face din DARK implicitul FIX pentru cine n-a
 * ales nimic (specs.md §15.6) — „System" nu mai e nicăieri implicit, apare doar la cine
 * îl alege explicit din comutator, pe un ecran deja autentificat, unde acest `matchMedia`
 * rulează oricum imediat. Client Hints ar elimina o licărire care, pentru acel subset
 * mic de utilizatori, nu mai e problema de prim rang.
 */
export function resolveSystemTheme(): ResolvedTheme {
    if (typeof window === 'undefined' || !window.matchMedia) {
        return 'dark';
    }

    return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
}

export function applyResolvedTheme(resolved: ResolvedTheme): void {
    document.documentElement.classList.toggle('dark', resolved === 'dark');
    document.documentElement.style.colorScheme = resolved;
}

/**
 * Necriptat, citit direct de resources/views/app.blade.php (bootstrap/app.php
 * exceptează `theme` de la EncryptCookies) — FR-PREF-03.
 *
 * `; secure` doar pe HTTPS (P3, review 2026-09-13): `document.cookie` nu are
 * echivalentul lui `config('session.secure')` din ThemeController — flag-ul se
 * decide din `location.protocol`, ca cel puțin pe producție (mereu HTTPS) cookie-ul
 * scris din client să nu rămână mai permisiv decât cel scris de server.
 */
export function persistResolvedThemeCookie(resolved: ResolvedTheme): void {
    const secure = typeof location !== 'undefined' && location.protocol === 'https:' ? '; secure' : '';

    document.cookie = `${THEME_COOKIE}=${resolved}; path=/; max-age=${COOKIE_MAX_AGE}; samesite=lax${secure}`;
}

/**
 * Corectează un prim-paint greșit pe un dispozitiv NOU (fără cookie `theme`) pentru
 * ecranul AUTENTIFICAT unde alegerea explicită e „System" (resources/js/Components/
 * ThemeToggle.tsx). Rescrie clasa de pe `<html>` și cookie-ul din `matchMedia`, apoi
 * urmărește schimbările REALE ale sistemului de operare cât timp fila rămâne deschisă
 * (BR-PREF-01, FR-PREF-03) — serverul nu poate „împinge" o schimbare de SO către un
 * ecran deja randat.
 *
 * Decizia proprietarului (2026-09-13, specs.md §15.6) restrânge domeniul acestei
 * funcții față de varianta inițială: vizitatorul anonim (resources/js/Pages/Welcome.tsx)
 * NU mai urmează sistemul de operare — implicitul lui e ÎNCHIS fix, nu „System". Singurul
 * apelant rămas e `ThemeToggle`, activ doar cât timp alegerea persistată e „System".
 *
 * Limita asumată, documentată în raport: pe ACEL prim-paint (dispozitiv nou, alegere
 * System, SO pe temă deschisă), utilizatorul vede o clipă tema închisă — implicitul pe
 * care App\Support\ThemePreference îl aplică pentru absența unui semnal de sistem —
 * înainte de corecția de mai jos.
 */
export function useThemeSync(active: boolean): void {
    useEffect(() => {
        if (!active || typeof window === 'undefined' || !window.matchMedia) {
            return;
        }

        const media = window.matchMedia('(prefers-color-scheme: light)');

        const sync = () => {
            const resolved = resolveSystemTheme();
            applyResolvedTheme(resolved);
            persistResolvedThemeCookie(resolved);
        };

        sync();
        media.addEventListener('change', sync);

        return () => media.removeEventListener('change', sync);
    }, [active]);
}
