<?php

/**
 * Traducere franceză — vezi `lang/en/search.php` pentru context.
 *
 * `groups.accounts`/`groups.contacts`/`groups.deals` NU sunt retraduse aici: reiau
 * literal „Comptes"/„Contacts"/„Affaires" din `resources/js/locales/fr/common.json`
 * (`nav.accounts`/`nav.contacts`/`nav.deals`), ca să nu apară o a doua formulare pentru
 * același obiect pe ecrane vecine (problemă reală, deja întâlnită în acest lot la
 * „deal"/„affaire" — vezi `lang/fr/rules.php`). „Deal" → „affaire", decizia
 * proprietarului (2026-09-21).
 */
return [

    'groups' => [
        'recent' => 'Récent',
        'actions' => 'Actions',
        'accounts' => 'Comptes',
        'contacts' => 'Contacts',
        'deals' => 'Affaires',
    ],

    'actions' => [
        'create_account' => 'Créer un compte',
        'create_account_named' => 'Créer un compte nommé ":term"',
        'create_contact' => 'Créer un contact',
        'create_deal' => 'Créer une affaire',
    ],

];
