<?php

namespace Tests\Unit\Support;

use App\Support\LocaleFormat;
use PHPUnit\Framework\TestCase;

/**
 * specs.md §15.8 FR-I18N-03, ADR-022 — formatarea locale-aware server-side.
 *
 * **Ce apără testul ăsta, spus exact**: nu „formatul arată franțuzesc", ci că backendul
 * produce ȘIRUL IDENTIC cu cel pe care `resources/js/lib/money.ts` îl produce în browser.
 * Aceeași factură se vede pe ecran (React, `Intl.NumberFormat` cu
 * `currencyDisplay: 'narrowSymbol'`) și în PDF (DomPDF, `LocaleFormat`). O divergență de un
 * singur separator între cele două ar fi citită de utilizator ca eroare de CALCUL, nu de
 * formatare — de-asta valorile de mai jos sunt scrise cu escape-uri Unicode explicite, nu cu
 * spații tastate: un spațiu normal și un U+202F sunt vizual imposibil de deosebit într-un
 * diff, iar testul ar trece „arătând corect" în timp ce PDF-ul diverge de ecran.
 *
 * Valorile așteptate au fost MĂSURATE din `Intl.NumberFormat` (Node, același ICU), nu deduse:
 *
 *   en / USD  →  `$1,234.56`
 *   fr / USD  →  `1<U+202F>234,56<U+00A0>$`
 *   en / EUR  →  `€1,234.56`
 *   fr / EUR  →  `1<U+202F>234,56<U+00A0>€`
 *
 * Fără bază de date (`PHPUnit\Framework\TestCase`, nu `Tests\TestCase`): formatarea e funcție
 * pură de (sumă, monedă, limbă), iar un test care ar cere un tenant ar ascunde asta.
 */
class LocaleFormatTest extends TestCase
{
    /** U+202F — separatorul de mii al francezei în ICU modern. */
    private const NNBSP = "\u{202F}";

    /** U+00A0 — spațiul insecabil DINAINTEA simbolului de monedă, în franceză. */
    private const NBSP = "\u{00A0}";

    /**
     * Cazul central: aceeași sumă, aceeași monedă, două limbi. Moneda NU se schimbă cu limba
     * (specs.md §15.8: „un tenant în USD văzut cu interfața în franceză arată suma tot în
     * dolari, doar cu convenția franceză de poziționare și separatori").
     */
    public function test_the_same_amount_and_currency_render_per_locale(): void
    {
        $this->assertSame('$1,234.56', LocaleFormat::money(1234.56, 'USD', 'en'));
        $this->assertSame('1'.self::NNBSP.'234,56'.self::NBSP.'$', LocaleFormat::money(1234.56, 'USD', 'fr'));
    }

    /**
     * Simbolul „îngust", cazul pentru care `formatCurrency()` nu era suficient. Implicitul ICU
     * randează USD în franceză ca `1 234,56 $US`, ca să-l deosebească de dolarul canadian —
     * FR-I18N-03 cere însă explicit `1 234,56 $`, iar frontendul obține forma aia cu
     * `currencyDisplay: 'narrowSymbol'`. Aserțiunea de mai jos e cea care ar pica dacă cineva
     * „simplifică" helper-ul înapoi la `formatCurrency()`.
     */
    public function test_the_narrow_currency_symbol_is_used_not_the_disambiguated_one(): void
    {
        $french = LocaleFormat::money(1234.56, 'USD', 'fr');

        $this->assertStringEndsWith(self::NBSP.'$', $french);
        $this->assertStringNotContainsString('$US', $french);
    }

    /** Euro, ca să nu fie testat un singur simbol — poziția lui diferă la fel între limbi. */
    public function test_euro_follows_the_same_rules(): void
    {
        $this->assertSame("\u{20AC}1,234.56", LocaleFormat::money(1234.56, 'EUR', 'en'));
        $this->assertSame('1'.self::NNBSP.'234,56'.self::NBSP."\u{20AC}", LocaleFormat::money(1234.56, 'EUR', 'fr'));
    }

    /**
     * O monedă absentă din harta de simboluri îngouste nu trebuie să producă nimic ghicit:
     * cade pe simbolul implicit al ICU, care e mereu corect, doar uneori mai lung. Testul
     * fixează comportamentul ca DECIZIE, nu ca accident — vezi docblock-ul clasei.
     */
    public function test_an_unmapped_currency_falls_back_to_the_icu_default_symbol(): void
    {
        $this->assertSame('CA$1,234.56', LocaleFormat::money(1234.56, 'CAD', 'en'));
    }

    /**
     * Liniile unei facturi afișează suma FĂRĂ simbol (moneda e spusă o dată, la totaluri) —
     * dar separatorii trebuie să urmeze tot limba. `number_format()`, pe care îl înlocuiește,
     * e fixat pe convenția engleză indiferent de locale.
     */
    public function test_amounts_without_a_symbol_still_follow_the_locale(): void
    {
        $this->assertSame('1,234.56', LocaleFormat::amount(1234.56, 'en'));
        $this->assertSame('1'.self::NNBSP.'234,56', LocaleFormat::amount(1234.56, 'fr'));

        // Două zecimale MEREU, inclusiv pe o sumă rotundă — un total de „1 000" pe o factură
        // se citește ca sumă trunchiată, nu ca sumă rotundă.
        $this->assertSame('0.00', LocaleFormat::amount(0.0, 'en'));
        $this->assertSame('0,00', LocaleFormat::amount(0.0, 'fr'));
    }

    /** Numărătorile din mesaje (rânduri afectate, rânduri dintr-un raport). */
    public function test_counts_follow_the_locale(): void
    {
        $this->assertSame('1,234', LocaleFormat::count(1234, 'en'));
        $this->assertSame('1'.self::NNBSP.'234', LocaleFormat::count(1234, 'fr'));
    }

    /**
     * Capcana pentru care cheia de cache include limba. Worker-ul de coadă e un proces de
     * viață lungă care randează PDF-uri pentru destinatari cu limbi diferite
     * (`.ai/rules/tenancy.md`, „Memoizarea per cerere"): un cache cheiat doar pe monedă ar
     * întoarce tăcut formatorul primei limbi cerute, tuturor jobului de după. Testul cere
     * aceeași pereche în ambele ordini, în același proces.
     */
    public function test_formatters_do_not_leak_between_locales_in_one_process(): void
    {
        $firstFrench = LocaleFormat::money(1234.56, 'USD', 'fr');
        $english = LocaleFormat::money(1234.56, 'USD', 'en');
        $secondFrench = LocaleFormat::money(1234.56, 'USD', 'fr');

        $this->assertSame($firstFrench, $secondFrench);
        $this->assertNotSame($english, $firstFrench);
    }
}
