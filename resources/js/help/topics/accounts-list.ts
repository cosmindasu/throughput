import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Accounts/Index` — specs.md §8 (FR-CRM-01/03, US-CRM-01…03), §15 (vizualizări
 * salvate), app/Support/Lists/AccountList.php + AccountPolicy.php.
 *
 * Reconciliat cu codul la 2026-09-13: butoanele și filtrele din `Pages/Accounts/Index.tsx`
 * („Export CSV", „New account", „Owner", „Status", „Sort by"), etichetele din
 * `SavedViewPicker.tsx` („Views", „Save view", „My views"/„Team views", „☆ Set default"),
 * `can` din `AccountController::index()`, implicitul „My accounts" din
 * `AccountList::defaultFilters()`, `SavedViewPolicy` și `SavedViewDefaultRedirect`.
 */
const accountsList: HelpTopic = {
    id: 'accounts-list',
    title: 'Accounts',
    whatIsThis:
        'Every company your workspace sells to or is talking to, in one list. This is where you search, filter and act on more than one account at a time.',
    whatCanYouDo: [
        'Search by account name, filter by "Status" and sort by name or newest.',
        'Use the "Owner" filter to see "My accounts", "All accounts", "Unassigned" accounts or one teammate\'s accounts.',
        'Open "Views" to apply a saved view, "Save view" to keep the current filters, or "☆ Set default" to open the list on that view.',
        'Download the filtered list with "Export CSV", even without edit rights.',
        'Create an account with "New account", or change one from its "Edit" link.',
        'Select several accounts with the checkboxes — or "Select all N matching this filter" — and reassign them to a new owner in one action, from the bar that appears once you\'ve picked at least one.',
    ],
    rules: [
        'Agents start on "My accounts"; everyone else starts on all accounts. The filter changes what you see, not what you can edit — an Agent only gets "Edit" on accounts they own or created.',
        '"Unassigned" means no owner, or an owner who is no longer an active member — deactivating someone never silently reassigns their records.',
        '"Export CSV" works for every role, including Viewer: it reads every row matching the current filters — all pages, not just the one on screen — and writes nothing. Up to 5,000 rows download at once; a bigger export continues on a status page.',
        'Anyone can save, rename and delete their own private views. Only Owner and Manager can save a view for the whole team or rename and delete a team view; everyone else can still apply "Team views".',
        'A default view opens only when the URL carries no filter or sort, so a link a teammate shares always opens with its own filters.',
        'Reassigning owners in bulk is a write, so it follows the same rule as editing one at a time: Viewer never sees the checkboxes, and an Agent can only reassign accounts they own or created, capped at 500 per operation. A confirmation dialog appears above 125 rows for Agent, 1,000 for everyone else who can do it — see the "Bulk operation status" page for what happens after you confirm.',
    ],
    howItsBuilt: {
        summary:
            'The URL for this screen is `/{workspace-slug}/accounts` — every module route carries the workspace in the path, not in a cookie or a hidden session value, so a filtered link (`?filter[status]=active`) is fully shareable between teammates and still resolves to the right tenant on the other end. One `ListQuery` reads filters, sort and cursor from the URL and `AccountList` says what they mean for accounts; the screen, "Export CSV" and saved views all go through that same pair, so an export or a saved view can\'t drift from what the list shows. Paging is by cursor, not by page number, so the list stays fast well past a few thousand rows — there is no "jump to page 40".',
        adr: {
            id: 'ADR-002',
            title: 'Multi-tenancy by path (workspace slug), not by subdomain',
            url: adrUrl('ADR-002', 'tenancy-pe-cale'),
        },
    },
};

export default accountsList;
