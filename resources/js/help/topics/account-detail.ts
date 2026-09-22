import type { HelpTopicDefinition } from '@/help/types';

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
const accountDetail: HelpTopicDefinition = {
    id: 'account-detail',
};

export default accountDetail;
