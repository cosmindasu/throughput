<?php

namespace App\Concerns;

use RuntimeException;

/**
 * Registrele din §19.1 marcate append-only — `deal_stage_events` (ADR-004 pentru
 * `stock_movements`, §9.1 pentru evenimentele de etapă), `activity_log`, `report_runs`.
 *
 * „Fără cale de UPDATE/DELETE în cod" (plan §7.6) e o regulă care se aplică singură:
 * un model care o încalcă aruncă la dezvoltare, nu trece de review din memorie. Corecția
 * unei înregistrări greșite se face cu o înregistrare compensatoare, nu prin rescriere —
 * altfel istoricul nu mai e istoric.
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
