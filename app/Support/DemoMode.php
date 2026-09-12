<?php

namespace App\Support;

/**
 * `DEMO_MODE` și guardrail-urile lui (specs.md §22.2), citite dintr-un singur loc.
 *
 * Producția ESTE demo-ul public, cu scriere reală. Codul care decide dacă o acțiune e
 * permisă întreabă aici, nu citește config-ul pe cont propriu — altfel butonul ascuns din
 * props și refuzul de pe server ajung să folosească reguli diferite.
 */
final class DemoMode
{
    public static function enabled(): bool
    {
        return (bool) config('throughput.demo.mode');
    }

    /**
     * Plafonul absolut de rânduri al unei operații în masă (§22.2, rândul doi): 60.000
     * implicit, deliberat de 3× peste ținta KPI. `null` în afara `DEMO_MODE`.
     */
    public static function bulkRowCap(): ?int
    {
        return self::enabled() ? (int) config('throughput.limits.bulk_max_rows') : null;
    }

    public static function exceedsBulkRowCap(int $rows): bool
    {
        $cap = self::bulkRowCap();

        return $cap !== null && $rows > $cap;
    }
}
