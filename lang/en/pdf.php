<?php

/**
 * Textul din cele 3 șabloane PDF (export listă, raport built-in, factură) — FR-I18N-04,
 * specs.md §15.8. `en` e sursa de adevăr pentru `php artisan i18n:coverage` (ADR-022).
 *
 * `<html lang>` NU trece prin acest catalog: fiecare șablon citește direct
 * `app()->getLocale()` la randare, ca să rămână sincron cu limba deja fixată de apelant
 * (job de export/raport/factură — FR-I18N-05, cod din afara perimetrului acestui lot) fără
 * o variabilă suplimentară de propagat prin `PdfExporter`/`ReportFileWriter`/joburile de
 * factură.
 */
return [

    // Comun celor trei șabloane.
    'meta' => [
        'generated' => 'Generated :date',
        // Pluralizare CORECTĂ per limbă prin `trans_choice()`, NU `Str::plural()` (care
        // aplică mereu regula engleză — 0 tratat ca plural). `MessageSelector::getPluralIndex()`
        // din framework alege deja segmentul potrivit după locale-ul curent, iar pentru
        // `fr` tratează 0 ȘI 1 ca aceeași formă (singular) — nicio regulă suplimentară de
        // scris aici, doar un catalog cu DOUĂ segmente (singular|plural), ca la engleză.
        'row_count' => ':count row|:count rows',
    ],

    // resources/views/exports/pdf/list.blade.php
    'export' => [
        'title' => 'Export',
        'heading_suffix' => 'export',
        'filters_label' => 'Filters',
        'no_rows' => 'No rows match this filter.',
    ],

    // resources/views/reports/pdf/built-in.blade.php
    'report' => [
        'no_rows' => 'No rows.',
    ],

    // resources/views/invoices/pdf/invoice.blade.php
    'invoice' => [
        'title' => 'Invoice :number',
        'fallback_tenant_name' => 'Invoice',
        'bill_to' => 'Bill to',
        'anonymized_contact' => 'Anonymized contact',
        'issue_date' => 'Issue date',
        'order' => 'Order',
        'due_date' => 'Due date',
        'line' => 'Line',
        'quantity' => 'Quantity',
        'unit_price' => 'Unit price',
        'discount' => 'Discount',
        'line_total' => 'Line total',
        'no_lines' => 'No lines on the source order.',
        'subtotal' => 'Subtotal',
        'tax' => 'Tax',
        'total' => 'Total',
        'paid' => 'Paid',
        'balance_due' => 'Balance due',
        // Cheile oglindesc EXACT `Invoice::STATUS_*` (app/Models/Invoice.php, coloană enum
        // în schemă — cele 5 valori sunt singurele posibile, migrația `create_invoices_table`).
        'status' => [
            'draft' => 'Draft',
            'sent' => 'Sent',
            'paid' => 'Paid',
            'overdue' => 'Overdue',
            'void' => 'Void',
        ],
    ],

];
