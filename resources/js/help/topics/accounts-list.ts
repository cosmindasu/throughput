import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Accounts/Index` — specs.md §8 (FR-CRM-01/03, US-CRM-01…03), §15 (vizualizări
 * salvate), app/Support/Lists/AccountList.php + AccountPolicy.php.
 *
 * Presupuneri de buton semnalate în raport: „New Account", „Export CSV", „Save
 * view" — numele exacte se confirmă la construirea `Accounts/Index.tsx` (pachet
 * paralel).
 */
const accountsList: HelpTopic = {
    id: 'accounts-list',
    title: 'Accounts',
    whatIsThis:
        'Every company your workspace sells to or is talking to, in one list. This is where you search, filter and act on more than one account at a time.',
    whatCanYouDo: [
        'Search by name and filter by status.',
        "Switch between \"My accounts\" and \"All accounts\" (the switch itself is only visible to Agents — everyone else always sees all accounts).",
        'Save the current filters as a view — "Save view" — private to you or shared with the team.',
        'Export the filtered list to CSV, even without edit rights.',
        'Open "New Account" to create one.',
    ],
    rules: [
        'Agents see their own accounts by default and can switch to All — the switch changes what they see, not what they can edit.',
        'There\'s also an "Unassigned" view: accounts with no owner, including accounts whose former owner was deactivated — deactivating someone never silently reassigns their records.',
        'Export is available to Viewers too: exporting the rows you can already see on screen is a read, not a write.',
        "A view saved as \"Team\" can be used by anyone in the workspace, but only Owner and Manager can edit or delete it.",
        'The list stays fast well past a few thousand rows because it pages by cursor, not by page number — there is no "jump to page 40".',
    ],
    howItsBuilt: {
        summary:
            'The URL for this screen is `/{workspace-slug}/accounts` — every module route carries the workspace in the path, not in a cookie or a hidden session value, so a filtered link (`?filter[status]=active`) is fully shareable between teammates and still resolves to the right tenant on the other end. Filtering, sorting and the "My accounts" default all live in one shared `ListQuery`/`AccountList` class, reused by every list screen instead of being reimplemented per page.',
        adr: {
            id: 'ADR-002',
            title: 'Multi-tenancy by path (workspace slug), not by subdomain',
            url: adrUrl('ADR-002', 'tenancy-pe-cale'),
        },
    },
};

export default accountsList;
