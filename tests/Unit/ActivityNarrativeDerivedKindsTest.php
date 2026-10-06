<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Order;
use App\Support\Activity\ActivityKind;
use App\Support\Activity\ActivityNarrative;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Garda pe IEȘIREA lui `ActivityNarrative::describe()`, nu pe existența cheilor.
 *
 * De ce există separat de `ActivityKindTest`: lotul din 2026-10-06 a adăugat
 * `invoice_sent`/`invoice_overdue`/`invoice_void` în `ActivityKind::DERIVED`, cu traduceri în
 * ambele cataloage și cu icon plus tentă în `activityKind.ts` — dar fără ramurile
 * corespunzătoare în `describe()`. Rezultatul: iconul și culoarea rândului se schimbau, iar
 * fraza cădea pe ramura generică, „Updated Invoice". Traducerile erau șiruri morte.
 *
 * Testul de atunci verifica, prin `Lang::get(..., fallback: false)`, că CHEIA există în
 * fiecare catalog. Trecea verde, fiindcă cheia exista — nimic nu cerea ca cineva să o
 * folosească. O gardă pe existența cheii nu e o gardă pe ieșire; e nevoie de amândouă.
 *
 * Fără bază de date: `describe()` și `subjectLabel()` citesc doar atribute și catalogul.
 */
function narrativeEntry(string $type, array $new): ActivityLog
{
    $entry = new ActivityLog;
    $entry->action = 'updated';
    $entry->auditable_type = $type;
    $entry->new_values = $new;

    return $entry;
}

/**
 * Fixtura REALĂ pentru fiecare tip derivat — forma pe care o scrie codul de producție, nu o
 * scurtătură prin parametrul `$kind` al lui `describe()`. Așa trece și prin
 * `ActivityKind::of()`, deci o fixtură care n-ar mai deriva tipul cerut cade singură.
 *
 * @return array<string, array{0: class-string, 1: array<string, mixed>}>
 */
function derivedFixtures(): array
{
    return [
        'stage_moved' => [Deal::class, ['stage_id' => '01JD0000000000000000000000']],
        'invoice_paid' => [Invoice::class, ['status' => Invoice::STATUS_PAID]],
        'invoice_sent' => [Invoice::class, ['status' => Invoice::STATUS_SENT]],
        'invoice_overdue' => [Invoice::class, ['status' => Invoice::STATUS_OVERDUE]],
        'invoice_void' => [Invoice::class, ['status' => Invoice::STATUS_VOID]],
        'order_shipped' => [Order::class, ['status' => Order::STATUS_FULFILLED]],
        'member_deactivated' => [Membership::class, ['status' => Membership::STATUS_DEACTIVATED]],
    ];
}

it('nu lasa niciun tip derivat fara fixtura aici', function () {
    // Dacă `DERIVED` crește, testul ăsta pică până cineva adaugă fixtura — adică până cineva
    // se uită dacă `describe()` chiar produce o frază pentru tipul nou.
    expect(array_keys(derivedFixtures()))->toEqualCanonicalizing(ActivityKind::DERIVED);
});

it('fiecare tip derivat produce fraza LUI, nu verbul generic', function (string $locale) {
    App::setLocale($locale);

    foreach (derivedFixtures() as $kind => [$type, $new]) {
        $entry = narrativeEntry($type, $new);

        // Control pe fixtură: dacă asta cade, fixtura nu mai derivă tipul pe care îl testăm,
        // iar restul afirmațiilor ar verifica altceva decât cred că verifică.
        expect(ActivityKind::of($entry))->toBe($kind);

        $subject = __('activity.subjects.'.Str::lower(class_basename($type)));
        $phrase = ActivityNarrative::describe($entry);

        // `member_deactivated` n-are `:subject` în șir; un parametru în plus e ignorat.
        expect($phrase)->toBe(__('activity.entries.'.$kind, ['subject' => $subject]));

        // AFIRMAȚIA care ar fi prins defectul: fraza nu e cea generică. Fără ea, o ramură
        // lipsă din `describe()` trece verde atât timp cât traducerea există undeva.
        expect($phrase)->not->toBe(__('activity.entries.updated', ['subject' => $subject]));
    }
})->with(['en', 'fr']);
