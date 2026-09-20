import type { HelpTopic } from '@/help/types';

/**
 * `Imports/Create` — Pasul 1 din §14.1 (Upload), FR-IMP-02 (plafoanele), FR-IMP-03 (Deals și
 * Orders rămân în afara MVP-ului), §22.5 (1 import activ per tenant).
 *
 * Reconciliat cu codul la 2026-09-19 (lotul N, Faza 4): `Pages/Imports/Create.tsx`
 * („What are you importing?", „Download a CSV template for this resource", „File (CSV or
 * XLSX)", butonul „Upload"), `StoreImportRequest` (`mimes:csv,txt,xlsx` — `txt` e o problemă
 * de DETECTARE, nu o a treia formă acceptată; plafonul de dimensiune din
 * `import_max_file_mb`, cel de rânduri din `import_max_rows`, verificat separat, DUPĂ
 * `mimes`/`max`), `ImportFileRowCounter` (numărare ieftină: linii pentru CSV, dimensiunea
 * foii pentru XLSX, cu un `IReadFilter` care respinge toate celulele), `CreateImportAction`
 * (`ImportConcurrencyGuard` ÎNAINTE de stocarea fișierului), `ImportableResources` (Accounts,
 * Contacts, Products, Products/Variants), `ImportTemplateBuilder`.
 *
 * Cifrele din text sunt cele din `config/throughput.php`: 20 MB, 50.000 de rânduri. Pagina
 * le afișează oricum din props-urile SERVER-SIDE (`limits`), nu dintr-o constantă de front —
 * dacă valorile se schimbă în config, textul de aici trebuie actualizat odată cu ele.
 */
const importUpload: HelpTopic = {
    id: 'import-upload',
    title: 'New import',
    whatIsThis:
        "Step 1 of 4. You say what kind of records the file holds and hand the file over. Nothing is read into the workspace yet — the next screen shows you the columns it found and lets you map them.",
    whatCanYouDo: [
        'Pick the target under "What are you importing?": Accounts, Contacts, Products, or Products/Variants.',
        'Grab a head start with "Download a CSV template for this resource" — its header row already carries the exact field names this import expects.',
        'Choose your file under "File (CSV or XLSX)" and press "Upload". You land straight on the mapping step.',
    ],
    rules: [
        'A file can be at most 20 MB and at most 50,000 data rows. The two are checked separately, and neither implies the other — a 20 MB file of short rows can be far past 50,000 rows, and a 5,000-row file of long text fields can be past 20 MB. Go over either one and the upload is refused with the real number in the message, so you know which limit you hit; split the file and import the parts one at a time.',
        'The first row is the header and is never imported — the row limit counts the data rows only.',
        '.csv, .txt and .xlsx are accepted. The .txt is not a third format: Excel and Google Sheets export CSVs that some platforms label as plain text, and rejecting those would be rejecting a valid CSV over a label. Anything that is not .xlsx is read as CSV.',
        'One import can be active per workspace. If another one is still open — even an old one left sitting at "Uploaded" — this upload is refused here, before the file is stored, rather than queued invisibly behind it. Finish that one, wait for it, or open it and press "Cancel import" to clear the way.',
        'Only Accounts, Contacts, Products and Products/Variants can be imported. Deals and Orders are deliberately out of scope: they hang off too many other records to import safely from a flat file (FR-IMP-03).',
        'Only Owner and Manager get here at all — the permission matrix gives Agent and Viewer no import rights (specs.md §7.4).',
    ],
    howItsBuilt: {
        summary:
            "The request does almost nothing: it validates, writes one `imports` row and stores the file. It never parses the file. The row count that enforces the 50,000 limit is measured cheaply — a line count for CSV, and for XLSX the sheet's declared dimensions read through a filter that rejects every single cell, so the spreadsheet is never materialised. The column headers are not stored on the record either; they are re-read from the saved file on demand at the mapping step, which keeps the file the single source of truth and means a re-map always sees the current header rather than a stale copy. Everything heavier than that — validating rows, writing entities — belongs to queued jobs, because the tenant isolation mechanism holds a database transaction open for the whole web request.",
    },
};

export default importUpload;
