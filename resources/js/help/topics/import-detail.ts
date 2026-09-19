import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Imports/Show` — cei patru pași din §14.1 ca UN SINGUR ecran, cu stări succesive pe
 * `imports.status`; US-IMP-01 (proba uscată + raportul reimportabil), US-IMP-02 (auto-mapare
 * cu indicator de încredere), FR-IMP-01 (duplicate: email la contacte, SKU la variante),
 * BR-IMP-01 (`raw_data` păstrat).
 *
 * Reconciliat cu codul la 2026-09-19 (lotul N, Faza 4): `Pages/Imports/Show.tsx` (etichetele
 * „Save mapping and continue", „Run dry-run validation", „Import N valid rows", „Download
 * the N failed/skipped rows as CSV", „Rows that need attention", „Start another import",
 * „Back to imports"; plafonul de 500 de rânduri invalide afișate inline vine din
 * `ImportController::INVALID_ROWS_PAGE_LIMIT`), `ColumnMappingSuggester` (potrivire exactă pe
 * alias = „High confidence", altfel `similar_text()` cu praguri 85/65/40; coloana `error` e
 * EXCLUSĂ explicit din potrivire), `ImportDryRunChunkProcessor` (format + duplicat față de
 * bază), `ImportDryRunFinalizer` (duplicate ÎN FIȘIER, o trecere finală — prima apariție
 * rămâne validă), `CommitImportJob` (ingestie parțială, savepoint per rând),
 * `UpdateImportMappingAction` (remaparea șterge rândurile unei probe uscate anterioare și e
 * blocată cât rulează un job), `ImportErrorReportBuilder` (antete ORIGINALE + coloana
 * `error`), resursele din `app/Support/Imports/Resources/`.
 *
 * DEFECT SEMNALAT, NEREPARAT (nu e fișierul acestui lot): `Pages/Imports/Show.tsx` cheamă
 * `usePoll(2000, {}, { autoStart: isRunning })` cu DOAR ramura `stop()` — exact tiparul
 * interzis de `.ai/rules/frontend.md` („usePoll are nevoie de start(), nu doar de
 * autoStart") și exact defectul reparat în `Pages/Reports/Show.tsx`. Pe fluxul real
 * (utilizatorul apasă „Run dry-run validation" pe o pagină DEJA deschisă, iar serverul
 * redirecționează spre ACEEAȘI rută, deci componenta nu se remontează), polling-ul nu
 * pornește niciodată, deși pagina scrie „This page updates automatically". Fraza din „What
 * can you do" descrie comportamentul PROIECTAT; devine adevărată când se adaugă ramura
 * `start()`.
 */
const importDetail: HelpTopic = {
    id: 'import-detail',
    title: 'Import — the four steps',
    whatIsThis:
        "One screen for the whole import: map the columns, dry-run the file, commit the rows that passed, read the report. The heading tells you which of the four steps you're on, and the page carries you forward as the background work finishes.",
    whatCanYouDo: [
        'Step 2 — check the mapping we guessed, change anything under "Maps to" (including "Don\'t import this column"), then press "Save mapping and continue". Each guess carries a "High confidence", "Medium confidence", "Low confidence" or "No match" badge, so you know which ones to look at.',
        'Step 3 — press "Run dry-run validation". Every row in the file is checked before a single record is written, and the progress line counts rows as they go by; the page refreshes itself every 2 seconds while it runs.',
        'Step 4 — press "Import N valid rows". The button says the actual number, so you commit knowing exactly how much is going in.',
        'Take the failures away with you at either step: "Download the N failed rows as CSV" after the dry run, "Download the N skipped rows as CSV — correct them and re-import" after the commit.',
        'Read the first 500 bad rows inline under "Rows that need attention" — each with its row number from the original file and the exact field and message, like "price: The Price must be a number".',
        'Move on with "Start another import", or step back to the list with "Back to imports".',
    ],
    rules: [
        'The dry run writes nothing into your workspace. It only validates and records what it found, which is why "Import N valid rows" appears afterwards and not before.',
        'A commit is partial by design, never all-or-nothing: valid rows go in, invalid ones are skipped and reported. Even a row that fails while being written — a SKU someone else created a second earlier, say — is rolled back on its own and marked invalid; the rows already committed alongside it stay committed.',
        'Duplicates are caught in two directions: against records already in your workspace, and against earlier rows in the same file. Within a file, the first occurrence is the one that imports and every later one is rejected — so a file that repeats a SKU three times creates one variant, not three, and tells you about the other two.',
        'The duplicate key is the email address for contacts and the SKU for variants (FR-IMP-01). Accounts use the domain when the row has one — normalised first, so "https://www.Acme.com/" and "acme.com" count as the same company — and otherwise the company name, compared without case. Products use their name the same way. SKUs are the one exception: they are compared exactly as written, case included, because that is how the uniqueness rule in the database works.',
        "A contact row with no email can't be checked for duplicates at all, so it is imported as it stands — there is no key to match it on, and guessing one would be worse than importing it.",
        "The error report is re-importable exactly as downloaded: the original columns, in their original order, plus one \"error\" column. That column is deliberately ignored when you upload the file again, and the rows that already imported aren't in it — so correcting and re-uploading never duplicates the ones that already went in.",
        'Saving the mapping again after a dry run throws that dry run away: its rows were judged against a mapping that no longer applies, so they are cleared and you start the validation over. While validation or the import is actually running, the mapping is locked and a change is refused rather than applied half-way through.',
        'The raw content of every failed row is kept, even after the import finishes — that is what the downloadable report is built from, and it is why nothing here is ever cleaned up automatically (BR-IMP-01).',
        'Only Owner and Manager reach any of this. Agent and Viewer have no import rights at all (specs.md §7.4).',
    ],
    howItsBuilt: {
        summary:
            'Both the dry run and the commit are chains of small jobs rather than one long one: each invocation handles 500 rows and then re-queues itself. That is not a style choice — the queue supervisor kills a job at 60 seconds and does not retry it, so a single job reading and validating a 50,000-row file would be killed halfway and leave the import stuck at "Validating…" forever. Each chunk commits its own counters in its own short transaction, which is why the progress line really moves between refreshes instead of jumping from 0 to done. In-file duplicates can\'t be caught chunk-by-chunk (a chunk cannot see what earlier chunks wrote without an extra query, and its own in-memory "already seen" set would not survive being serialised between jobs), so they are a single final pass over the rows already written to the database — order-independent by construction. The commit needs no cursor to resume: each run asks for "the next 500 rows still marked valid", and a processed row leaves that set by changing status. Every single row write is wrapped in its own savepoint, because in PostgreSQL one failed insert aborts the whole surrounding transaction — without it, a single duplicate SKU would take down the rest of the batch and the error-recording write with it, turning "partial import" back into all-or-nothing. The error report re-reads the header order from the stored file rather than from the saved mapping, because the database does not preserve the key order of a JSON object.',
        adr: {
            id: 'ADR-013',
            title: 'External calls and heavy work run in a queue, never inside the web request',
            url: adrUrl('ADR-013', 'apeluri-externe-in-cozi'),
        },
    },
};

export default importDetail;
