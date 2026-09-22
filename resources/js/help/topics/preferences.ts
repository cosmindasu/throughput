import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Settings/Preferences` — specs.md §15.6 (FR-PREF-01…03, BR-PREF-01…03) și §15.8
 * (FR-I18N-01, granița din FR-I18N-06).
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Settings/Preferences.tsx`, `ThemeToggle.tsx`
 * (montat și în bara de sus, `AppLayout.tsx`), `ThemeController`, `App\Support\ThemePreference`
 * (ordinea de rezoluție: alegerea explicită → cookie → dark) și migrația care face `dark`
 * implicitul coloanei `users.theme`.
 *
 * Re-reconciliat la Valul 4 al Lotului I18N: Valul 1 a adăugat pe ACEST ecran un al doilea
 * rând, `LocaleToggle` + `settings:language.*`, iar subiectul descria în continuare numai
 * tema — un gol de conținut produs chiar de lotul ăsta, nu unul moștenit. În plus, blocul
 * „How it's built" afirma „No dedicated ADR", afirmație devenită falsă în momentul în care
 * ADR-022 a fost acceptat: e decizia dedicată exact mecanismului de pe acest ecran, iar
 * acum e linkată ca atare.
 */
const preferences: HelpTopicDefinition = {
    id: 'preferences',
    adr: {
        id: 'ADR-022',
        title: 'The interface becomes bilingual (EN default + FR) — language is a per-user preference, not a URL segment',
        url: adrUrl('ADR-022', 'locale-en-fr-per-utilizator-nu-in-url'),
    },
};

export default preferences;
