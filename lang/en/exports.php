<?php

declare(strict_types=1);

/**
 * FR-I18N-04 + BR-I18N-01 — anteturile de coloană ale exporturilor (CSV și PDF).
 *
 * Gol descoperit după Valul 2: anteturile NU stau în `app/Support/Exports/` (acolo sunt
 * doar consumatorii, `CsvExporter`/`PdfExporter`), ci în `exportHeaders()` din
 * `app/Support/Lists/*.php` — motiv pentru care prima împărțire pe agenți le-a ratat.
 *
 * CUPLAJ CU IMPORTUL, de citit înainte de a schimba un cuvânt de aici: un fișier
 * exportat în franceză trebuie să se poată reimporta fără remapare manuală. Maparea
 * rămâne pe cheie stabilă (BR-I18N-01, `ImportRowMapper` neatins), iar potrivirea
 * automată se face prin `ColumnMappingSuggester`, care compară antetul NORMALIZAT cu
 * aliasurile din `ImportField`. `normalize()` face
 * `preg_replace('/[^a-z0-9]+/', '', strtolower($v))` — deci ELIMINĂ diacriticele:
 * „Prénom" și „prenom" ajung amândouă la `prnom`.
 *
 * Consecința practică: fiecare traducere de mai jos care corespunde unui câmp
 * IMPORTABIL trebuie să existe, după normalizare, în lista de aliasuri a câmpului
 * respectiv. `tests/Feature/Imports/ExportHeaderRoundTripTest.php` verifică exact asta,
 * pe anteturile reale produse de `exportHeaders()`, nu pe exemple scrise de mână.
 *
 * Coloanele care nu au corespondent la import (Status, Owner, Created at, tot ce ține
 * de comenzi și facturi — care nu se importă deloc) nu au constrângerea asta.
 */
return [
    'accounts' => [
        'name' => 'Name',
        'domain' => 'Domain',
        'industry' => 'Industry',
        'status' => 'Status',
        'credit_terms' => 'Credit terms',
        'owner' => 'Owner',
        'created_at' => 'Created at',
    ],

    'contacts' => [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'phone' => 'Phone',
        'title' => 'Title',
        'account' => 'Account',
        'primary_contact' => 'Primary contact',
        'marketing_opt_out' => 'Marketing opt-out',
        'created_at' => 'Created at',
    ],

    'orders' => [
        'order_number' => 'Order number',
        'status' => 'Status',
        'account' => 'Account',
        'owner' => 'Owner',
        'grand_total' => 'Grand total',
        'currency' => 'Currency',
        'placed_at' => 'Placed at',
        'created_at' => 'Created at',
    ],

    'invoices' => [
        'invoice_number' => 'Invoice number',
        'status' => 'Status',
        'account' => 'Account',
        'order' => 'Order',
        'issue_date' => 'Issue date',
        'due_date' => 'Due date',
        'currency' => 'Currency',
        'total' => 'Total',
        'amount_paid' => 'Amount paid',
        'balance_due' => 'Balance due',
    ],

    /*
     * I18N-08 — mesajele de eroare ale `App\Support\Exports\ExportFormat` (nu anteturi de
     * coloană, deci fără constrângerea BR-I18N-01 de mai sus). Textul englez e identic,
     * caracter cu caracter, cu literalul dinainte de extragere.
     */
    'errors' => [
        'unknown_format' => 'Unknown export format ":format". Use csv, pdf or zip.',
    ],
];
