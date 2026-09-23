import { router } from '@inertiajs/react';

/**
 * A11Y-01 (audit accesibilitate 2026-09-23) — mecanism GLOBAL, înregistrat o SINGURĂ dată
 * la bootstrap (`app.tsx`, import de efect, exact ca `lib/i18n.ts`), nu per formular.
 * `Form/Field.tsx` e importat în 31 de fișiere; fără acest modul, fiecare ar fi trebuit
 * să-și mute singur focusul pe eroare — 31 de copii ale aceleiași logici.
 *
 * `router.on('error', …)` ascultă evenimentul `inertia:error` (`@inertiajs/core`), care se
 * declanșează de fiecare dată când răspunsul unei vizite conține un bag `errors` nevid —
 * fie că vizita a pornit dintr-un `useForm().post()`, fie dintr-un `router.post()` direct
 * (`Settings/Members/Index.tsx`, `Reports/Show.tsx` ș.a.). Nu există echivalent per-pagină
 * de ascultat aici: bag-ul ajunge prin props Inertia, nu printr-un `onError` local — un
 * apelant care NU și-a atașat `onError` propriu tot beneficiază de mutarea focusului.
 */
const INVALID_FIELD_SELECTOR = '[aria-invalid="true"]';

/**
 * Funcție de MODUL, nu closure dintr-un component/hook — `react-hooks/immutability`
 * (`eslint-plugin-react-hooks`, activ în `eslint.config.js`) interzice mutarea unei valori
 * globale (aici, `focus()`, care mută starea globală a documentului) din corpul unui
 * component. `hooks/useThemeSync.ts` (`applyResolvedTheme`) și `lib/i18n.ts`
 * (`applyDocumentLocale`) rezolvă identic pentru aceeași regulă — al treilea caz, același
 * tipar (`.ai/rules/frontend.md`, „O mutație pe `document.documentElement` se scrie într-o
 * funcție din afara componentei").
 */
function focusFirstInvalidField(): void {
    if (typeof document === 'undefined') {
        return;
    }

    const active = document.activeElement;

    if (active instanceof HTMLElement && active.matches(INVALID_FIELD_SELECTOR)) {
        // Utilizatorul e deja pe UN câmp invalid (ex. a corectat o eroare și a retrimis,
        // iar o alta a apărut pe un câmp diferit, în care nu se află) — nu-i furăm focusul.
        return;
    }

    // Un dialog deschis (`<dialog open>`) e căutat ÎNTÂI: formularele din dialogurile
    // Members (`ChangeRoleDialog`, `DeactivateMemberDialog`) validează în interior, iar
    // `#main-content` de dedesubt n-are niciun câmp invalid de găsit. Fără dialog deschis,
    // căutarea cade pe `#main-content` (randat de ambele layout-uri, `GuestLayout.tsx` de la
    // A11Y-09); `document` rămâne ultimul refugiu pentru o pagină fără layout.
    const openDialog = document.querySelector<HTMLElement>('dialog[open]');
    const scope = openDialog ?? document.getElementById('main-content') ?? document;
    const target = scope.querySelector<HTMLElement>(INVALID_FIELD_SELECTOR);

    target?.focus();
}

/**
 * `requestAnimationFrame`: evenimentul `inertia:error` se declanșează SINCRON din
 * `setPage()` (`@inertiajs/core`), înainte ca React să fi apucat să randeze
 * `aria-invalid="true"` pe DOM — actualizarea props-urilor precede randarea, exact
 * secvențializarea documentată în `.ai/rules/frontend.md` („O regiune `aria-live` nu
 * reacționează la `setState`, ci la mutația DOM-ului"). Un `requestAnimationFrame`
 * garantează că bucla de randare a React a apucat deja să treacă prin DOM.
 */
router.on('error', () => {
    requestAnimationFrame(focusFirstInvalidField);
});
