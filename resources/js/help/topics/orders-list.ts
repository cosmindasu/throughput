import type { HelpTopic } from '@/help/types';

/**
 * `Orders/Index` — specs.md §11.4/§11.6 (FR-ORD-02), plan §9 task 1. Tabel filtrabil/
 * sortabil pe cursor, la fel ca `Deals/Index`.
 */
const ordersList: HelpTopic = {
    id: 'orders-list',
    title: 'Orders — list',
    whatIsThis:
        'Every order this workspace has drafted, confirmed, fulfilled or cancelled, in one filterable, sortable table.',
    whatCanYouDo: [
        'Search by order number and filter by "Status" or "Account".',
        'Narrow the date range with "From"/"To" on when the order was created.',
        'Switch between "My orders" and "All orders".',
        'Sort by clicking the "Order number", "Grand total", "Placed at" or "Created" header — click again to reverse.',
        'Open "New order" to start a draft, or open any row to see its full detail.',
    ],
    rules: [
        'Agents start on "My orders"; everyone else on all orders — same convention as Accounts and Deals.',
        'A draft has no order number yet: it only gets one at confirmation, so abandoned drafts never leave gaps in the numbering (BR-ORD-02).',
        'Viewer can see every order and export the current filter to CSV, but has no "New order" button and no write action on any row.',
        'An Agent can open and edit any order, but only edits or cancels the ones they own.',
    ],
    howItsBuilt: {
        summary:
            'Same cursor-paginated `ListQuery` foundation as Accounts and Deals (`App\\Support\\Lists\\OrderList`). The status filter is validated against the closed set of values in `App\\Enums\\OrderStatus`, the single place that also owns which status can move to which — see the Order detail topic for why that matters.',
    },
};

export default ordersList;
