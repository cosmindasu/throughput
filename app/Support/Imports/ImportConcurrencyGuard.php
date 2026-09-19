<?php

namespace App\Support\Imports;

use App\Models\Import;

/**
 * §22.5 — „Import CSV (per tenant): 1 import activ concurent", verificat server-side
 * (`config('throughput.limits.import_concurrent_per_tenant')`, NU o constantă nouă — cheia
 * există deja în `config/throughput.php`).
 *
 * Decizie de aplicare: pragul se verifică O SINGURĂ DATĂ, la UPLOAD (Pasul 1) —
 * `CreateImportAction`. Odată ce un import există, Pașii 2-4 (mapare/dry-run/commit) sunt
 * continuarea ACELUIAȘI import, nu un import nou concurent — re-verificarea la fiecare pas
 * ar refuza propriul import aflat în curs. „Activ" = orice `imports.status` care NU e
 * terminal (`completed`/`completed_with_errors`/`failed`) — un import lăsat la „uploaded"
 * fără mapare blochează la fel un import nou, exact ca unul aflat în `validating`: simplu de
 * raționat, dar cu un cost cunoscut (semnalat în raportul lotului) — un import abandonat
 * blochează tenantul pe termen nelimitat, fără mecanism de „abandonare" în acest lot.
 */
final class ImportConcurrencyGuard
{
    private const ACTIVE_STATUSES = [
        Import::STATUS_UPLOADED,
        Import::STATUS_MAPPED,
        Import::STATUS_VALIDATING,
        Import::STATUS_VALIDATED,
        Import::STATUS_IMPORTING,
    ];

    public static function hasReachedLimit(): bool
    {
        $limit = max(1, (int) config('throughput.limits.import_concurrent_per_tenant'));

        return Import::query()->whereIn('status', self::ACTIVE_STATUSES)->count() >= $limit;
    }
}
