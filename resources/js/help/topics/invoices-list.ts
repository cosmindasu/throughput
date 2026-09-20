import type { HelpTopic } from '@/help/types';

/**
 * `Invoices/Index` — specs.md §12.1 (FR-BILL, US-BILL-01/02), plan §11 (Faza 5, lotul A).
 * Listă simplă, pe cursor, la fel ca Orders — fără selector de coloane, vizualizări
 * salvate sau operații în masă (niciuna cerută pentru acest modul în MVP).
 */
const invoicesList: HelpTopic = {
    id: 'invoices-list',
    title: 'Invoices — list',
    whatIsThis:
        'Every invoice this workspace has raised against a confirmed or fulfilled order — its status, its balance, and a link to the order it came from.',
    whatCanYouDo: [
        'Search by invoice number.',
        'Filter by "Status" (Draft, Sent, Paid, Overdue, Void).',
        'Narrow the date range with "From"/"To" on when the invoice was created.',
        'Open any row to see its full detail, payments and PDF.',
        'Press "Export CSV" for a summary of exactly the invoices on screen, or "Export PDFs (zip)" for an archive of their PDF files — the archive lists any invoice whose PDF is not ready yet.',
    ],
    rules: [
        "An Agent only sees the invoices of the orders they own — everyone else (Owner, Manager, Viewer) sees the whole workspace's invoices (specs.md §7.4).",
        'Only Owner and Manager can create, send or void an invoice, or record a payment; Agent and Viewer are read-only here, even on their own orders.',
        'Exporting is allowed for every role, including Viewer: it is a read of the rows already on screen, delivered as a file. What limits it is the cap of 3 bulk operations running at once per person, the same for everyone.',
    ],
    howItsBuilt: {
        summary:
            'Same cursor-paginated `ListQuery` foundation as Orders (`App\\Support\\Lists\\InvoiceList`), scoped through the source order rather than a column of its own — an invoice has no owner column; an Agent\'s restriction is applied with a `whereHas(\'order\', …)` on the same `owner_user_id` that scopes their orders.',
    },
};

export default invoicesList;
