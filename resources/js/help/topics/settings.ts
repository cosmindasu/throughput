import type { HelpTopic } from '@/help/types';

/**
 * `Settings/Index` — specs.md §7.1/7.3/7.4 (matricea de permisiuni), hub-ul din care pornesc
 * Preferences, Pipeline, Members, Billing etc.
 *
 * Reconciliat cu codul la 2026-09-20, la integrarea valului 2 al Fazei 5: cardurile din
 * `Pages/Settings/Index.tsx` (`SECTIONS`, butonul „Open") și `can` din
 * `SettingsController::index()`, calculat din `App\Support\Permissions::forRoles()`.
 *
 * Textul de dinainte descria Billing și API Tokens ca fiind încă „placeholders" cu insignă
 * „Coming in a later phase" — adevărat când a fost scris, fals de la valul 1 încoace. BR-HELP-04
 * cere ca subiectul să fie contemporan cu ecranul; aici n-a fost, fiindcă ecranul s-a schimbat
 * din alt lot decât cel care ținea textul. De aceea recitirea manualului e un task explicit în
 * Faza 6, nu o presupunere.
 */
const settings: HelpTopic = {
    id: 'settings',
    title: 'Settings',
    whatIsThis:
        "This is the hub for everything about how your workspace and your account are configured — not customer data, just setup.",
    whatCanYouDo: [
        'Open "Preferences" to change your theme (System, Light or Dark).',
        'Open "Pipeline" to see the stages used by the deals board — and change them, if you are an Owner or Manager.',
        'Open "Members" to invite someone, change a role, or deactivate someone who left, if you are an Owner or Manager.',
        'Open "Export data" to request a machine-readable copy of everything this workspace holds.',
        'See which workspace sections your role has — every card here leads to a real screen.',
    ],
    rules: [
        'Every role sees "Preferences". "Pipeline" shows for Owner, Manager and Viewer; "Members", "API Tokens", "Sent Emails" and "Export data" for Owner and Manager; "Billing & Subscription", "Carrier settings" and "Webhook health" only for Owner — so an Agent sees just "Preferences".',
        '"Sent Emails" only appears while the public demo guardrails are on — with them off nothing is ever recorded, so the screen is hidden rather than shown permanently empty.',
        'Manager has full operational access everywhere else, but not to billing: the "Billing & Subscription" card never appears for them.',
        "What you see on this page is computed from your role's actual permissions on the server, not hidden with CSS — a section you can't use doesn't appear at all, rather than appearing and then refusing you.",
        '"Export data" is visible to Owner and Manager, but only an Owner can start an export — a Manager sees the history without the button.',
        '"Webhook health" is Owner-only. It shows what Stripe sent to this deployment, including events that belong to no workspace here; those are marked "ignored", which is not an error.',
    ],
    howItsBuilt: {
        summary:
            'Whether "Billing & Subscription" shows up here is decided server-side, from the same permission matrix used for every other role check in the app (`app/Support/Permissions.php`) — not a separate rule for this one menu. That matrix has already caught one real contradiction before it shipped: an earlier draft accidentally gave Manager read access to billing, and a feature test written directly against the specification (not against the code) failed until the matrix was corrected. No dedicated ADR — this is a settled rule in specs.md §7.4, not an architecture decision.',
    },
};

export default settings;
