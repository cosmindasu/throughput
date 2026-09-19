<?php

namespace App\Actions\Imports;

use App\Jobs\Imports\RunDryRunValidationJob;
use App\Models\Import;
use App\Models\ImportRow;
use Illuminate\Validation\ValidationException;

/**
 * Pasul 3, declanșare (§14.1 pct. 3). Doar din `mapped` — tranziția e un `UPDATE` atomic
 * CONDIȚIONAT pe starea curentă (`whereKey()->where('status', mapped)`), nu un citește-apoi-
 * scrie: un dublu-clic pe „Run dry-run validation" nu are voie să pornească DOUĂ lanțuri de
 * joburi auto-continue în paralel pe ACELAȘI import (`RunDryRunValidationJob` s-ar
 * suprascrie reciproc contoarele).
 */
final class RunDryRunValidationAction
{
    public function execute(Import $import): void
    {
        // Verificarea de stare ÎNTÂI, ștergerea rândurilor DUPĂ — altfel un apel refuzat
        // (status ≠ `mapped`, ex. un al doilea POST pe un import deja `validated`) ar șterge
        // totuși rândurile unei probe uscate anterioare, ÎNAINTE de a afla că tranziția a
        // eșuat. Ordinea inversă (șterge, apoi verifică) distrugea rezultate valide pentru
        // niciun beneficiu.
        $updated = Import::query()
            ->whereKey($import->getKey())
            ->where('status', Import::STATUS_MAPPED)
            ->update([
                // `status` trebuie să tranziționeze AICI, sincron, nu abia în jobul din coadă
                // (găsit de suita E2E, Faza 4). Două motive, nu unul:
                //  1. Fără el, cererea se întoarce cu importul tot pe `mapped`, deci ecranul
                //     nu se consideră „în lucru" și nu pornește polling-ul — pagina rămâne
                //     blocată pe „Step 2 of 4" la nesfârșit, deși proba uscată se termină
                //     corect pe server în câteva secunde.
                //  2. Garda `where('status', MAPPED)` de mai sus nu serializa NIMIC cât timp
                //     `status` rămânea `mapped`: două POST-uri concurente treceau amândouă,
                //     iar docblock-ul de deasupra promitea o atomicitate pe care nu o avea.
                // `CommitImportAction` făcea deja corect (`mapped` → `importing`).
                'status' => Import::STATUS_VALIDATING,
                'total_rows' => null,
                'valid_rows' => null,
                'error_rows' => null,
                'completed_at' => null,
            ]);

        if ($updated === 0) {
            throw ValidationException::withMessages([
                'status' => 'This import cannot start validation from its current status.',
            ]);
        }

        // Rulare repetată (mapare corectată după o probă uscată anterioară pe ACEEAȘI
        // resursă): rândurile vechi nu au ce căuta amestecate cu cele noi. Sigur AICI: tocmai
        // am confirmat, atomic, că importul chiar a tranziționat din `mapped`.
        ImportRow::query()->where('import_id', $import->getKey())->delete();

        RunDryRunValidationJob::dispatch($import->tenant_id, $import->getKey())->onQueue('imports');
    }
}
