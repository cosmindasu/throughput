import type { HelpTopic } from '@/help/types';

/**
 * `Accounts/Show`, `Accounts/Create`, `Accounts/Edit` — specs.md §8.1/8.2/8.3
 * (FR-CRM-01/02/04, US-CRM-01, BR-CRM-01), app/Support/Contacts/DuplicateContactEmail.php,
 * resources/js/Components/DuplicateEmailNotice.tsx.
 *
 * Presupuneri de buton semnalate în raport: „Save", „Delete", „New Deal" (link
 * către pipeline din pagina de cont, §9.4 US-DEAL-01) — confirmate la construirea
 * paginilor (pachet paralel).
 */
const accountDetail: HelpTopic = {
    id: 'account-detail',
    title: 'Account detail',
    whatIsThis:
        "This is one company's full record — its details, its contacts, and everything that's happened with it (deals, orders, activity). Creating and editing an account both happen on the same kind of form.",
    whatCanYouDo: [
        "Create the account and its primary contact together, in one \"Save\" — no separate step for the first contact.",
        "Edit the account's details, tags, credit terms and assigned owner.",
        'Review the "Activity" tab: a unified timeline of deals, orders and log entries tied to this account.',
        'Start a new deal for this account without losing your place.',
        'Delete the account, if nothing depends on it.',
    ],
    rules: [
        "Accounts with deals or orders can't be deleted — the delete action explains why instead of silently failing, and deletion itself is normally an Owner-only action.",
        'If the contact email you enter already belongs to another account, saving is blocked with a warning and a link to that account — not silently overwritten. Checking "Save anyway" confirms it\'s intentional (the same person can legitimately work with two companies) and saving proceeds.',
        "Only one contact per account can be marked primary — marking a new one automatically un-marks the previous one.",
        "An Agent can only edit accounts they own or created; everything else on this page is still visible to them, just not editable.",
    ],
    howItsBuilt: {
        summary:
            "The duplicate-email warning isn't a hard validation error: the server flashes a one-time Inertia flash payload (account id + name), which disappears on the next navigation instead of sticking to the form. There's no dedicated ADR for this — it's an application-level policy, covered directly by a feature test (`DuplicateContactEmailTest`) rather than an architecture decision.",
    },
};

export default accountDetail;
