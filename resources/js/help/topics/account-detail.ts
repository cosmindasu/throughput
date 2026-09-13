import type { HelpTopic } from '@/help/types';

/**
 * `Accounts/Show`, `Accounts/Create`, `Accounts/Edit` — specs.md §8.1/8.2/8.3
 * (FR-CRM-01/02/04, US-CRM-01, BR-CRM-01), app/Support/Contacts/DuplicateContactEmail.php,
 * resources/js/Components/DuplicateEmailNotice.tsx.
 *
 * Reconciliat cu codul la 2026-09-13: butoanele din `Pages/Accounts/Show.tsx` („Add contact",
 * „New deal", „Edit", „Delete"), `Components/Accounts/AccountForm.tsx` („Create account",
 * „Save changes", „Primary contact"), `AccountPolicy` (editare/ștergere: Owner și Manager pe
 * orice cont, Agent pe cele proprii sau create de el — nu „doar Owner"),
 * `Account::deletionBlockedReason()`, `AccountController::store()` (owner implicit = creatorul)
 * și `AccountActivityTimeline` (o secțiune „Activity", nu un tab).
 */
const accountDetail: HelpTopic = {
    id: 'account-detail',
    title: 'Account detail',
    whatIsThis:
        "This is one company's full record — its details, its contacts, its deals and a timeline of what happened with it. Creating and editing an account both happen on the same kind of form.",
    whatCanYouDo: [
        'On "New account", fill in the company and its "Primary contact" in the same form — one "Create account" saves both, no separate step for the first contact.',
        'Change details, owner, status, credit terms, tags and addresses with "Edit", then "Save changes".',
        'Start related records with "Add contact" or "New deal" — each opens its form with this account already filled in.',
        'Scan the "Contacts", "Deals" and "Activity" sections — "Activity" mixes deals created, stage moves, orders placed and logged actions, the 30 most recent first.',
        'Remove the account with "Delete".',
    ],
    rules: [
        "An account with deals or orders can't be deleted — \"Delete\" still opens, and the dialog says how many deals and orders are in the way instead of silently failing.",
        'Owner and Manager can edit and delete any account; an Agent only accounts they own or created — everything else on this page stays visible to them, just not editable.',
        'If the contact email you enter is already linked to an account, saving is blocked with a warning and a link to that account — not silently overwritten. Ticking "Save anyway" confirms it\'s intentional (the same person can legitimately work with two companies) and saving proceeds.',
        'Leaving "Owner" on "Unassigned" when you create an account makes you its owner.',
        'Only one contact per account can be primary — marking another contact primary, from its own form, automatically un-marks the previous one.',
    ],
    howItsBuilt: {
        summary:
            'The duplicate-email warning is a validation error you can override: the request stops and nothing is written until you tick "Save anyway". The link to the other account doesn\'t travel inside the error text — the server adds a one-time Inertia flash payload (account id + name), which disappears on the next navigation instead of sticking to the form. The same check runs on the contact form. There\'s no dedicated ADR for this — it\'s an application-level policy, covered directly by feature tests (`DuplicateContactEmailTest`, `DuplicateContactEmailHttpTest`) rather than an architecture decision.',
    },
};

export default accountDetail;
