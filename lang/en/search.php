<?php

/**
 * FR-SEARCH-01, FR-I18N-04, Lotul I18N Val 5 — textul randat de
 * `App\Services\Search\GlobalSearchService`, în două familii:
 *
 *   - `groups.*` — etichetele de GRUP din dropdown-ul căutării globale (Cmd+K):
 *     „Recent"/„Actions"/„Accounts"/„Contacts"/„Deals" (`initialState()`/`search()`).
 *     Prima trecere a Valului 5 le lăsase pe toate literale — semnalate, nu reparate;
 *     a doua trecere le-a mutat aici;
 *   - `actions.*` — etichetele acțiunilor rapide de creare, din `frequentActions()`/
 *     `searchActions()`, INCLUSIV varianta cu termenul tipărit
 *     (`create_account_named`, ex. „Create account named "Acme"") — a treia eroare
 *     găsită de prima trecere, aceeași notă.
 *
 * `Accounts`/`Contacts`/`Deals` reiau EXACT formularea deja folosită în navigația
 * principală (`resources/js/locales/{en,fr}/common.json`, `nav.accounts`/`nav.contacts`/
 * `nav.deals`) — un evaluator care vede „Comptes" în bara laterală și „Accounts" în
 * dropdown-ul de căutare, pe același ecran, ar vedea două cuvinte pentru același obiect.
 */
return [

    'groups' => [
        'recent' => 'Recent',
        'actions' => 'Actions',
        'accounts' => 'Accounts',
        'contacts' => 'Contacts',
        'deals' => 'Deals',
    ],

    'actions' => [
        'create_account' => 'Create account',
        'create_account_named' => 'Create account named ":term"',
        'create_contact' => 'Create contact',
        'create_deal' => 'Create deal',
    ],

];
