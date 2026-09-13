import type { HelpTopic } from '@/help/types';

/**
 * `Contacts/Show`, `Contacts/Create`, `Contacts/Edit` — specs.md §8.1/8.2/8.3
 * (FR-CRM-02, US-CRM-01, BR-CRM-02).
 *
 * Presupuneri de buton semnalate în raport: „Save", „Delete", „Mark as primary" —
 * confirmate la construirea paginilor (pachet paralel).
 */
const contactDetail: HelpTopic = {
    id: 'contact-detail',
    title: 'Contact detail',
    whatIsThis:
        "This is one person's record — their details, which account they belong to, and whether they're the primary contact there.",
    whatCanYouDo: [
        'Edit name, email, phone and title.',
        'Link the contact to an account, or leave it unlinked for now.',
        'Mark this contact as the primary one for its account.',
        'Opt the contact out of marketing communications.',
        'Delete the contact, if nothing depends on it.',
    ],
    rules: [
        'Only one contact per account can be primary — marking this one as primary automatically un-marks whichever contact held that spot before.',
        "If the email already belongs to another account's contact, saving warns you and links to that account instead of silently overwriting it — you can still save if it's intentional.",
        '"Opt out" only suppresses marketing messages, not transactional ones (order confirmations, invoices), and does not delete the contact.',
        "Deleting a contact is blocked while it has linked deals or orders — the record stays, the delete button explains why.",
    ],
    howItsBuilt: {
        summary:
            "The \"single primary contact per account\" rule is enforced at save time on the server, not just in the form: saving a new primary contact demotes the previous one in the same transaction, so there's never a moment with two primaries or none. No dedicated ADR — it's a straightforward business rule (FR-CRM-02), not an architecture decision.",
    },
};

export default contactDetail;
