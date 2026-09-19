<?php

namespace App\Actions\Imports;

use App\Models\Import;
use App\Models\ImportRow;
use Illuminate\Validation\ValidationException;

/**
 * Pasul 2 — Mapare (§14.1 pct. 2). Salvează `column_mapping` și duce `status` la `mapped`.
 * Remaparea unui import care trecuse deja printr-o probă uscată (`validated`/`failed`)
 * șterge rândurile vechi — ar fi validate pe o mapare care nu mai e cea curentă.
 *
 * Refuzată cât timp un job de fundal rulează activ (`validating`/`importing`): joburile
 * auto-continue (`RunDryRunValidationJob`/`CommitImportJob`) țin deja o COPIE a
 * `column_mapping` citită la începutul lanțului; o remapare la mijloc ar corupe tăcut
 * rândurile scrise DEJA cu maparea veche, fără ca vreun job să afle.
 *
 * @param  array<string, string|null>  $mapping
 */
final class UpdateImportMappingAction
{
    private const BLOCKED_STATUSES = [Import::STATUS_VALIDATING, Import::STATUS_IMPORTING];

    public function execute(Import $import, array $mapping): void
    {
        if (in_array($import->status, self::BLOCKED_STATUSES, true)) {
            throw ValidationException::withMessages([
                'mapping' => 'This import is currently being processed in the background — wait for it to finish before changing the mapping.',
            ]);
        }

        if ($import->status !== Import::STATUS_UPLOADED) {
            ImportRow::query()->where('import_id', $import->getKey())->delete();
        }

        $import->update([
            'column_mapping' => $mapping,
            'status' => Import::STATUS_MAPPED,
            'total_rows' => null,
            'valid_rows' => null,
            'error_rows' => null,
            'completed_at' => null,
        ]);
    }
}
