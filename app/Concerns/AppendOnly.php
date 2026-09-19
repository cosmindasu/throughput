<?php

namespace App\Concerns;

use RuntimeException;

/**
 * Registrele marcate append-only — `deal_stage_events` (ADR-004 pentru `stock_movements`,
 * §9.1 pentru evenimentele de etapă), `activity_log`.
 *
 * „Fără cale de UPDATE/DELETE în cod" (plan §7.6) e o regulă care se aplică singură:
 * un model care o încalcă aruncă la dezvoltare, nu trece de review din memorie. Corecția
 * unei înregistrări greșite se face cu o înregistrare compensatoare, nu prin rescriere —
 * altfel istoricul nu mai e istoric.
 *
 * `report_runs` NU mai e pe listă (scos la review-ul Fazei 4, lotul K) — specs.md §19.1
 * îl marca „Append-only: Da", dar §16.2 pct. 2-3/5 cere explicit un flux mutabil pe rând
 * (`queued` → `running` → `success`/`failed`). Contradicție de specificație, nu eroare de
 * implementare — vezi docblock-ul `App\Models\ReportRun` pentru argumentarea completă.
 */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(function ($model): void {
            throw new RuntimeException($model::class.' e append-only: scrie o înregistrare compensatoare, nu un UPDATE.');
        });

        static::deleting(function ($model): void {
            throw new RuntimeException($model::class.' e append-only: rândurile nu se șterg (retenția e în §17.2).');
        });
    }
}
