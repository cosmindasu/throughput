<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Carbon;

/**
 * Distribuție istorică pe 24 de luni, cu variație sezonieră plauzibilă (specs.md §21.1/§21.2)
 * — cerința explicită e "nu totul creat azi". Ponderile favorizează Q4/Q1 (cicluri bugetare
 * B2B tipice: reînnoiri de contract, comenzi de sfârșit de an fiscal) și scad ușor vara,
 * fără să elimine complet nicio lună din interval.
 */
final class DemoClock
{
    /** Index 0 = ianuarie … 11 = decembrie. */
    private const MONTH_WEIGHTS = [
        1.3, 1.2, 1.1, 1.0, 0.9, 0.8,
        0.7, 0.8, 1.0, 1.1, 1.3, 1.4,
    ];

    /** O dată aleasă uniform pe zi în interval, apoi acceptată/respinsă pe ponderea lunii ei. */
    public static function historicalDate(int $monthsBack = 24): Carbon
    {
        $now = Carbon::now();
        $start = $now->copy()->subMonths($monthsBack)->startOfDay();
        $totalDays = max(1, (int) $start->diffInDays($now));

        do {
            $candidate = $start->copy()->addDays(random_int(0, $totalDays));
        } while (! self::acceptForSeason($candidate));

        return $candidate->setTime(self::businessHour(), random_int(0, 59), random_int(0, 59));
    }

    private static function acceptForSeason(Carbon $date): bool
    {
        $weight = self::MONTH_WEIGHTS[$date->month - 1];

        // Pragul de 1.5 (peste orice pondere din tabel) garantează o acceptare în câteva
        // încercări în medie, niciodată o buclă nemărginită.
        return (random_int(0, 150) / 100) <= $weight;
    }

    private static function businessHour(): int
    {
        // Concentrat în orele de birou (8-18), cu o coadă subțire în afara lor.
        return random_int(0, 99) < 85 ? random_int(8, 18) : random_int(0, 23);
    }

    /** O dată ulterioară lui `$after`, la câteva ore/zile distanță — pentru succesiuni cauzale. */
    public static function shortlyAfter(Carbon $after, int $minHours, int $maxHours): Carbon
    {
        return $after->copy()->addHours(random_int($minHours, $maxHours))->addMinutes(random_int(0, 59));
    }
}
