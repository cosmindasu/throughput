<?php

namespace App\Actions\Imports;

use App\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * Anulare manuală (P1, review general — un import abandonat blochează tenantul la
 * nesfârșit, §22.5). Calea PRINCIPALĂ de ieșire dintr-un import blocat — plasa de sistem
 * (`FailStuckImportsJob`) rămâne doar de rezervă, cu praguri mult mai largi.
 *
 * Mapează pe `STATUS_FAILED` — enum-ul `imports.status` (migrația deja existentă, nemodificată
 * de acest lot) n-are o valoare `cancelled` separată; funcțional, un import anulat manual e
 * identic cu unul eșuat (raportul final arată același mesaj generic).
 *
 * `UPDATE` atomic condiționat pe „nu e deja terminal" — simetric cu
 * `RunDryRunValidationAction`/`CommitImportAction`: un dublu-clic pe „Cancel" nu are voie să
 * încerce de două ori aceeași tranziție.
 */
final class CancelImportAction
{
    private const TERMINAL_STATUSES = [
        Import::STATUS_COMPLETED,
        Import::STATUS_COMPLETED_WITH_ERRORS,
        Import::STATUS_FAILED,
    ];

    public function execute(Import $import): void
    {
        $updated = Import::query()
            ->whereKey($import->getKey())
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->update(['status' => Import::STATUS_FAILED, 'completed_at' => now()]);

        if ($updated === 0) {
            throw ValidationException::withMessages([
                'status' => 'This import has already finished and cannot be cancelled.',
            ]);
        }

        // Lanțul de joburi auto-continue (`RunDryRunValidationJob`/`CommitImportJob`) se
        // oprește SINGUR la următoarea invocare: fiecare își verifică statusul la începutul
        // lui `handle()` și `return`-ează liniștit dacă nu mai e cel așteptat — nimic de
        // dispecerizat sau anulat explicit aici.
    }
}
