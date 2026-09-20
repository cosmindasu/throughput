import type { HelpTopic } from '@/help/types';

/**
 * `Orders/Index` — specs.md §11.4/§11.6 (FR-ORD-02), §13.5 (lotul E, valul „bulk"). Tabel
 * filtrabil/sortabil pe cursor, la fel ca `Deals/Index`, plus operații în masă: reasignare
 * owner, anulare de draft-uri, export CSV/PDF.
 */
const ordersList: HelpTopic = {
    id: 'orders-list',
    title: 'Orders — list',
    whatIsThis:
        'Every order this workspace has drafted, confirmed, fulfilled or cancelled, in one filterable, sortable table.',
    whatCanYouDo: [
        'Search by order number and filter by "Status".',
        'Switch between "My orders" and "All orders".',
        'Sort by clicking the "Order number", "Grand total", "Placed at" or "Created" header — click again to reverse.',
        'Open "Views" to apply a saved view, "Save view" to keep the current filters and columns, or "☆ Set default" to open the list on that view.',
        'Open "Columns" to show, hide or reorder the optional columns (Grand total, Placed at, Created, Status, Account, Owner) with checkboxes and "Move up"/"Move down" — the order number column always stays, and a saved view remembers your choice.',
        'Open "New order" to start a draft, or open any row to see its full detail.',
        'Download the filtered list with "Export CSV" or "Export PDF", even without edit rights.',
        'Select several orders with the checkboxes — or "Select all N matching this filter" — and reassign them to a new owner, or cancel the draft ones, in one action, from the bar that appears once you\'ve picked at least one.',
    ],
    rules: [
        'Agents start on "My orders"; everyone else on all orders — same convention as Accounts and Deals.',
        'A draft has no order number yet: it only gets one at confirmation, so abandoned drafts never leave gaps in the numbering (BR-ORD-02).',
        'Viewer can see every order and export the current filter to CSV or PDF, but has no "New order" button and no write action on any row.',
        'An Agent can open any order in the workspace, but only edits or cancels the ones they own; reassigning an owner is Owner/Manager only (same as Deals, unlike Accounts).',
        'Bulk cancel only ever touches the draft orders in your selection — a confirmed or already-shipped order is never affected, even if it was part of "Select all matching this filter". The count shown (and the confirmation threshold it\'s checked against) is always the number of drafts, not the raw filter count.',
        'A PDF export always runs as a background job, even for a handful of rows, and is capped well below the CSV limit — for a large list, use CSV.',
    ],
    howItsBuilt: {
        summary:
            'Same cursor-paginated `ListQuery` foundation as Accounts and Deals (`App\\Support\\Lists\\OrderList`). The status filter is validated against the closed set of values in `App\\Enums\\OrderStatus`, the single place that also owns which status can move to which — see the Order detail topic for why that matters. Bulk cancel narrows its own query to `status = draft` before counting or planning (`App\\Support\\Bulk\\BulkChunkActions::narrowQuery()`), so the row count driving "Select all N" and the confirmation dialog is never the raw filter — see the "Bulk operation status" topic for the shared mechanism.',
    },
};

export default ordersList;
