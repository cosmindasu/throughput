import type { HelpTopic } from '@/help/types';

/**
 * `Contacts/Index` — specs.md §8.1/8.3 (FR-CRM-02/03), same list mechanism as
 * Accounts (`ListQuery`/`ResourceList`).
 *
 * Reconciliat cu codul la 2026-09-14: `Pages/Contacts/Index.tsx` (căutare cu „Apply", „Sort by",
 * „Export CSV", „New contact" — și FĂRĂ `SavedViewPicker`: vizualizările salvate există doar pe
 * accounts/deals, `SavedViewResourceType`), `ContactList` (fără filtru implicit pe rol, căutare
 * pe câmpuri separate), `ContactPolicy` și `App\Support\Contacts\ContactErasure` — ștergerea
 * anonimizează în loc să șteargă fizic dacă există deals (inclusiv șterse) sau orders (§20.5);
 * contactele anonimizate dispar din listă și din export (`NotAnonymizedContactScope`).
 */
const contactsList: HelpTopic = {
    id: 'contacts-list',
    title: 'Contacts',
    whatIsThis:
        "Every person you deal with at your accounts — not the companies themselves, the people. A contact can exist before you've linked it to a company yet.",
    whatCanYouDo: [
        'Search by first name, last name or email — one at a time, so "Jane Doe" typed together finds nothing — then press "Apply".',
        'Sort by "Last name (A–Z)", "Newest first" or "Oldest first".',
        'Download the current list with "Export CSV".',
        'Add someone with "New contact" — linked to an account or on their own — or change a contact from its "Edit" link.',
    ],
    rules: [
        'There\'s no "My contacts" default: every role sees every contact in the workspace. What changes by role is who can edit.',
        'An Agent can edit or delete only contacts they created, or contacts at accounts they own; the other rows have no "Edit" link.',
        '"Export CSV" is available to Viewers too — it reads every row matching the current search, all pages of it, and writes nothing. The file includes a "Marketing opt-out" column.',
        "Deleting a contact isn't blocked by deals or orders that reference it: with none, the row is deleted; with any, the contact's personal data (name, email, phone, title) is anonymized instead and the row stays, so deal and order history keeps its link. Anonymized contacts no longer show up here.",
        'Contacts have no saved views yet — "Views" exists on Accounts and Deals only.',
    ],
    howItsBuilt: {
        summary:
            'This list reuses the same cursor-paginated `ListQuery` foundation as Accounts (`ContactList` on the shared `ResourceList`), so it stays fast at the same scale without a separate implementation, and "Export CSV" re-runs exactly this query. There is no dedicated ADR for this screen — the pagination and filtering approach is a functional requirement (FR-CRM-03), not an architecture-level decision.',
    },
};

export default contactsList;
