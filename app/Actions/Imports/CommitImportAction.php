<?php

namespace App\Actions\Imports;

use App\Jobs\Imports\CommitImportJob;
use App\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * Pasul 4, declanșare (§14.1 pct. 4). Doar din `validated` — tranziția la `importing` se
 * face AICI, printr-un `UPDATE` atomic condiționat pe starea curentă (același motiv ca
 * `RunDryRunValidationAction`: un dublu-clic pe „Import valid rows" nu pornește două lanțuri
 * `CommitImportJob` în paralel pe ACELAȘI import).
 */
final class CommitImportAction
{
    public function execute(Import $import): void
    {
        $updated = Import::query()
            ->whereKey($import->getKey())
            ->where('status', Import::STATUS_VALIDATED)
            ->update(['status' => Import::STATUS_IMPORTING]);

        if ($updated === 0) {
            throw ValidationException::withMessages([
                'status' => 'This import cannot be committed from its current status.',
            ]);
        }

        CommitImportJob::dispatch($import->tenant_id, $import->getKey())->onQueue('imports');
    }
}
