import type { HelpTopic } from '@/help/types';

/**
 * `Settings/Index` — specs.md §7.1/7.3/7.4 (matricea de permisiuni), hub-ul din
 * care pornesc Preferences, Members, Billing etc. Doar Preferences există în
 * această fază (§3.2) — restul secțiunilor sosesc cu fazele lor, fără să atingă
 * acest subiect.
 */
const settings: HelpTopic = {
    id: 'settings',
    title: 'Settings',
    whatIsThis:
        "This is the hub for everything about how your workspace and your account are configured — not customer data, just setup.",
    whatCanYouDo: [
        'Open "Preferences" to change your theme (System, Light or Dark).',
        "See the sections your role has access to — the list itself changes depending on who you are.",
    ],
    rules: [
        'Only Owner sees "Billing & Subscription" here — Manager has full operational access everywhere else, but not to billing.',
        "What you see on this page is computed from your role's actual permissions, not hidden with CSS — a link you can't use doesn't appear at all, rather than appearing and then refusing you.",
        "More sections (Members, Carrier settings, API tokens, Export data) arrive with the phases that build them — this hub doesn't need to change when they do.",
    ],
    howItsBuilt: {
        summary:
            'Whether "Billing & Subscription" shows up here is decided server-side, from the same permission matrix used for every other role check in the app (`app/Support/Permissions.php`) — not a separate rule for this one menu. That matrix has already caught one real contradiction before it shipped: an earlier draft accidentally gave Manager read access to billing, and a feature test written directly against the specification (not against the code) failed until the matrix was corrected. No dedicated ADR — this is a settled rule in specs.md §7.4, not an architecture decision.',
    },
};

export default settings;
