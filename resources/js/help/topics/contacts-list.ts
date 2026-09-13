import type { HelpTopic } from '@/help/types';

/**
 * `Contacts/Index` — specs.md §8.1/8.3 (FR-CRM-02/03), same list mechanism as
 * Accounts (`ListQuery`/`ResourceList`).
 *
 * Presupuneri de buton semnalate în raport: „New Contact", „Export CSV" —
 * confirmate la construirea `Contacts/Index.tsx` (pachet paralel).
 */
const contactsList: HelpTopic = {
    id: 'contacts-list',
    title: 'Contacts',
    whatIsThis:
        "Every person you deal with at your accounts — not the companies themselves, the people. A contact can exist before you've linked it to a company yet.",
    whatCanYouDo: [
        'Search by name or email and filter the list.',
        'Save the current filters as a view, private or shared with the team.',
        'Export the filtered list to CSV.',
        'Open "New Contact" to add one, linked to an account or on its own.',
    ],
    rules: [
        'Export is available to Viewers too — it reads the rows already on screen, it does not write anything.',
        "A contact can be marked \"opt out\" of marketing — that only suppresses future marketing messages, it doesn't affect transactional ones like order confirmations or invoices, and it doesn't delete the contact.",
        "An Agent can only edit contacts linked to accounts they own or created; the rest of the list is still visible, just read-only for them.",
        'Deleting a contact is blocked while it has linked deals or orders, the same rule as accounts.',
    ],
    howItsBuilt: {
        summary:
            'This list reuses the same cursor-paginated `ListQuery` foundation as Accounts, so it stays fast at the same scale without a separate implementation. There is no dedicated ADR for this screen — the pagination and filtering approach is a functional requirement (FR-CRM-03), not an architecture-level decision.',
    },
};

export default contactsList;
