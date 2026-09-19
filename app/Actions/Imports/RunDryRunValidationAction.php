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
