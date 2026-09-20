import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Settings/DataExport/Index` — specs.md §20.5 (FR-GDPR-01/02, BR-GDPR-01/02, US-GDPR-01),
 * §7.4 (permisiunile `data_exports.view`/`data_exports.create`), plan §11 (lotul G, Faza 5).
 *
 * Distinct de `exports` (statusul unui export de LISTĂ, §13.2): acolo se exportă rândurile
 * unei liste filtrate, aici întreg workspace-ul, ca răspuns la o cerere de portabilitate.
 *
 * Scris pe codul acestui lot: `DataExportController` (istoric de 50 de rânduri, ordonat cu
 * tiebreaker pe `id`), `DataExportRequestPolicy` (doar autorul descarcă), `DataExportSources`
 * (cele șapte entități, CSV doar unde toate coloanele sunt scalare), `WriteEntityExportAction`
 * (scriere în flux, `chunkById` la 500), `PlanDataExportJob`/`ExportTenantEntityJob`/
 * `FinalizeDataExportJob` (`Bus::batch()`, FĂRĂ `allowFailures()`), `DataExportReadyMail`,
 * `PruneExpiredDataExportsJob` (`EXPORT_RETENTION_DAYS` = 7).
 */
const dataExport: HelpTopic = {
    id: 'data-export',
    title: 'Export data',
    whatIsThis:
        "A complete, machine-readable copy of everything this workspace holds — accounts, contacts, deals, orders, invoices, payments and the activity log — packaged as a single archive you can hand to someone who asks what data you keep about them. It is built in the background, because a workspace this size cannot be assembled while a page is waiting.",
    whatCanYouDo: [
        'Press "Request export" to start one. It appears in the list immediately as "Queued", and you can leave the page — the work carries on without you.',
        'Watch a request move from "Queued" to "Preparing" to "Ready to download". The page refreshes itself, so there is nothing to reload.',
        'Press "Download ZIP" on a finished request to get the archive.',
        'Read the history: the 50 most recent requests, who made each one, when it finished, and how long its file stays available.',
    ],
    rules: [
        'Only an Owner can request an export. A Manager sees this history and can tell you exactly what was exported and when, but cannot start a new one — handing over a copy of an entire workspace is an owner-level decision.',
        'The download link belongs to the person who requested it. Another Owner cannot open someone else\'s archive; they request their own, which also leaves their name in the history.',
        'One export at a time per workspace. A second request while one is still running is refused, because an export touches every large table at once and a single background worker serves the whole application.',
        'A finished archive can be downloaded for 7 days. After that the file is deleted and the link stops working — but the request stays in this list, with its status unchanged, so there is a permanent record that the export was made and honoured.',
        'Requesting an export never changes, moves or deletes anything. Exporting and erasing are separate rights and separate actions: this screen only ever reads.',
        'The archive holds one JSON file per entity, with the relationships intact, plus a CSV of the same rows wherever the table is flat enough for a spreadsheet, plus a manifest describing what is inside. There is no PDF: a PDF is a picture of data, not data, and does not count as a portable format.',
        'The manifest also lists what is deliberately not in the archive — sign-in credentials, subscription and payment-method details held by the payment processor, carrier credentials, and files that are only renderings of rows already included.',
        'Deleted deals are in the export, each carrying the date it was deleted: the record still exists, so withholding it would be an incomplete answer. Contacts that were anonymised are not, because their identifying fields were already erased.',
        'Only this workspace is ever in the archive. Switching workspaces and exporting again gives you a different archive; there is no request that spans two.',
    ],
    howItsBuilt: {
        summary:
            "Requesting an export writes one row and queues one job — nothing else happens inside the web request, because the request holds a database transaction open for its whole life and a long one would tie up a connection the rest of the application needs. The background work is split into one job per entity, grouped as a batch, exactly the mechanism the bulk operations use: each job holds its own short transaction, streams its rows out in pages of 500 keyed on the primary key, and writes them to disk as it goes rather than building the whole file in memory — the same memory ceiling that caps PDF exports applies here, and the showcase workspace alone holds tens of thousands of orders. Unlike a bulk operation, a failed piece is not tolerated: an archive missing its invoices would be a wrong answer to a portability request rather than a partial one, so the first failure cancels the batch and nothing is delivered. A final job writes the manifest, zips the parts by path (never by loading them), then records the file and its expiry, and only afterwards sends the notification email — outside any transaction, since that is an external call. The archive contains this workspace and nothing else, and no line of the export code says so: isolation comes from the same two layers that scope every other query, and an extra filter here would have hidden a leak instead of preventing one. A daily job then clears the file at expiry and leaves the row.",
        adr: {
            id: 'ADR-013',
            title: 'External calls and long work belong in queues, never in a request',
            url: adrUrl('ADR-013', 'apeluri-externe-in-cozi'),
        },
    },
};

export default dataExport;
