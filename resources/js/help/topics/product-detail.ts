import type { HelpTopic } from '@/help/types';

/**
 * `Products/Show`, `Products/Create`, `Products/Edit` — specs.md §10.2, §10.7.
 */
const productDetail: HelpTopic = {
    id: 'product-detail',
    title: 'Product',
    whatIsThis:
        'One product and every variant sold under it — a variant is a specific SKU (a size, a pack size, a finish) with its own price, cost and stock.',
    whatCanYouDo: [
        'Change the product\'s name, category and unit of measure from "Edit".',
        'Add a new SKU with "Add variant".',
        'Open a variant\'s "Stock" link to receive, adjust or transfer its stock, or "History" to see every past movement.',
        'Delete the product once none of its variants have stock history or orders against them.',
    ],
    rules: [
        'Only Owner and Manager can create, edit or delete products and variants — Agent and Viewer see the same screen read-only, with no "Edit", "Add variant" or "Delete".',
        "A variant's cost (and therefore its margin) is never sent to Agent or Viewer accounts — not just hidden in the layout, removed from the data the page receives.",
        "Deleting a product or a variant is refused, with a plain-language reason, once it has recorded stock movements or has been used on an order — that history is never silently dropped.",
        'A "Low stock" badge appears next to a variant once its available quantity drops below the threshold set on it (see "Variant") — a variant without a threshold never shows it.',
    ],
    howItsBuilt: {
        summary:
            'A variant\'s on-hand, reserved and available quantities are read straight off `inventory_levels`, the same materialized projection the stock ledger keeps — never recomputed by summing the ledger on every page view.',
    },
};

export default productDetail;
