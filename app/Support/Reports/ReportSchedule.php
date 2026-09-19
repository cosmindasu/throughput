<?php

namespace App\Support\Reports;

use App\Models\ReportDefinition;
use Carbon\CarbonImmutable;

/**
 * Verifică dacă un `report_definitions` e scadent într-o oră dată (specs.md §16.2 pct. 1).
 *
 * DECIZIE DE FUS ORAR (semnalată explicit, cerută de task) — `schedule_time` se
 * interpretează ca oră **UTC**, nu oră locală a tenantului. Motive:
 *  1. `tenants` n-are coloană de fus orar (verificat — nicio migrație n-o adaugă), deci
 *     „ora locală a cui" n-ar avea de unde să citească un fus orar real.
 *  2. Restul aplicației e deja ancorat pe UTC pentru cron: reset-ul demo (§22.1, FR-DEMO-03)
 *     e „03:00 UTC" explicit în specs.md, iar `throughput.demo.reset_cron` din config
 *     rulează pe fusul serverului (UTC în container/producție).
 *  3. Adăugarea unui fus orar per tenant e o schimbare de schemă (o coloană nouă pe
 *     `tenants`) care depășește scopul acestui val — notat ca limitare cunoscută, nu ca
 *     bug ascuns.
 * Dacă produsul chiar are nevoie de fus orar per tenant, e o extensie de schemă pentru altă
 * fază, nu o presupunere de rezolvat tăcut aici.
 */
final class ReportSchedule
{
    public static function isDueAt(ReportDefinition $definition, CarbonImmutable $hourStart): bool
    {
        if ($definition->schedule_frequency === ReportDefinition::FREQUENCY_NONE || $definition->schedule_time === null) {
            return false;
        }

        if ((int) CarbonImmutable::parse($definition->schedule_time, 'UTC')->format('H') !== (int) $hourStart->format('H')) {
            return false;
        }

        return match ($definition->schedule_frequency) {
            ReportDefinition::FREQUENCY_DAILY => true,
            // `schedule_day` — zi ISO a săptămânii (1 = luni ... 7 = duminică), ca
            // `CarbonImmutable::isoWeekday()`. Ales ISO (nu 0-6 „Sunday-first") pentru că e
            // convenția din exemplul Gherkin al US-REP-01 („ziua Monday" = prima zi).
            ReportDefinition::FREQUENCY_WEEKLY => (int) $definition->schedule_day === $hourStart->isoWeekday(),
            ReportDefinition::FREQUENCY_MONTHLY => self::matchesMonthlyDay((int) $definition->schedule_day, $hourStart),
            default => false,
        };
    }

    /**
     * O zi de lună peste ultima zi a lunii curente (ex: 31 în februarie) cade pe ULTIMA zi
     * a lunii, nu se sare peste ea — altfel un raport lunar programat pe 31 nu s-ar genera
     * NICIODATĂ în lunile scurte, o lipsă tăcută, mai gravă decât o zi „aproximativă".
     */
    private static function matchesMonthlyDay(int $scheduleDay, CarbonImmutable $hourStart): bool
    {
        $effectiveDay = min($scheduleDay, $hourStart->daysInMonth);

        return $hourStart->day === $effectiveDay;
    }
}
