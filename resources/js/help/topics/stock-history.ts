import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Stock/History` — FR-STOCK-03, app/Support/Lists/StockMovementList.php.
 */
const stockHistory: HelpTopic = {
    id: 'stock-history',
    title: 'Stock — History',
    whatIsThis:
        "Every stock movement ever recorded for this variant, oldest reasoning kept forever: what changed, by how much, why, who did it and when.",
    whatCanYouDo: [
        'Filter by "Reason" (receipt, sale, adjustment, return or transfer), by location, or by a date range.',
        'See the note attached to a movement — required for every adjustment, optional for the rest.',
        'Follow a transfer\'s two linked rows (one location losing stock, the other gaining the same quantity at the same moment).',
    ],
    rules: [
        'Available to every role that can see stock at all, including Viewer — this screen is read-only by nature, there is nothing here to restrict beyond `stock.view`.',
        'Nothing on this list can be edited or removed, by anyone, at any role: correcting a mistake means recording a new adjustment, not changing what already happened.',
    ],
    howItsBuilt: {
        summary:
            "This list reads directly off `stock_movements`, the append-only ledger — never a summary table that could drift from it. Paging is by cursor, not by page number, so the history stays fast for a variant with years of receipts and sales behind it. `stock:reconcile` (run weekly, and on demand) recalculates on-hand straight from this ledger and reports any mismatch with the live stock numbers — the ledger is treated as the one source of truth, not the number displayed on the stock screen.",
        adr: {
            id: 'ADR-004',
            title: 'Inventory as an append-only ledger, not a mutable quantity',
            url: adrUrl('ADR-004', 'stoc-registru-append-only'),
        },
    },
};

export default stockHistory;
