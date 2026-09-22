<?php

/**
 * Traducere franceză — vezi `lang/en/reports.php` pentru cheile-sursă și comentariile
 * structurale. Pasajele „⚠ NATIV" sunt jargon CRM/contabil, de revizuit de un vorbitor
 * nativ (testul de acoperire verifică doar prezența cheii, nu calitatea traducerii).
 */
return [

    // Voir `lang/en/reports.php` pour le contexte — repli de `ReportDefinitionResource::sourceLabel`.
    'saved_view_fallback' => 'Vue enregistrée',

    'deal_velocity' => [
        // „Deal" → „affaire", decizie a proprietarului (2026-09-21), aplicată uniform în
        // TOATE cataloagele franceze — vezi nota din `lang/fr/rules.php`, care e locul unde
        // întrebarea fusese pusă. Varianta respinsă, „opportunité", e termenul folosit de
        // Salesforce FR și Dynamics FR; alegerea nu s-a făcut pe standardul de industrie, ci
        // pe consecvență cu mesajele flash și cu e-mailurile scrise deja în Valul 2.
        // Auditul care a declanșat decizia: 35 de ocurențe „affaire" față de 30
        // „opportunité", împărțite pe 15 fișiere din ambele valuri.
        'title' => 'Vitesse des affaires par étape',
        'columns' => [
            // Păstrat ca atare — „Pipeline" e folosit netradus în CRM-urile franceze uzuale.
            'pipeline' => 'Pipeline',
            'stage' => 'Étape',
            // ⚠ NATIV
            'avg_days_in_stage' => 'Jours moyens par étape',
            'deals_reached' => 'Affaires atteintes',
            // ⚠ NATIV
            'conversion_to_next_stage' => 'Taux de conversion vers l’étape suivante',
        ],
        'unknown_pipeline' => 'Pipeline inconnu',
    ],

    'inventory_valuation' => [
        // ⚠ NATIV: termen contabil — „Valorisation des stocks" e uzual, de confirmat.
        'title' => 'Valorisation des stocks',
        'columns' => [
            'location' => 'Emplacement',
            'category' => 'Catégorie',
            // ⚠ NATIV
            'on_hand_units' => 'En stock (unités)',
            'total_value' => 'Valeur totale',
        ],
        'unknown_location' => 'Emplacement inconnu',
        'uncategorized' => 'Sans catégorie',
    ],

];
