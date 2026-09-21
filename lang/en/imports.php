<?php

declare(strict_types=1);

/**
 * Etichetele ecranului de import (§14, §15.8): numele resursei (`resources` — selectul de
 * pe Imports/Create, coloana Resource din Imports/Index) și eticheta umană a fiecărui câmp
 * țintă (`fields` — maparea de coloane pe Imports/Show ȘI antetul template-ului CSV
 * descărcabil, `ImportTemplateBuilder`).
 *
 * Sursă unică pentru `ImportField->labelKey` (`app/Support/Imports/ImportField.php`) —
 * fiecare `ImportField` din `app/Support/Imports/Resources/*.php` poartă cheia de aici, nu
 * mai literalul englez direct. Valorile engleze de mai jos sunt copii IDENTICE, caracter cu
 * caracter, ale literalelor care existau înainte de extragere — nicio reformulare, ca
 * engleza vizibilă să nu se schimbe (regula lotului).
 *
 * CUPLAJ CU EXPORTUL (BR-I18N-01) — vezi docblock-ul din `lang/fr/imports.php` pentru
 * detaliul normalizării; traducerea franceză de-aici e verificată să cadă pe aceleași
 * aliasuri de potrivire ca `lang/fr/exports.php`, acolo unde import și export numesc
 * același concept.
 */
return [
    'resources' => [
        'accounts' => 'Accounts',
        'contacts' => 'Contacts',
        'products' => 'Products',
        'variants' => 'Products/Variants',
    ],

    'fields' => [
        'accounts' => [
            'name' => 'Company name',
            'domain' => 'Domain',
            'industry' => 'Industry',
            'phone' => 'Phone',
            'source' => 'Source',
        ],

        'contacts' => [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'email' => 'Email',
            'phone' => 'Phone',
            'title' => 'Job title',
            'account_name' => 'Company',
        ],

        'products' => [
            'name' => 'Product name',
            'category' => 'Category',
            'unit_of_measure' => 'Unit of measure',
        ],

        'variants' => [
            'sku' => 'SKU',
            'product_name' => 'Product name',
            'category' => 'Category',
            'unit_of_measure' => 'Unit of measure',
            'price' => 'Price',
            'cost' => 'Cost',
            'weight' => 'Weight',
        ],
    ],

    /*
     * `UpdateImportMappingRequest::withValidator()` — FR-I18N-04 („mesaje de validare").
     * Mutată aici odată cu etichetele, nu separat: fraza INTERPOLEAZĂ două etichete care
     * tocmai au devenit traductibile, deci lăsată ca literal englez producea o propoziție
     * engleză cu două cuvinte franceze în mijloc — mai rău decât engleza curată dinainte.
     *
     * `:field` și `:resource` sunt etichete generate de APLICAȚIE (chiar cheile de mai sus),
     * nu conținut de utilizator — interpolarea lor nu atinge granița FR-I18N-06.
     *
     * Textul englez e neschimbat față de literalul dinainte, inclusiv ghilimelele drepte:
     * `e2e/specs/imports.spec.ts:131` îl caută verbatim.
     */
    'validation' => [
        'required_field_unmapped' => '":field" is required for :resource and must be mapped to a column.',
    ],
];
