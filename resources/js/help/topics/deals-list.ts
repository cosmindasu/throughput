import type { HelpTopic } from '@/help/types';

/**
 * `Deals/Index` — specs.md §9, §15 (vizualizări salvate aplicate și pe Deals).
 * Vedere alternativă la `Deals/Kanban` pentru cine preferă un tabel filtrabil/
 * sortabil în locul board-ului.
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Deals/Index.tsx` (căutare, „Status", „My deals"/
 * „All deals", antete sortabile, `SavedViewPicker`, `ViewSwitcher` „List"/„Board" — fără export
 * CSV și fără buton de creare), `DealList` (implicit `owner=me` pentru Agent, sortarea pe
 * `value_sort`). Formularul de deal cu câmp „Account" căutabil e schimbarea paralelă descrisă
 * în `deal-detail.ts`.
 */
const dealsList: HelpTopic = {
    id: 'deals-list',
    title: 'Deals — list',
    whatIsThis:
        'The same deals as the Kanban board, as a sortable, filterable table — useful when you want to sort by value or close date rather than scan columns.',
    whatCanYouDo: [
        'Search by title (press Enter or leave the field) and filter by "Status": "Open", "Won" or "Lost".',
        'Switch between "My deals" and "All deals".',
        'Sort by clicking the "Title", "Value", "Expected close" or "Created" header — click again to reverse.',
        'Open "Views" to apply a saved view, "Save view" to keep the current filters, or "☆ Set default".',
        'Switch to "Board" for the Kanban layout, or open a deal from its title or its "Edit" link.',
    ],
    rules: [
        'Agents start on "My deals"; everyone else on all deals. A default saved view, if you set one, wins whenever the URL has no filter or sort.',
        'There is no "New deal" button on this list: start a deal from "New deal" on an account page, or from "Create deal" in search (Cmd+K / Ctrl+K), where you pick the account.',
        'Deals have no CSV export yet — "Export CSV" exists on Accounts and Contacts.',
        "Sorting and filtering here never change a deal's stage — moving stage only happens from the board or from the deal's own page.",
        'Only Owner and Manager can save a team view or rename and delete one; every role can keep private views.',
    ],
    howItsBuilt: {
        summary:
            'Same cursor-paginated `ListQuery` foundation as Accounts and Contacts, with one twist: a deal\'s value can be empty, and cursor paging compares on the sort column, so sorting by value runs on a generated, never-empty `value_sort` column (an empty value counts as -1). Sorting on the raw column would make deals without a value drop out of every page instead of just sorting to one end. See the Kanban topic\'s "How it\'s built" for why deal-stage history itself is stored the way it is (append-only, not a mutable column).',
    },
};

export default dealsList;
