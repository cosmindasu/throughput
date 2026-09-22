<?php

/**
 * Traducere franceză — vezi `lang/en/pdf.php` pentru cheile-sursă și comentariile
 * structurale (neduplicate aici). Pasajele semnalate „⚠ NATIV" sunt terminologie de
 * factură/raport care merită o trecere a unui vorbitor nativ înainte de publicare (cerut
 * explicit de acest lot) — testul de acoperire verifică doar PREZENȚA cheii, nu calitatea
 * traducerii (ADR-022, „Cost, stated honestly").
 */
return [

    'meta' => [
        'generated' => 'Généré le :date',
        // Vezi comentariul din `lang/en/pdf.php`: `trans_choice()` alege singurul segment
        // corect pentru `fr` (0 ȘI 1 → aceeași formă), fără cod suplimentar.
        'row_count' => ':count ligne|:count lignes',
    ],

    'export' => [
        // ⚠ NATIV: „export" e un anglicism larg acceptat în franceza de business/tech
        // (folosit ca atare în multe unelte franceze); varianta formală ar fi „Exportation".
        'title' => 'Export',
        'heading_suffix' => 'export',
        'filters_label' => 'Filtres',
        'no_rows' => 'Aucune ligne ne correspond à ce filtre.',
    ],

    'report' => [
        'no_rows' => 'Aucune ligne.',
    ],

    'invoice' => [
        'title' => 'Facture :number',
        'fallback_tenant_name' => 'Facture',
        // ⚠ NATIV: terminologie de facturation — de revizuit de un vorbitor nativ/contabil francofon.
        'bill_to' => 'Facturé à',
        'anonymized_contact' => 'Contact anonymisé',
        'issue_date' => 'Date d’émission',
        'order' => 'Commande',
        // ⚠ NATIV
        'due_date' => 'Date d’échéance',
        'line' => 'Ligne',
        'quantity' => 'Quantité',
        'unit_price' => 'Prix unitaire',
        'discount' => 'Remise',
        // ⚠ NATIV: „Total ligne" vs. „Total de la ligne" — registru de decis de un nativ.
        'line_total' => 'Total ligne',
        'no_lines' => 'Aucune ligne sur la commande source.',
        'subtotal' => 'Sous-total',
        // ⚠ NATIV: „Taxe" generic, deliberat — produsul nu are nicio noțiune de TVA
        // franceză (§2.3, ADR — piață exclusiv internațională, fără ANAF/TVA românesc și,
        // simetric, fără presupunere de TVA franceză). Un vorbitor nativ ar putea prefera
        // alt termen, dar „TVA" ar fi o afirmație falsă despre ce calculează aplicația.
        'tax' => 'Taxe',
        'total' => 'Total',
        'paid' => 'Payé',
        // ⚠ NATIV
        'balance_due' => 'Solde dû',
        'status' => [
            'draft' => 'Brouillon',
            'sent' => 'Envoyée',
            'paid' => 'Payée',
            // ⚠ NATIV: „En retard" ales peste „Échue" — registru de confirmat de un nativ.
            'overdue' => 'En retard',
            'void' => 'Annulée',
        ],
    ],

];
