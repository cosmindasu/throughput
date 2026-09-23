<?php

namespace Tests\Unit\Support;

use App\Support\LocaleFormat;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * I18N-05, specs.md §15.8 FR-I18N-03, ADR-022 — `LocaleFormat::date()`/`dateTime()`,
 * perechea server-side EXACTĂ a lui `formatDate()`/`formatDateTime()` din
 * `resources/js/lib/format.ts` (stiluri `DATE_MEDIUM`/`DATE_TIME_MEDIUM`). Înlocuiesc
 * `Carbon::toFormattedDateString()`/`toDayDateTimeString()`, care sunt FIXATE pe engleză
 * indiferent de `App::getLocale()`.
 *
 * **Capcana ICU pe care testul ăsta trebuie să o prindă, explicit, nu doar „arată franțuzesc"**:
 * imaginea Docker a primit `icu-data-full` abia recent — fără el, ICU cade TĂCUT pe locale-ul
 * `root` pentru orice limbă fără date compilate, iar franceza se formatează IDENTIC cu
 * engleza (fără nicio eroare aruncată). O aserțiune „conține litere frantuzești" n-ar prinde
 * regresia dacă cineva rescrie imaginea fără `icu-data-full`; o aserțiune pe ȘIRUL EXACT, MĂSURAT
 * în containerul cu ICU 78.1 instalat, o prinde. Valorile de mai jos sunt cele produse REAL de
 * `IntlDateFormatter` în container (verificat cu `php artisan tinker`, nu deduse din documentație):
 *
 *   en, MEDIUM        → `Sep 23, 2026`
 *   fr, MEDIUM        → `23 sept. 2026`               (spații ASCII normale — NU U+202F; ICU
 *                                                       78.1 nu pune spațiu insecabil îngust în
 *                                                       jurul lunii abreviate în format de dată)
 *   en, MEDIUM+SHORT  → `Sep 23, 2026, 5:09<U+202F>PM` (!) — ICU 78.1 pune U+202F, NU spațiu
 *                                                       normal, ÎNTRE oră și marcatorul AM/PM
 *                                                       în engleză (CLDR recent, verificat cu
 *                                                       `bin2hex()` în container — nu presupus;
 *                                                       un `assertSame` cu spațiu ASCII tastat
 *                                                       normal ar pica, nu doar franceza are
 *                                                       nevoie de atenție la spații speciale)
 *   fr, MEDIUM+SHORT  → `23 sept. 2026, 17:09`         (24h, fără marcator de perioadă, deci
 *                                                       fără U+202F suplimentar față de cel de
 *                                                       la §12.2-stil deja acoperit mai sus)
 *
 * Fără bază de date (`PHPUnit\Framework\TestCase`, ca `LocaleFormatTest`): formatarea e
 * funcție pură de (dată, fus, limbă); locale-ul e mereu transmis explicit, niciodată citit din
 * `App::getLocale()` — un `PHPUnit\Framework\TestCase` nu bootstrapează containerul Laravel.
 */
class LocaleFormatDateTest extends TestCase
{
    /** U+202F — NARROW NO-BREAK SPACE, separatorul francez de mii/înaintea punctuației duble. */
    private const NNBSP = "\u{202F}";

    /** U+00A0 — NBSP obișnuit, vizual identic cu U+202F dar greșit în convenția proiectului. */
    private const NBSP = "\u{00A0}";

    public function test_date_renders_medium_style_per_locale(): void
    {
        $value = CarbonImmutable::create(2026, 9, 23, 17, 9, 0, 'UTC');

        $this->assertSame('Sep 23, 2026', LocaleFormat::date($value, 'en'));
        $this->assertSame('23 sept. 2026', LocaleFormat::date($value, 'fr'));
    }

    /**
     * Regresie ICU explicită: dacă `icu-data-full` lipsește din imagine, franceza cade pe
     * `root` și produce EXACT string-ul englezesc — testul de mai sus ar trece „din
     * întâmplare" doar dacă cineva ar slăbi aserțiunea `en`. Asta verifică direct diferența.
     */
    public function test_french_date_differs_from_english_and_has_no_stray_nnbsp_or_nbsp(): void
    {
        $value = CarbonImmutable::create(2026, 9, 23, 17, 9, 0, 'UTC');

        $french = LocaleFormat::date($value, 'fr');
        $english = LocaleFormat::date($value, 'en');

        $this->assertNotSame($english, $french);
        $this->assertStringNotContainsString(self::NNBSP, $french);
        $this->assertStringNotContainsString(self::NBSP, $french);
    }

    public function test_date_time_renders_medium_date_and_short_time_per_locale(): void
    {
        $value = CarbonImmutable::create(2026, 9, 23, 17, 9, 0, 'UTC');

        $this->assertSame('Sep 23, 2026, 5:09'.self::NNBSP.'PM', LocaleFormat::dateTime($value, 'en'));
        $this->assertSame('23 sept. 2026, 17:09', LocaleFormat::dateTime($value, 'fr'));
    }

    /**
     * Fusul e cel al OBIECTULUI transmis, nu cel implicit al aplicației (`config('app.timezone')`
     * = `UTC`) — altfel o factură randată pentru un destinatar din alt fus ar arăta o oră
     * greșită în PDF fără nicio eroare. `America/New_York` e UTC-4 în septembrie (DST).
     */
    public function test_date_time_uses_the_value_own_timezone_not_the_application_default(): void
    {
        $utc = CarbonImmutable::create(2026, 9, 23, 17, 9, 0, 'UTC');
        $newYork = $utc->setTimezone('America/New_York');

        $this->assertSame('Sep 23, 2026, 5:09'.self::NNBSP.'PM', LocaleFormat::dateTime($utc, 'en'));
        $this->assertSame('Sep 23, 2026, 1:09'.self::NNBSP.'PM', LocaleFormat::dateTime($newYork, 'en'));
    }

    /**
     * `null` intră, `null` iese — apelanții păstrează tiparul `?? '—'` de dinainte
     * (`$invoice->issue_date?->toFormattedDateString() ?? '—'` devine
     * `LocaleFormat::date($invoice->issue_date) ?? '—'`), fără `?->` suplimentar aici.
     */
    public function test_null_in_null_out_for_both_helpers(): void
    {
        $this->assertNull(LocaleFormat::date(null, 'fr'));
        $this->assertNull(LocaleFormat::dateTime(null, 'fr'));
    }

    /**
     * Capcana cache-ului, ca la `LocaleFormatTest::test_formatters_do_not_leak_between_locales_in_one_process()`:
     * un `IntlDateFormatter` se leagă de fus la CONSTRUCȚIE, deci cheia de cache trebuie să
     * includă fusul, nu doar limba — altfel un worker de coadă care randează pentru fusuri
     * diferite pe rând ar întoarce tăcut fusul primei cereri celor de după.
     */
    public function test_formatters_do_not_leak_between_timezones_in_one_process(): void
    {
        $utc = CarbonImmutable::create(2026, 9, 23, 17, 9, 0, 'UTC');
        $newYork = $utc->setTimezone('America/New_York');

        $first = LocaleFormat::dateTime($newYork, 'en');
        $second = LocaleFormat::dateTime($utc, 'en');
        $third = LocaleFormat::dateTime($newYork, 'en');

        $this->assertSame('Sep 23, 2026, 1:09'.self::NNBSP.'PM', $first);
        $this->assertSame('Sep 23, 2026, 5:09'.self::NNBSP.'PM', $second);
        $this->assertSame($first, $third);
    }
}
