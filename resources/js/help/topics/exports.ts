import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Exports/Show` — specs.md §7.4 nota ³, §13.1/§13.2 (mecanismul de coadă), US-CRM-03. Distinct
 * de exportul GDPR de tenant din §20.5 (Settings → „Export data", Faza 5) — acesta e statusul
 * unui export de LISTĂ (CSV sau, pe Orders, și PDF).
 *
 * Reconciliat cu codul la 2026-09-14 (lotul E, valul „bulk"): `Pages/Exports/Show.tsx`
 * (etichetele de status, polling la 2 s, „Download CSV"/„Download PDF" după format, data de
 * expirare), `ListExport` (prag `EXPORT_SYNC_MAX_ROWS` = 5.000 inclusiv pentru CSV; PDF-ul
 * NU are cale sincronă — decizie a proprietarului, DomPDF — și are propriul plafon,
 * `EXPORT_PDF_MAX_ROWS` = 250 (coborât de la 500 în Faza 4, după remăsurarea pe container —
 * vezi comentariul cheii din `config/throughput.php`), măsurat direct: peste el timpul și
 * memoria de randare cresc
 * mai repede decât liniar; plafonul demo `BULK_MAX_ROWS_ABSOLUTE` = 60.000), `ExportableResources`
 * (Accounts, Contacts, Orders), `BulkOperationPolicy` (doar autorul), `ExportListJob`,
 * `CsvExporter` și `PdfExporter`. `PruneExpiredExportsJob` (plan §7.2) rulează zilnic, cu
 * aceeași retenție ca FR-GDPR-01 (`export_retention_days`, implicit 7 zile): golește
 * `result_path` și șterge fișierul, rândul rămâne. În demo, `demo:reset` mai golește și el
 * `exports/` integral, la fiecare reset zilnic — `migrate:fresh` oricum șterge toate rândurile
 * `bulk_operations`, deci fișierele rămase ar fi orfane cu certitudine.
 */
const exportsTopic: HelpTopicDefinition = {
    id: 'exports',
};

export default exportsTopic;
