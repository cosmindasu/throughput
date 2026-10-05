<?php

namespace App\Support\Activity;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Eticheta SCURTĂ a unei valori din enum-ul închis `activity_log.action` (§17.1,
 * migrația `2026_09_12_100080_create_activity_log_table.php`) — registrul „etichetă",
 * distinct de registrul „propoziție" al `lang/en/activity.php:entries.*`
 * (`ActivityNarrative`). Patru apelanți azi, o singură sursă de adevăr:
 *
 *  - `ActivityLogResource::toArray()` — `actionLabel` din jurnalul de activitate (FR-AUD-03);
 *  - `ActivityNarrative::describe()` — ramura `default`, cale de scăpare pentru o valoare de
 *    acțiune neacoperită de switch-ul de propoziții (metoda a fost `ActivityEntryResource::
 *    description()` până la extragerea din 2026-10-05, când al doilea ecran a avut nevoie de
 *    aceleași fraze);
 *  - `AccountActivityTimeline::build()` — rândurile provenite direct din `activity_log`
 *    (tab „Activity" al contului, FR-CRM-04): nu au un subiect de compus („Updated Account"),
 *    contul fiind deja implicit, doar eticheta acțiunii;
 *  - `ActivityLogController::actionOptions()` — opțiunile filtrului de acțiune, din aceeași
 *    sursă ca tabelul de sub el.
 *
 * ADR-022/FR-I18N-04 — un enum ÎNCHIS se traduce prin catalog (`lang/{en,fr}/activity.php`,
 * cheia `actions.*`), nu prin `Str::headline()` peste valoarea brută a coloanei. `Lang::has()`
 * rămâne totuși plasa de siguranță, nu decorație: `php artisan i18n:coverage` garantează
 * catalogul complet la momentul scrierii, dar nimic din schema Postgres nu leagă enum-ul
 * coloanei de conținutul lui `lang/`, deci o valoare viitoare adăugată în migrație fără
 * catalog actualizat tot ar ajunge aici. `__()` pe o cheie lipsă întoarce cheia BRUTĂ
 * („activity.actions.ceva-nou"), nu un fallback lizibil — `Str::headline()` e păstrat DOAR
 * ca ultimă linie de apărare, identic cu comportamentul dinaintea mutării pe catalog.
 */
final class ActivityActionLabel
{
    public static function resolve(string $action): string
    {
        $key = 'activity.actions.'.$action;

        return Lang::has($key) ? __($key) : Str::headline($action);
    }
}
