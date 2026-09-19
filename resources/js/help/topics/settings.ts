import type { HelpTopic } from '@/help/types';

/**
 * `Settings/Index` — specs.md §7.1/7.3/7.4 (matricea de permisiuni), hub-ul din care pornesc
 * Preferences, Pipeline, Members, Billing etc.
 *
 * Reconciliat cu codul la 2026-09-13: cardurile din `Pages/Settings/Index.tsx` (`SECTIONS`,
 * insigna „Coming in a later phase", butonul „Open") și `can` din
 * `SettingsController::index()`, calculat din `App\Support\Permissions::forRoles()`.
 */
const settings: HelpTopic = {
    id: 'settings',
    title: 'Settings',
    whatIsThis:
        "This is the hub for everything about how your workspace and your account are configured — not customer data, just setup.",
    whatCanYouDo: [
        'Open "Preferences" to change your theme (System, Light or Dark).',
        'Open "Pipeline" to see the stages used by the deals board — and change them, if you are an Owner or Manager.',
        'Open "Members" to see who has access to this workspace, and deactivate someone who left, if you are an Owner or Manager.',
        'See which workspace sections your role has: cards marked "Coming in a later phase" have no "Open" button yet.',
    ],
    rules: [
        'Every role sees "Preferences". "Pipeline" shows for Owner, Manager and Viewer; "Members" for Owner and Manager; "Billing & Subscription" only for Owner — so an Agent sees just "Preferences".',
        'Manager has full operational access everywhere else, but not to billing: the "Billing & Subscription" card never appears for them.',
        "What you see on this page is computed from your role's actual permissions on the server, not hidden with CSS — a section you can't use doesn't appear at all, rather than appearing and then refusing you.",
        '"Billing & Subscription" and "API Tokens" are still placeholders: they show the "Coming in a later phase" badge instead of a link.',
    ],
    howItsBuilt: {
        summary:
            'Whether "Billing & Subscription" shows up here is decided server-side, from the same permission matrix used for every other role check in the app (`app/Support/Permissions.php`) — not a separate rule for this one menu. That matrix has already caught one real contradiction before it shipped: an earlier draft accidentally gave Manager read access to billing, and a feature test written directly against the specification (not against the code) failed until the matrix was corrected. No dedicated ADR — this is a settled rule in specs.md §7.4, not an architecture decision.',
    },
};

export default settings;
