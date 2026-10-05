<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Carbon;

/**
 * Distribuție istorică pe 24 de luni, cu variație sezonieră plauzibilă (specs.md §21.1/§21.2)
 * — cerința explicită e "nu totul creat azi". Ponderile favorizează Q4/Q1 (cicluri bugetare
 * B2B tipice: reînnoiri de contract, comenzi de sfârșit de an fiscal) și scad ușor vara,
 * fără să elimine complet nicio lună din interval.
 *
 * ## Marginea dinspre prezent
 *
 * Un seed descrie ISTORIE, deci niciun moment produs aici nu trece de `latestAcceptable()` —
 * acum minus o oră. Regula are două părți, ambele plătite prin bug-uri vizibile pe dashboard:
 *
 *  1. **Nimic în viitor.** `shortlyAfter()` aduna ore peste o dată istorică fără plafon: o
 *     afacere creată acum trei zile, plus două salturi de până la 21 de zile și unul de câștig
 *     de până la 30, ajungea la două luni în viitor. „Recent activity" lista intrări datate 2
 *     noiembrie pe 5 octombrie. Mai rău, graficele de 12 luni pierdeau tăcut acele afaceri: o
 *     lună care nu există încă n-are coloană, deci nu se numărau nicăieri.
 *  2. **Nimic „chiar acum".** Fără prag, tot ce era reașezat ajungea lipit de momentul rulării,
 *     iar feed-ul de după fiecare reset nocturn arăta opt evenimente din ultimele cinci minute
 *     — toate la minutul seed-ului.
 */
final class DemoClock
{
    /** Index 0 = ianuarie … 11 = decembrie. */
    private const MONTH_WEIGHTS = [
        1.3, 1.2, 1.1, 1.0, 0.9, 0.8,
        0.7, 0.8, 1.0, 1.1, 1.3, 1.4,
    ];

    /**
     * Fereastra în care se reașază un moment care a trecut de margine.
     *
     * TREIZECI DE ZILE, nu câteva ore, din cauza efectului de amplificare. Reașezarea nu mută
     * un singur rând: un CONT reașezat își trage după el toate comenzile (fallback-ul „creat
     * la 0-5 zile după cont", fiindcă orice dată istorică e anterioară unui cont de câteva
     * ore), fiecare comandă își trage factura și expedierea. Măsurat pe fereastra de trei
     * zile: 144 de comenzi în ultimele 24 de ore, într-un workspace care face 20,5 pe zi — de
     * șapte ori densitatea proprie, și toate cu o oră înainte de vizită.
     *
     * Cu treizeci de zile, cele ~20 de trageri care depășesc marginea (din 15.000, adică una
     * din 731 de zile) se împrăștie subțire în loc să se suprapună peste coada naturală.
     * Fereastra nu depinde de ora rulării, deci dă aceeași împrăștiere la 00:30 ca la prânz —
     * ceea ce contează, fiindcă resetul nocturn rulează la puțin după 00:00 UTC.
     */
    private const CLAMP_WINDOW_HOURS = 24 * 30;

    /** Cât de aproape de prezent poate ajunge orice moment semănat — vezi docblock-ul clasei. */
    private const CLAMP_FLOOR_MINUTES = 60;

    /** O dată aleasă uniform pe zi în interval, apoi acceptată/respinsă pe ponderea lunii ei. */
    public static function historicalDate(int $monthsBack = 24): Carbon
    {
        $now = Carbon::now();
        $start = $now->copy()->subMonths($monthsBack)->startOfDay();
        $totalDays = max(1, (int) $start->diffInDays($now));

        do {
            $candidate = $start->copy()->addDays(random_int(0, $totalDays));
        } while (! self::acceptForSeason($candidate));

        // Ultima zi a intervalului E ziua de azi, iar `setTime(8…18)` o împinge regulat peste
        // margine — la 4 dimineața, „azi la 15:00" e în viitor. Rar (o tragere din ~730), dar
        // seed-ul face zeci de mii, deci apărea la fiecare rulare, și apoi se propaga: factura
        // moștenește data comenzii, plata pe a facturii.
        return self::reseatIfPastTheEdge($candidate->setTime(self::businessHour(), random_int(0, 59), random_int(0, 59)));
    }

    /**
     * Un moment din ultimele `$maxDaysBack` zile, concentrat în orele de birou — aceeași
     * distribuție ca `historicalDate()`, pe o fereastră scurtă. Pentru coada recentă a
     * jurnalului de activitate (`Database\Seeders\Demo\ActivityVarietySeeder`).
     */
    public static function recentMoment(int $maxDaysBack): Carbon
    {
        $moment = Carbon::now()
            ->subDays(random_int(0, $maxDaysBack))
            ->setTime(self::businessHour(), random_int(0, 59), random_int(0, 59));

        return self::reseatIfPastTheEdge($moment);
    }

    /**
     * O dată ulterioară lui `$after`, la câteva ore/zile distanță — pentru succesiuni cauzale.
     *
     * Marginea se verifică pe AMBELE ramuri, nu doar pe cea de reașezare. Prima încercare de
     * reparare o pusese doar acolo, iar scurgerea a rămas exact unde nu te uitai: un rezultat
     * care nu trecea de `now()` era returnat neatins, deci o comandă creată acum 61 de minute
     * plus „0-6 ore" putea ateriza la un minut de prezent, cu expedierea ei imediat după.
     * Feed-ul se aduna la loc în ultimele minute.
     *
     * Când rezultatul natural trece de margine, momentul se alege UNIFORM în fereastra rămasă,
     * nu se fixează pe ea: o fixare ar îngrămădi zeci de evenimente la aceeași secundă, adică
     * ar înlocui un artefact vizibil cu unul mai greu de observat.
     */
    public static function shortlyAfter(Carbon $after, int $minHours, int $maxHours): Carbon
    {
        $candidate = $after->copy()->addHours(random_int($minHours, $maxHours))->addMinutes(random_int(0, 59));
        $latest = self::latestAcceptable();

        if ($candidate->lessThanOrEqualTo($latest)) {
            return $candidate;
        }

        $remaining = (int) $after->diffInSeconds($latest, absolute: false);

        // `$after` e deja peste margine (un salt anterior a fost reașezat chiar la ea): nu mai
        // există fereastră în care să încapă nimic, iar o dată anterioară ar rupe cauzalitatea
        // pe care funcția o garantează.
        return $remaining <= 0 ? $after->copy() : $after->copy()->addSeconds(random_int(1, $remaining));
    }

    /** Cel mai târziu moment pe care seed-ul are voie să-l scrie. */
    private static function latestAcceptable(): Carbon
    {
        return Carbon::now()->subMinutes(self::CLAMP_FLOOR_MINUTES);
    }

    /**
     * Readuce în trecut un moment care a trecut de margine, uniform în ultimele
     * `CLAMP_WINDOW_HOURS` ore.
     *
     * NU în ziua calendaristică a momentului, cum încercase prima variantă: resetul nocturn al
     * VPS-ului rulează la puțin după 00:00 UTC, deci „ziua de azi" avea atunci treizeci de
     * minute, iar toate tragerile care nimereau azi se înghesuiau în ele. O fereastră fixă nu
     * depinde de ora rulării, deci dă aceeași împrăștiere la 00:30 ca la prânz.
     */
    private static function reseatIfPastTheEdge(Carbon $moment): Carbon
    {
        $latest = self::latestAcceptable();

        if ($moment->lessThanOrEqualTo($latest)) {
            return $moment;
        }

        return $latest->copy()->subMinutes(random_int(0, self::CLAMP_WINDOW_HOURS * 60));
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
}
