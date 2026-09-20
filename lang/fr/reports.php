<?php

/**
 * Traducere franceză — vezi `lang/en/reports.php` pentru cheile-sursă și comentariile
 * structurale. Pasajele „⚠ NATIV" sunt jargon CRM/contabil, de revizuit de un vorbitor
 * nativ (testul de acoperire verifică doar prezența cheii, nu calitatea traducerii).
 */
return [

    'deal_velocity' => [
        // ⚠ NATIV: „Deal" e termen CRM — unele unelte franceze (Salesforce FR) păstrează
        // „Deal"/„Opportunité" interschimbabil; ales aici „opportunité" (termenul oficial
        // francez din majoritatea CRM-urilor), de confirmat de un nativ din domeniu.
        'title' => 'Vitesse des opportunités par étape',
        'columns' => [
            // Păstrat ca atare — „Pipeline" e folosit netradus în CRM-urile franceze uzuale.
            'pipeline' => 'Pipeline',
            'stage' => 'Étape',
            // ⚠ NATIV
            'avg_days_in_stage' => 'Jours moyens par étape',
            'deals_reached' => 'Opportunités atteintes',
            // ⚠ NATIV
            'conversion_to_next_stage' => "Taux de conversion vers l'étape suivante",
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
