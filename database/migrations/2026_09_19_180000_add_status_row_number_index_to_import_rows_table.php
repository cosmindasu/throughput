<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 4, review general — singurul index existent pe `import_rows`, `(tenant_id,
 * import_id)`, nu acoperă filtrul pe `status` nici sortarea/`MAX()` pe `row_number`, cerute
 * de lanțul de joburi auto-continue. DOUĂ indexuri noi, nu unul — măsurat cu `EXPLAIN
 * ANALYZE` (50.000 de rânduri semănate, `throughput_app` sub RLS; cifrele exacte în
 * raportul lotului), fiindcă cele două interogări au forme diferite:
 *
 *  - `CommitImportJob`: `WHERE import_id = ? AND status = 'valid' ORDER BY row_number LIMIT
 *    import_chunk_size` — are nevoie de `status` ÎNAINTEA lui `row_number` în index, ca
 *    egalitatea pe status să restrângă intervalul înainte de sortare.
 *  - `RunDryRunValidationJob`: `WHERE import_id = ? MAX(row_number)`, FĂRĂ predicat pe
 *    `status` — un index care are `status` între `import_id` și `row_number` NU poate servi
 *    `MAX(row_number)` printr-un simplu „Index Scan Backward, Limit 1": rândurile sunt
 *    sortate pe `row_number` DOAR în interiorul fiecărei valori de `status`, nu global, deci
 *    cel mai mare `row_number` dintr-un status „mai mic" alfabetic ar putea fi omis. Verificat
 *    empiric: cu un singur index compus (status înainte de row_number), interogarea de mai
 *    sus rămânea `Seq Scan` chiar și fără RLS (deci nu era o limitare de RLS) — un al doilea
 *    index, FĂRĂ `status`, era singura schimbare care producea `Index Only Scan Backward`.
 *
 * `ImportErrorReportBuilder`/`ImportDryRunFinalizer` filtrează la fel pe `import_id` +
 * `status`, deși folosesc deja `chunkById()` — foloseau tot indexul lipsă ca să găsească
 * rândurile cerute, acum servite de primul index de mai jos.
 *
 * Indexul VECHI, `(tenant_id, import_id)`, devine un prefix strict al celui de-al doilea —
 * păstrat ar fi doar întreținere de scriere suplimentară fără niciun câștig de citire, deci
 * se înlocuiește, nu se adaugă alături.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'import_id']);
            $table->index(['tenant_id', 'import_id', 'status', 'row_number']);
            $table->index(['tenant_id', 'import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'import_id', 'row_number']);
            $table->dropIndex(['tenant_id', 'import_id', 'status', 'row_number']);
            $table->index(['tenant_id', 'import_id']);
        });
    }
};
