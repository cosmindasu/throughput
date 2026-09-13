import type { HelpTopic } from '@/help/types';

/**
 * `Exports/Show` — specs.md §7.4 nota ³, §13.1/§13.2 (mecanismul de coadă), US-CRM-03. Distinct
 * de exportul GDPR de tenant din §20.5 (Settings → „Export data", Faza 5) — acesta e statusul
 * unui export de LISTĂ (CSV filtrat).
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Exports/Show.tsx` (etichetele de status, polling la
 * 2 s, „Download CSV"), `ListExport` (prag `EXPORT_SYNC_MAX_ROWS` = 5.000 inclusiv, plafonul
 * demo `BULK_MAX_ROWS_ABSOLUTE` = 60.000), `ExportableResources` (doar conturi și contacte),
 * `BulkOperationPolicy` (doar autorul), `ExportListJob` și `CsvExporter`. Nu există încă un job de
 * expirare a exporturilor; în demo, `demo:reset` (`migrate:fresh`) le șterge în fiecare noapte.
 */
const exportsTopic: HelpTopic = {
    id: 'exports',
    title: 'Export status',
    whatIsThis:
        "This page follows a CSV export too big to download on the spot — you land here automatically after \"Export CSV\" on a large Accounts or Contacts list. It's a status page, not a place you navigate to directly.",
    whatCanYouDo: [
        'Watch the status go from "Queued" to "Running" to "Ready to download" — the page updates itself every 2 seconds until the export finishes.',
        'Download the file with "Download CSV" once it\'s ready.',
        'If it says "Failed", go back to the list and start the export again.',
        'Come back later through the same link — in the public demo, until the nightly data reset.',
    ],
    rules: [
        "An export of up to 5,000 rows downloads straight from the list, no waiting screen. A larger one runs as a background job so the request doesn't hang — you land here to watch it finish instead.",
        'The file contains every row matching the filters and sort you had when you clicked "Export CSV" — all pages, not just the one on screen — including a default such as "My accounts".',
        "Only the person who started an export can open this page or download the file — not even an Owner can open a teammate's export.",
        "Every role that can view the list can export it, including Viewer — exporting what's already visible to you is a read, not a write.",
        'In the public demo, an export of more than 60,000 rows is refused before it starts.',
    ],
    howItsBuilt: {
        summary:
            'The 5,000-row threshold decides synchronous vs. queued, not the resource type — under it, the CSV is built inside the request and returned directly; over it, the filter and sort are saved on a `bulk_operations` row and re-run by a queued job. The job marks the export "Running" in its own short transaction before doing the work, otherwise the status would jump straight from "Queued" to the end. Both paths write the file through the same exporter, which prefixes any cell starting with =, +, -, @, a tab or a carriage return with an apostrophe, so a spreadsheet never runs it as a formula. No dedicated ADR — see specs.md §13.2 for the mechanism.',
    },
};

export default exportsTopic;
