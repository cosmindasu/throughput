import type { HelpTopic } from '@/help/types';

/**
 * `Settings/Preferences` — specs.md §15.6 (FR-PREF-01…03, BR-PREF-01…03).
 */
const preferences: HelpTopic = {
    id: 'preferences',
    title: 'Preferences',
    whatIsThis: "This is where you control how the app looks to you, personally — nobody else's screen changes when you change yours.",
    whatCanYouDo: [
        'Choose "System", "Light" or "Dark" for the theme.',
        'The same switch is also available from the top bar, on every screen, not just here.',
    ],
    rules: [
        'Your theme choice follows you, not your workspace — switching workspaces keeps the same theme.',
        '"System" follows your operating system\'s preference; if your system has no preference set, you get the dark theme.',
        'This is available to every role, including Viewer, and it is never disabled in the public demo — it\'s not a business action, just a display preference.',
    ],
    howItsBuilt: {
        summary:
            "Changing this writes both `users.theme` and a `theme` cookie in the same request. The cookie is what matters for the part that's hard to get right: the very first byte of HTML already has the right `dark` class on `<html>`, read server-side before any JavaScript runs — so there's no flash of the wrong theme while the page boots. A version that applied the theme after hydration, in a `useEffect`, would flicker — that's explicitly treated as a defect here, not a cosmetic detail. No dedicated ADR; the no-flicker requirement is specs.md §15.6 (FR-PREF-03).",
    },
};

export default preferences;
