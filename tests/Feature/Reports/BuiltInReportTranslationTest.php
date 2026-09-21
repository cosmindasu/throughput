<?php

namespace Tests\Feature\Reports;

use App\Support\Reports\DealVelocityReport;
use App\Support\Reports\InventoryValuationReport;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * FR-I18N-04, ADR-022 — `BuiltInReport::title()`/`columns()` (specs.md §16.3) trec prin
 * `lang/{en,fr}/reports.php` în loc de literale PHP. `title()`/`columns()` nu ating baza
 * (doar `rows()` face asta), deci instanțierea directă e suficientă — nu e nevoie de
 * `TenantContext`/fixture de bază pentru acest test.
 */
class BuiltInReportTranslationTest extends TestCase
{
    public function test_deal_velocity_title_and_columns_stay_english_by_default(): void
    {
        App::setLocale('en');
        $report = new DealVelocityReport;

        $this->assertSame('Deal Velocity by Stage', $report->title());
        $this->assertSame(
            ['Pipeline', 'Stage', 'Avg. days in stage', 'Deals reached', 'Conversion to next stage'],
            $report->columns(),
        );
    }

    public function test_deal_velocity_title_and_columns_translate_to_french(): void
    {
        App::setLocale('fr');
        $report = new DealVelocityReport;

        // „affaire", nu „opportunité": decizie de terminologie a proprietarului
        // (2026-09-21), luată la Valul 3, când auditul a găsit ambele forme în cataloagele
        // franceze — 35 de ocurențe una, 30 cealaltă, pe ecrane vecine. Vezi nota din
        // `lang/fr/rules.php`. Aserțiunea rămâne pe textul LITERAL, nu pe `__()`: altfel
        // ar trece verde comparând catalogul cu el însuși.
        $this->assertSame('Vitesse des affaires par étape', $report->title());
        $this->assertSame('Étape', $report->columns()[1]);
        $this->assertNotSame('Stage', $report->columns()[1]);

        App::setLocale('en');
    }

    public function test_inventory_valuation_title_and_columns_stay_english_by_default(): void
    {
        App::setLocale('en');
        $report = new InventoryValuationReport;

        $this->assertSame('Inventory Valuation', $report->title());
        $this->assertSame(
            ['Location', 'Category', 'On hand (units)', 'Total value'],
            $report->columns(),
        );
    }

    public function test_inventory_valuation_title_and_columns_translate_to_french(): void
    {
        App::setLocale('fr');
        $report = new InventoryValuationReport;

        $this->assertSame('Valorisation des stocks', $report->title());
        $this->assertSame('Emplacement', $report->columns()[0]);
        $this->assertNotSame('Location', $report->columns()[0]);

        App::setLocale('en');
    }
}
