import type { HelpTopic } from '@/help/types';

/**
 * `Products/Index` — specs.md §10 (FR-STOCK-02/03), app/Support/Lists/ProductList.php +
 * ProductPolicy.php.
 */
const productsList: HelpTopic = {
    id: 'products-list',
    title: 'Products',
    whatIsThis:
        "Everything your workspace sells, grouped by product. Each product can have several variants — the actual SKUs you stock and sell — shown once you open it.",
    whatCanYouDo: [
        'Search by product name, filter by category or "Any status", and sort by name or newest.',
        'Open a product to see its variants, their stock and their price.',
        'Create a new product with "New product" (Owner and Manager only).',
        'Edit a product from its "Edit" link, or add a variant from inside it.',
    ],
    rules: [
        'Owner and Manager have full read/write access; Agent and Viewer can only look — no "New product", "Edit" or "Delete" appears for them.',
        "A product can't be deleted while any of its variants has stock history or has been used on an order — the delete button stays visible, but explains why it's blocked rather than failing with a server error.",
    ],
    howItsBuilt: {
        summary:
            'Same list mechanics as Accounts and Deals: one `ListQuery` reads filters, sort and cursor from the URL, `ProductList` says what they mean for products, and paging is by cursor so the catalog stays fast past a few thousand SKUs.',
    },
};

export default productsList;
