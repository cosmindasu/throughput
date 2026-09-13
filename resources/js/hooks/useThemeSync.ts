import { useEffect } from 'react';

export type ResolvedTheme = 'light' | 'dark';

const THEME_COOKIE = 'theme';
const COOKIE_MAX_AGE = 60 * 60 * 24 * 365; // un an

/**
 * Rezolvă preferința de SISTEM chiar acum, în acest browser — singurul semnal de
 * încredere disponibil. Niciun browser major nu livrează încă
 * `Sec-CH-Prefers-Color-Scheme` (propunere WICG, nefinalizată la data scrierii — vezi
 * raportul agentului), altfel rezoluția „System" s-ar face server-side, prin
 * Accept-CH/Critical-CH, fără niciun cod client. `light` explicit, altfel `dark` —
 * coerentă cu regula server-side din App\Support\ThemePreference pentru absența
 * oricărui semnal de sistem.
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
 */
export function persistResolvedThemeCookie(resolved: ResolvedTheme): void {
    document.cookie = `${THEME_COOKIE}=${resolved}; path=/; max-age=${COOKIE_MAX_AGE}; samesite=lax`;
}

/**
 * Corectează un prim-paint greșit pe un dispozitiv NOU (fără cookie `theme`) pentru
 * orice ecran unde preferința efectivă e „System" — inclusiv un vizitator anonim, care
 * nu are nicio alegere persistată și e deci mereu, implicit, „System"
 * (resources/js/Pages/Welcome.tsx). Rescrie clasa de pe `<html>` și cookie-ul din
 * `matchMedia`, apoi urmărește schimbările REALE ale sistemului de operare cât timp
 * fila rămâne deschisă (BR-PREF-01, FR-PREF-03) — serverul nu poate „împinge" o
 * schimbare de SO către un ecran deja randat.
 *
 * Limita asumată, documentată în raport: pe ACEL prim-paint (dispozitiv nou, alegere
 * System, SO pe temă deschisă), vizitatorul vede o clipă tema închisă — implicitul pe
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
