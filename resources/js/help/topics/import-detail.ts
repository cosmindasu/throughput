import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

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
 */
const importDetail: HelpTopicDefinition = {
    id: 'import-detail',
    adr: {
        id: 'ADR-013',
        title: 'External calls leave the HTTP request and move to queues',
        url: adrUrl('ADR-013', 'apeluri-externe-in-cozi'),
    },
};

export default importDetail;
