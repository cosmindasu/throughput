import type { HelpTopic } from '@/help/types';

/**
 * `Settings/Preferences` — specs.md §15.6 (FR-PREF-01…03, BR-PREF-01…03).
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Settings/Preferences.tsx`, `ThemeToggle.tsx`
 * (montat și în bara de sus, `AppLayout.tsx`), `ThemeController`, `App\Support\ThemePreference`
 * (ordinea de rezoluție: alegerea explicită → cookie → dark) și migrația care face `dark`
 * implicitul coloanei `users.theme`.
 */
const preferences: HelpTopic = {
    id: 'preferences',
    title: 'Preferences',
    whatIsThis: "This is where you control how the app looks to you, personally — nobody else's screen changes when you change yours.",
    whatCanYouDo: [
        'Pick "System", "Light" or "Dark" under "Theme" — the change applies immediately.',
        'Use the same switch in the top bar, on every screen, without coming back here.',
    ],
    rules: [
        'Your theme choice follows you, not your workspace — switching workspaces keeps the same theme.',
        'If you have never picked a theme, you get "Dark".',
        '"System" follows your device\'s light or dark setting, and keeps following it while the tab stays open; a device with no light preference gets the dark theme.',
        'This is available to every role, including Viewer, and it is never disabled in the public demo — it\'s not a business action, just a display preference.',
    ],
    howItsBuilt: {
        summary:
            "Changing this saves your choice on `users.theme` and writes a `theme` cookie holding the resolved value (light or dark). On every full page load the server decides the `dark` class on `<html>` before any JavaScript runs: your saved Light or Dark first, then the cookie — which is how \"System\" gets it right — then dark. So there's no flash of the wrong theme while the page boots. A version that applied the theme after hydration, in a `useEffect`, would flicker — that's explicitly treated as a defect here, not a cosmetic detail. No dedicated ADR; the no-flicker requirement is specs.md §15.6 (FR-PREF-03).",
    },
};

export default preferences;
