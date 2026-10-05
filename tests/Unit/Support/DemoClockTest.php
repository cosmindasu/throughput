<?php

namespace Tests\Unit\Support;

use Database\Seeders\Support\DemoClock;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Ceasul seed-ului demo. Fără bază de date — e aritmetică pe date calendaristice.
 *
 * Regula pe care o apără fișierul: un seed descrie ISTORIE. Fiecare apel al lui
 * `shortlyAfter()` din seedere reprezintă ceva care S-A ÎNTÂMPLAT (o tranziție de etapă, o
 * expediere, o livrare), deci nu poate fi datat după ziua de azi.
 *
 * Versiunea nemărginită a funcției încălca asta de rutină, iar defectul se vedea pe două
 * căi foarte diferite: zgomotos pe dashboard („Recent activity" lista 2 noiembrie pe 5
 * octombrie) și TĂCUT în graficele de 12 luni, unde o lună încă inexistentă nu are coloană,
 * deci afacerile câștigate „în viitor" nu se numărau nicăieri. A doua cale e motivul pentru
 * care testul merită să existe: n-ar fi fost prinsă privind pagina.
 */
class DemoClockTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_causal_step_never_lands_in_the_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $now = Carbon::now();

        // Fereastra cerută (până la 30 de zile) depășește cu mult cele 3 zile rămase până
        // „acum" — exact configurația în care varianta nemărginită sărea peste prezent.
        $after = $now->copy()->subDays(3);

        for ($i = 0; $i < 300; $i++) {
            $result = DemoClock::shortlyAfter($after, 48, 24 * 30);

            $this->assertTrue(
                $result->lessThanOrEqualTo($now->copy()->subMinutes(59)),
                "shortlyAfter a întors {$result->toDateTimeString()}, prea aproape de acum ({$now->toDateTimeString()})",
            );
            $this->assertTrue(
                $result->greaterThan($after),
                "shortlyAfter a întors {$result->toDateTimeString()}, înaintea punctului de plecare ({$after->toDateTimeString()})",
            );
        }
    }

    /**
     * Plafonarea nu trebuie să devină o fixare: dacă toate rezultatele plafonate ar cădea pe
     * `now()`, zeci de evenimente ar împărți aceeași secundă — un artefact mai greu de
     * observat decât cel pe care îl înlocuiește.
     */
    public function test_the_clamped_window_is_spread_out_rather_than_pinned_to_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $after = Carbon::now()->copy()->subDays(10);

        $distinct = [];
        for ($i = 0; $i < 200; $i++) {
            $distinct[DemoClock::shortlyAfter($after, 24 * 20, 24 * 40)->toDateTimeString()] = true;
        }

        $this->assertGreaterThan(100, count($distinct), 'rezultatele plafonate sunt îngrămădite pe prea puține momente');
    }

    /**
     * Pasul plafonat lasă o oră liberă înaintea prezentului CÂND fereastra o permite — altfel
     * o comandă plasată acum două ore primește o expediere de acum un minut, și tot feed-ul
     * se adună iar în ultimele minute.
     */
    public function test_a_clamped_step_keeps_its_distance_from_now_when_there_is_room(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $now = Carbon::now();
        $after = $now->copy()->subHours(6);

        for ($i = 0; $i < 200; $i++) {
            $result = DemoClock::shortlyAfter($after, 48, 24 * 30);

            $this->assertTrue($result->lessThanOrEqualTo($now->copy()->subMinutes(59)), "shortlyAfter a întors {$result->toDateTimeString()}, prea aproape de acum");
        }
    }

    /**
     * `$after` e deja dincolo de marginea de o oră — nu mai există fereastră în care să încapă
     * pasul următor. Funcția îl lasă acolo: a-l împinge înainte ar încălca marginea, a-l trage
     * înapoi ar inversa cauzalitatea pe care chiar ea o garantează. În seed-ul real cazul nu
     * apare (orice punct de plecare trece întâi prin `historicalDate`/`recentMoment`, deci e
     * el însuși în afara ferestrei), dar e singura ramură fără ieșire bună, deci e scrisă.
     */
    public function test_a_step_from_a_point_already_past_the_edge_stays_put(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $after = Carbon::now()->copy()->subMinutes(10);

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame($after->toDateTimeString(), DemoClock::shortlyAfter($after, 48, 96)->toDateTimeString());
        }
    }

    /** Fereastra naturală încape înaintea prezentului: funcția nu trebuie să se atingă de ea. */
    public function test_a_step_that_already_fits_in_the_past_is_left_alone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $after = Carbon::now()->copy()->subMonths(6);

        $result = DemoClock::shortlyAfter($after, 48, 72);

        $this->assertTrue($result->greaterThanOrEqualTo($after->copy()->addHours(48)));
        $this->assertTrue($result->lessThanOrEqualTo($after->copy()->addHours(73)));
    }

    /** `$after` chiar pe margine: nu mai e fereastră, dar cauzalitatea nu se poate inversa. */
    public function test_a_step_from_the_present_does_not_go_backwards(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $now = Carbon::now();

        $result = DemoClock::shortlyAfter($now->copy(), 48, 96);

        $this->assertSame($now->toDateTimeString(), $result->toDateTimeString());
    }

    public function test_historical_dates_stay_inside_the_requested_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $now = Carbon::now();

        for ($i = 0; $i < 200; $i++) {
            $result = DemoClock::historicalDate(24);

            $this->assertTrue($result->lessThanOrEqualTo($now), "historicalDate a întors {$result->toDateTimeString()}");
            $this->assertTrue($result->greaterThanOrEqualTo($now->copy()->subMonths(24)->startOfDay()));
        }
    }

    /**
     * Cazul care se pierde într-o fereastră de 24 de luni: ziua de AZI. `historicalDate` alege
     * întâi o zi, apoi îi pune o oră de birou (8-18) — deci o tragere care nimerește azi
     * dimineața iese în viitor. E o tragere din ~730, adică invizibilă la 200 de iterații pe
     * fereastra mare, dar seed-ul face zeci de mii, deci se întâmpla la fiecare rulare.
     *
     * `historicalDate(0)` colapsează fereastra la ziua curentă și face cazul CERT.
     */
    public function test_a_draw_that_lands_on_today_is_pulled_back_before_the_current_hour(): void
    {
        // 00:20 UTC — chiar ora la care rulează resetul nocturn al VPS-ului, și cazul în care
        // „undeva azi, până acum" ar însemna o fereastră de douăzeci de minute.
        Carbon::setTestNow(Carbon::parse('2026-10-05 00:20:00'));
        $now = Carbon::now();

        $distinct = [];
        for ($i = 0; $i < 100; $i++) {
            $result = DemoClock::historicalDate(0);

            $this->assertTrue($result->lessThanOrEqualTo($now), "historicalDate(0) a întors {$result->toDateTimeString()}");
            // Reașezarea folosește o fereastră de 30 de zile DINCOLO de marginea de o oră,
            // deci trece peste miezul nopții — NU se îngrămădește în cele douăzeci de minute
            // scurse din ziua curentă.
            $this->assertTrue($result->greaterThanOrEqualTo($now->copy()->subHours(24 * 30 + 1)));
            // …și nu ajunge niciodată „chiar acum": altfel feed-ul ar arăta, după fiecare
            // reset, opt evenimente din ultimele cinci minute.
            $this->assertTrue($result->lessThanOrEqualTo($now->copy()->subMinutes(59)));
            $distinct[$result->toDateTimeString()] = true;
        }

        $this->assertGreaterThan(80, count($distinct), 'tragerile reașezate sunt îngrămădite pe prea puține momente');
    }

    /** Coada recentă a jurnalului: aceeași regulă, pe fereastra scurtă. */
    public function test_recent_moments_stay_inside_their_window_and_in_the_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 00:20:00'));
        $now = Carbon::now();

        for ($i = 0; $i < 200; $i++) {
            $result = DemoClock::recentMoment(14);

            $this->assertTrue($result->lessThanOrEqualTo($now->copy()->subMinutes(59)), "recentMoment a întors {$result->toDateTimeString()}");
            $this->assertTrue($result->greaterThanOrEqualTo($now->copy()->subDays(14)->startOfDay()->subHours(24 * 30 + 1)));
        }
    }
}
