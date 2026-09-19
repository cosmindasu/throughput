import type { HelpTopic } from '@/help/types';

/**
 * `Products/Index` — specs.md §10 (FR-STOCK-02/03), §13.5 (lotul E, valul „bulk").
 * app/Support/Lists/ProductList.php + ProductPolicy.php.
 */
const productsList: HelpTopic = {
    id: 'products-list',
    title: 'Products',
    whatIsThis:
        "Everything your workspace sells, grouped by product. Each product can have several variants — the actual SKUs you stock and sell — shown once you open it.",
    whatCanYouDo: [
        'Search by product name, filter by category or "Any status", and sort by name or newest.',
        'Open "Views" to apply a saved view, "Save view" to keep the current filters and columns, or "☆ Set default".',
        'Open "Columns" to show, hide or reorder the optional columns (Category, Variants, Status, Low stock, Created) with checkboxes and "Move up"/"Move down" — the product name column always stays. "Low stock" (how many variants are below their reorder threshold) is shown by default, so the alert doesn\'t need to be searched for.',
        'Open a product to see its variants, their stock and their price.',
        'Create a new product with "New product" (Owner and Manager only).',
        'Edit a product from its "Edit" link, or add a variant from inside it.',
        'Select several products with the checkboxes — or "Select all N matching this filter" — and change the price of every one of their variants by a percentage or a fixed amount, or activate/deactivate them, in one action (Owner and Manager only).',
    ],
    rules: [
        'Owner and Manager have full read/write access; Agent and Viewer can only look — no "New product", "Edit", "Delete" or bulk actions appear for them.',
        "A product can't be deleted while any of its variants has stock history or has been used on an order — the delete button stays visible, but explains why it's blocked rather than failing with a server error.",
        'A bulk price change applies to every variant of every selected product at once — never below zero, rounded to 2 decimals.',
        'Re-running the same price change (a retry of the same background step) never doubles up — it\'s applied exactly once per bulk operation, no matter how many times that step is retried.',
    ],
    howItsBuilt: {
        summary:
            'Same list mechanics as Accounts and Deals: one `ListQuery` reads filters, sort and cursor from the URL, `ProductList` says what they mean for products, and paging is by cursor so the catalog stays fast past a few thousand SKUs. The bulk price change is idempotent by a marker column on `variants` (the id of the last bulk price operation applied), written in the same `UPDATE` as the new price — a percentage can\'t be re-derived from "does the price differ", since the new price always depends on the old one. See the "Bulk operation status" topic for the shared queue mechanism.',
    },
};

export default productsList;
