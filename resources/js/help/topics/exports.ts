import type { HelpTopic } from '@/help/types';

/**
 * `Exports/Show` — specs.md §7.4 nota ³, §13.1/§13.2 (mecanismul de coadă), US-CRM-03. Distinct
 * de exportul GDPR de tenant din §20.5 (Settings → „Export data", Faza 5) — acesta e statusul
 * unui export de LISTĂ (CSV sau, pe Orders, și PDF).
 *
 * Reconciliat cu codul la 2026-09-14 (lotul E, valul „bulk"): `Pages/Exports/Show.tsx`
 * (etichetele de status, polling la 2 s, „Download CSV"/„Download PDF" după format, data de
 * expirare), `ListExport` (prag `EXPORT_SYNC_MAX_ROWS` = 5.000 inclusiv pentru CSV; PDF-ul
 * NU are cale sincronă — decizie a proprietarului, DomPDF — și are propriul plafon,
 * `EXPORT_PDF_MAX_ROWS` = 500, măsurat direct: peste el timpul și memoria de randare cresc
 * mai repede decât liniar; plafonul demo `BULK_MAX_ROWS_ABSOLUTE` = 60.000), `ExportableResources`
 * (Accounts, Contacts, Orders), `BulkOperationPolicy` (doar autorul), `ExportListJob`,
 * `CsvExporter` și `PdfExporter`. `PruneExpiredExportsJob` (plan §7.2) rulează zilnic, cu
 * aceeași retenție ca FR-GDPR-01 (`export_retention_days`, implicit 7 zile): golește
 * `result_path` și șterge fișierul, rândul rămâne. În demo, `demo:reset` mai golește și el
 * `exports/` integral, la fiecare reset zilnic — `migrate:fresh` oricum șterge toate rândurile
 * `bulk_operations`, deci fișierele rămase ar fi orfane cu certitudine.
 */
const exportsTopic: HelpTopic = {
    id: 'exports',
    title: 'Export status',
    whatIsThis:
        "This page follows a CSV or PDF export too big — or, for PDF, any size at all — to download on the spot. You land here automatically after \"Export CSV\"/\"Export PDF\" on a large Accounts, Contacts or Orders list. It's a status page, not a place you navigate to directly.",
    whatCanYouDo: [
        'Watch the status go from "Queued" to "Running" to "Ready to download" — the page updates itself every 2 seconds until the export finishes.',
        'Download the file with "Download CSV" or "Download PDF" once it\'s ready.',
        'If it says "Failed", go back to the list and start the export again.',
        'Come back later through the same link — in the public demo, until the nightly data reset.',
    ],
    rules: [
        "A CSV export of up to 5,000 rows downloads straight from the list, no waiting screen. A larger one, or any PDF export, runs as a background job so the request doesn't hang — you land here to watch it finish instead.",
        'A PDF export is capped at 500 rows and always runs in the background, even for a single row — rendering a PDF costs real CPU time and memory, which never belongs inside the request that served the page. For anything bigger, use CSV.',
        'The file contains every row matching the filters and sort you had when you clicked "Export CSV"/"Export PDF" — all pages, not just the one on screen — including a default such as "My accounts".',
        "Only the person who started an export can open this page or download the file — not even an Owner can open a teammate's export.",
        "Every role that can view the list can export it, including Viewer — exporting what's already visible to you is a read, not a write.",
        'In the public demo, an export of more than 60,000 rows is refused before it starts.',
        'A completed export stays downloadable for 7 days. After that the link expires — this page shows when it expired instead of the download button — though the row itself stays in history.',
    ],
    howItsBuilt: {
        summary:
            'The 5,000-row threshold decides synchronous vs. queued for CSV only — under it, the CSV is built inside the request and returned directly; over it, or for any PDF, the filter, sort and chosen format are saved on a `bulk_operations` row and re-run by a queued job. The job marks the export "Running" in its own short transaction before doing the work, otherwise the status would jump straight from "Queued" to the end. CSV rows are streamed through an exporter that prefixes any cell starting with =, +, -, @, a tab or a carriage return with an apostrophe, so a spreadsheet never runs it as a formula; PDF rows are rendered through a fixed Blade table (same columns as the CSV, DomPDF driver chosen explicitly, no remote resources) into an A4 landscape file. A daily system job then prunes completed exports past their 7-day retention: it deletes the file and clears the stored path, keeping the row. No dedicated ADR — see specs.md §13.2 and §20.5 (FR-GDPR-01, same retention) for the mechanism.',
    },
};

export default exportsTopic;
