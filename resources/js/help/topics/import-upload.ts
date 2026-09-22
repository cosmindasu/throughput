import type { HelpTopicDefinition } from '@/help/types';

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
const importUpload: HelpTopicDefinition = {
    id: 'import-upload',
};

export default importUpload;
