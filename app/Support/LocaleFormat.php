<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\App;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Formatarea locale-aware a numerelor, sumelor ȘI DATELOR, SERVER-SIDE — perechea lui
 * `resources/js/lib/money.ts` (numere/sume) și `resources/js/lib/format.ts` (date), ADR-022,
 * specs.md §15.8 FR-I18N-03: „niciodată concatenare manuală de simbol și cifre", „`Intl` în
 * frontend, echivalentul locale-aware în backend". `date()`/`dateTime()` (I18N-05) sunt
 * perechea server-side EXACTĂ a lui `formatDate()`/`formatDateTime()` din `format.ts`: aceleași
 * stiluri (`DATE_MEDIUM`/`DATE_TIME_MEDIUM`), pe `IntlDateFormatter` în loc de
 * `Intl.DateTimeFormat` — motivul identic celui de mai jos pentru sume: `Carbon::toFormattedDateString()`/
 * `toDayDateTimeString()` sunt **fixate pe engleză**, indiferent de `App::getLocale()`.
 *
 * **Criteriul de acceptare nu e „arată bine", ci „identic cu frontendul".** Aceeași factură
 * se vede pe ecran (React, `Intl.NumberFormat`) și în PDF (DomPDF, această clasă). Dacă cele
 * două ar diverge cu un separator, ar arăta ca o eroare de calcul, nu ca una de formatare.
 * `MoneyFormattingTest` fixează perechile măsurate, octet cu octet.
 *
 * **De ce `format()` și nu `formatCurrency()`** — singura nepotrivire față de frontend era
 * `fr` + USD: ICU randează implicit `1 234,56 $US` (dezambiguizare față de dolarul canadian),
 * iar FR-I18N-03 cere explicit `1 234,56 $`. Frontendul o rezolvă cu
 * `currencyDisplay: 'narrowSymbol'`; `NumberFormatter` din PHP nu are opțiunea, iar
 * `formatCurrency($v, $cur)` **suprascrie** simbolul din tabelă cu cel al monedei cerute, deci
 * un `setSymbol()` înainte n-are niciun efect. Forma care merge: moneda se dă prin cuvântul-cheie
 * de locale (`fr@currency=USD`), simbolul îngust prin `setSymbol()`, iar numărul prin `format()`.
 * Verificat că partea numerică era deja identică între ICU-ul din PHP și cel din browser —
 * `1U+202F234,56U+00A0` în franceză, `1,234.56` în engleză.
 *
 * **Harta de simboluri e deliberat SCURTĂ, nu exhaustivă.** `tenants.currency` e `string(3)` cu
 * implicit `USD` (migrația `create_tenants_table`), iar USD e singura valoare folosită azi. Pentru
 * o monedă necunoscută hărții NU inventăm nimic: cade pe simbolul implicit al ICU, care e mereu
 * corect și neambiguu, doar uneori mai lung (`CA$` în loc de `$`). Preferăm o diferență vizibilă
 * față de frontend pe o monedă neconfigurată nicăieri, în locul unui simbol ghicit greșit pe una
 * reală.
 *
 * **Cache-ul include locale-ul în cheie, obligatoriu.** Worker-ul de coadă e un proces de viață
 * lungă care randează PDF-uri pentru destinatari cu limbi diferite (`.ai/rules/tenancy.md`,
 * „Memoizarea per cerere"); un cache cheiat doar pe monedă ar întoarce tăcut formatorul francez
 * unui job englezesc care urmează. E aceeași clasă de bug pe care `money.ts` o documentează pe
 * partea de client, unde comutatorul de limbă nu reîncarcă modulul.
 */
final class LocaleFormat
{
    /**
     * Simbolul „îngust", pentru monedele pe care proiectul le poate configura efectiv.
     * Valorile sunt cele pe care `Intl.NumberFormat(..., { currencyDisplay: 'narrowSymbol' })`
     * le produce în frontend — copiate din măsurătoare, nu deduse.
     *
     * @var array<string, string>
     */
    private const NARROW_SYMBOLS = [
        'USD' => '$',
        'EUR' => "\u{20AC}",
        'GBP' => "\u{00A3}",
    ];

    /** @var array<string, NumberFormatter> */
    private static array $currencyFormatters = [];

    /** @var array<string, NumberFormatter> */
    private static array $decimalFormatters = [];

    /** @var array<string, NumberFormatter> */
    private static array $integerFormatters = [];

    /** @var array<string, IntlDateFormatter> */
    private static array $dateFormatters = [];

    /** @var array<string, IntlDateFormatter> */
    private static array $dateTimeFormatters = [];

    /**
     * Sumă cu simbol de monedă: `$1,234.56` (en) · `1 234,56 $` (fr, cu U+202F la mii și
     * U+00A0 înaintea simbolului — exact ce cere FR-I18N-03).
     */
    public static function money(float $amount, string $currency, ?string $locale = null): string
    {
        $locale ??= App::getLocale();
        $key = $locale.':'.$currency;

        if (! isset(self::$currencyFormatters[$key])) {
            $formatter = new NumberFormatter($locale.'@currency='.$currency, NumberFormatter::CURRENCY);

            if (isset(self::NARROW_SYMBOLS[$currency])) {
                $formatter->setSymbol(NumberFormatter::CURRENCY_SYMBOL, self::NARROW_SYMBOLS[$currency]);
            }

            self::$currencyFormatters[$key] = $formatter;
        }

        return self::$currencyFormatters[$key]->format($amount);
    }

    /**
     * Sumă FĂRĂ simbol, cu exact două zecimale: `1,234.56` (en) · `1 234,56` (fr).
     *
     * Folosită acolo unde moneda e deja spusă o dată pentru tot tabelul, iar repetarea
     * simbolului pe fiecare rând ar fi zgomot — liniile facturii, unde totalurile de dedesubt
     * poartă simbolul. Înlocuiește `number_format()`, care e **fixat pe convenția engleză**
     * (punct zecimal, virgulă la mii) indiferent de limba cererii.
     */
    public static function amount(float $amount, ?string $locale = null): string
    {
        $locale ??= App::getLocale();

        if (! isset(self::$decimalFormatters[$locale])) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

            self::$decimalFormatters[$locale] = $formatter;
        }

        return self::$decimalFormatters[$locale]->format($amount);
    }

    /**
     * Număr întreg, cu separator de mii din limba cererii: `1,234` (en) · `1 234` (fr, U+202F).
     *
     * Înlocuiește `number_format()` pe numărătorile care ajung în text vizibil (rânduri afectate
     * de o operație în masă, rânduri dintr-un raport livrat pe email). Nu e cosmetic: `1,234` citit
     * de un francofon e „1,234" — adică unu virgulă doi trei patru.
     */
    public static function count(int $value, ?string $locale = null): string
    {
        $locale ??= App::getLocale();

        if (! isset(self::$integerFormatters[$locale])) {
            self::$integerFormatters[$locale] = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        }

        return self::$integerFormatters[$locale]->format($value);
    }

    /**
     * Dată FĂRĂ oră, stil MEDIUM: „Sep 23, 2026" (en) · „23 sept. 2026" (fr, verificat pe
     * ICU-ul din container — vezi `LocaleFormatDateTest`). Perechea server-side a lui
     * `formatDate()`/`DATE_MEDIUM` din `resources/js/lib/format.ts` (§15.8 FR-I18N-03).
     * Înlocuiește `toFormattedDateString()` (`Carbon`), care e **fixat pe engleză**
     * indiferent de `App::getLocale()`.
     *
     * `null` intră, `null` iese — apelanții păstrează exact tiparul dinainte,
     * `$invoice->issue_date?->... ?? '—'` devine `LocaleFormat::date($invoice->issue_date)
     * ?? '—'`, fără un `?->` suplimentar de verificat aici.
     */
    public static function date(CarbonInterface|DateTimeInterface|null $value, ?string $locale = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $locale ??= App::getLocale();
        $key = self::formatterKey($locale, $value);

        if (! isset(self::$dateFormatters[$key])) {
            self::$dateFormatters[$key] = new IntlDateFormatter(
                $locale,
                IntlDateFormatter::MEDIUM,
                IntlDateFormatter::NONE,
                $value->getTimezone(),
            );
        }

        return self::$dateFormatters[$key]->format($value) ?: null;
    }

    /**
     * Dată + oră, stil MEDIUM pentru dată și SHORT pentru oră: „Sep 23, 2026, 5:09 PM" (en) ·
     * „23 sept. 2026, 17:09" (fr). Perechea server-side a lui
     * `formatDateTime()`/`DATE_TIME_MEDIUM` din `resources/js/lib/format.ts`. Înlocuiește
     * `toDayDateTimeString()`, la fel de fixat pe engleză.
     */
    public static function dateTime(CarbonInterface|DateTimeInterface|null $value, ?string $locale = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $locale ??= App::getLocale();
        $key = self::formatterKey($locale, $value);

        if (! isset(self::$dateTimeFormatters[$key])) {
            self::$dateTimeFormatters[$key] = new IntlDateFormatter(
                $locale,
                IntlDateFormatter::MEDIUM,
                IntlDateFormatter::SHORT,
                $value->getTimezone(),
            );
        }

        return self::$dateTimeFormatters[$key]->format($value) ?: null;
    }

    /**
     * Cheie de cache pentru `$dateFormatters`/`$dateTimeFormatters`, cu FUSUL ORAR inclus,
     * nu doar locale-ul — aceeași precauție ca la `$currencyFormatters`
     * („Cache-ul include locale-ul în cheie, obligatoriu", docblock-ul clasei): un
     * `IntlDateFormatter` se construiește cu fusul legat la instanță, deci un formator
     * cheiat DOAR pe locale ar întoarce tăcut fusul primei valori formatate unei valori
     * ulterioare cu alt fus, pe același worker de viață lungă.
     */
    private static function formatterKey(string $locale, CarbonInterface|DateTimeInterface $value): string
    {
        return $locale.':'.$value->getTimezone()->getName();
    }
}
