import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Stock/Show` — specs.md §10.2…§10.5 (BR-STOCK-01…04, US-STOCK-01…03),
 * app/Actions/Stock/RecordStockMovementAction.php, TransferStockAction.php.
 */
const stock: HelpTopic = {
    id: 'stock',
    title: 'Stock',
    whatIsThis:
        "How much of one variant you have, at each of your locations, right now — and how much of it is actually free to sell.",
    whatCanYouDo: [
        'See "On hand" (physically in the location), "Reserved" (allocated to confirmed orders) and "Available" (on hand minus reserved) per location.',
        'Use "Receive stock" to record incoming inventory, with an optional reference note (a PO number, for example).',
        'Use "Adjust" to correct a count after a physical audit — a reason is required, so the correction stays explainable later.',
        'Use "Transfer" to move stock from one location to another.',
        'Open "View history" to see every past movement for this variant.',
    ],
    rules: [
        'Only Owner and Manager can receive, adjust or transfer stock — Agent and Viewer see the same numbers read-only, with none of the three buttons.',
        'An adjustment always requires a note explaining the correction — there is no way to change a quantity without saying why.',
        "A transfer is rejected if the source location doesn't have enough on hand — moving stock that isn't physically there isn't allowed, unlike an order, which can knowingly go over the available quantity as a backorder.",
        '"Available" is what matters when building an order — never "On hand" alone, which ignores what other orders already claimed.',
        'A "Low stock" badge appears at the top of this page once an active variant\'s total available quantity, across every location, drops below its configured threshold (set on the variant itself, not here) — an inactive variant never shows it.',
    ],
    howItsBuilt: {
        summary:
            "Every button on this page writes to the append-only stock ledger (`stock_movements`) and updates the on-hand projection (`inventory_levels`) in the very same database transaction — never as two separate steps. A transfer is really two linked ledger entries (one out, one in) sharing a reference id, written together. Nothing here ever runs an UPDATE or a DELETE on a past movement: a correction is always a new entry, so the full history stays intact and explainable — the same reasoning behind never touching a customer invoice line after it's issued, applied here to inventory.",
        adr: {
            id: 'ADR-004',
            title: 'Inventory as an append-only ledger, not a mutable quantity',
            url: adrUrl('ADR-004', 'stoc-registru-append-only'),
        },
    },
};

export default stock;
