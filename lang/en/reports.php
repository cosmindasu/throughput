<?php

/**
 * Titluri + headere de coloană pentru rapoartele BUILT-IN (specs.md §16.3,
 * `App\Support\Reports\BuiltInReport`) — FR-I18N-04. Cheile de nivel 1 oglindesc EXACT
 * `BuiltInReports::map()` (`deal_velocity`, `inventory_valuation`): un al treilea raport
 * built-in viitor (FR-REP-02, fază separată) adaugă propriul bloc aici, fără să atingă
 * structura existentă.
 *
 * NU acoperă `report_definitions.name` (numele ALES de utilizator la crearea unui raport,
 * salvat/vizualizat/programat) — acela e conținut introdus de utilizator, granița
 * FR-I18N-06 (§15.8): ce scrie utilizatorul nu se traduce, doar ce generează aplicația.
 */
return [

    // FR-I18N-04, Lotul I18N Val 5 — eticheta de rezervă a `ReportDefinitionResource::sourceLabel`
    // când raportul e pe o vedere salvată fără (sau cu) nume, nu titlul unui raport built-in
    // (acela vine din `BuiltInReports::resolve()->title()`, nu din acest fișier). Nivel de
    // top, nu sub `deal_velocity`/`inventory_valuation` — nu descrie un raport built-in.
    'saved_view_fallback' => 'Saved view',

    'deal_velocity' => [
        'title' => 'Deal Velocity by Stage',
        'columns' => [
            'pipeline' => 'Pipeline',
            'stage' => 'Stage',
            'avg_days_in_stage' => 'Avg. days in stage',
            'deals_reached' => 'Deals reached',
            'conversion_to_next_stage' => 'Conversion to next stage',
        ],
        'unknown_pipeline' => 'Unknown pipeline',
    ],

    'inventory_valuation' => [
        'title' => 'Inventory Valuation',
        'columns' => [
            'location' => 'Location',
            'category' => 'Category',
            'on_hand_units' => 'On hand (units)',
            'total_value' => 'Total value',
        ],
        'unknown_location' => 'Unknown location',
        'uncategorized' => 'Uncategorized',
    ],

];
