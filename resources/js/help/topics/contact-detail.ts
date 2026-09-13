import type { HelpTopic } from '@/help/types';

/**
 * `Contacts/Show`, `Contacts/Create`, `Contacts/Edit` — specs.md §8.1/8.2/8.3
 * (FR-CRM-02, US-CRM-01, BR-CRM-02).
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Contacts/Show.tsx` („Edit", „Delete", „Marketing",
 * „Deals as primary contact"), `Components/Contacts/ContactForm.tsx` (etichetele câmpurilor,
 * `AccountCombobox`, „Create contact"/„Save changes"), `StoreContactRequest`/
 * `UpdateContactRequest` (primary cere cont), `PrimaryContactAssignment`, `ContactPolicy` și
 * `ContactController::destroy()` — ștergerea NU e blocată de deals/comenzi (FK-uri
 * `nullOnDelete`), exact cum spune dialogul de confirmare.
 */
const contactDetail: HelpTopic = {
    id: 'contact-detail',
    title: 'Contact detail',
    whatIsThis:
        "This is one person's record — their details, which account they belong to, whether they're the primary contact there, and the deals that name them as primary contact.",
    whatCanYouDo: [
        'Change name, "Job title", email and phone with "Edit", then "Save changes" ("Create contact" on a new one).',
        'Link, move or unlink the company in "Account": search by name and pick one, or "Clear" it for a lead without a company. "Add contact" on an account page fills it in for you.',
        'Tick "Primary contact for this account" to make this person the account\'s primary contact.',
        'Tick "Opted out of marketing communications" — the page then shows "Opted out" under "Marketing".',
        'Remove the contact with "Delete".',
    ],
    rules: [
        'Only one contact per account can be primary — saving this one as primary automatically un-marks whichever contact held that spot before.',
        'A primary contact must belong to an account: the checkbox stays disabled until an account is chosen, and clearing the account un-ticks it.',
        'If the email already belongs to another contact linked to an account, saving is blocked with a warning and a link to that account instead of silently going through — tick "Save anyway" if it\'s intentional.',
        "Deleting is allowed even when deals or orders reference this contact: they keep their history but lose the link, and it can't be undone. Opting out, by contrast, keeps the contact.",
        'An Agent can edit or delete only contacts they created, or contacts at accounts they own.',
    ],
    howItsBuilt: {
        summary:
            "The \"single primary contact per account\" rule is enforced at save time on the server, not just in the form: the save locks the account row, demotes the previous primary and stores the new one in the same transaction, so two concurrent saves can never leave two primaries. A partial unique index on `contacts` (one primary row per account) is a second safety net in the database itself. No dedicated ADR — it's a straightforward business rule (FR-CRM-02), not an architecture decision.",
    },
};

export default contactDetail;
