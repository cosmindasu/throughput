<?php

/**
 * Mesaje de validare SCRISE DE NOI pentru un formular anume — cele care suprascriu, prin
 * `FormRequest::messages()`, mesajul generic al framework-ului din `lang/{locale}/validation.php`
 * (ADR-022, specs.md §15.8 FR-I18N-04). Al treilea fișier dintr-o familie de trei, care se
 * delimitează astfel:
 *
 *   - `validation.php` — mesajele GENERICE ale framework-ului („The :attribute field is
 *     required."), publicate verbatim din `vendor/`, nu scrise de noi;
 *   - `rules.php`      — reguli de BUSINESS care depind de starea datelor (praguri,
 *     tranziții, ultimul Owner activ), aruncate din `app/Actions/**` și din Policy-uri;
 *   - `forms.php`      — ACEST fișier: suprascrieri per formular ale mesajelor generice,
 *     acolo unde „The email field is required." e corect dar inutil, iar „Enter the email
 *     address to invite." spune omului ce să facă.
 *
 * DE CE NU în `validation.php` sub `custom`: acolo cheia e `custom.<câmp>.<regulă>`, deci
 * GLOBALĂ pe numele câmpului. `email.required` înseamnă „adresa pe care o inviți" în
 * `InviteMemberRequest` și cu totul altceva în alt formular — o singură intrare globală
 * le-ar fi dat același text.
 *
 * Valorile engleze sunt copii IDENTICE, caracter cu caracter, ale literalelor care existau
 * în cele patru `FormRequest`-uri înainte de extragere. `php artisan i18n:coverage` verifică
 * simetria cu `lang/fr/forms.php`.
 */

return [

    'members' => [
        // O singură cheie pentru DOUĂ apelante (`InviteMemberRequest`,
        // `UpdateMemberRoleRequest`): aceeași regulă, același text — erau deja același
        // literal, duplicat în ambele fișiere.
        'role_in' => 'Choose one of the four workspace roles.',

        'invite' => [
            'email_required' => 'Enter the email address to invite.',
            'email_email' => 'Enter a valid email address.',
            'role_required' => 'Choose a role for the new member.',
        ],

        'update_role' => [
            'role_required' => 'Choose a role.',
        ],
    ],

    'stock' => [
        'adjust' => [
            'delta_not_in' => 'The adjustment must change the quantity by at least 1.',
            'note_required' => 'Explain why you are correcting this quantity.',
        ],
    ],

    'settings' => [
        'carrier' => [
            // Aproape geamăn cu `rules.shipping.sandbox_key_only`, dar NU identic: acolo
            // scrie „on this deployment", aici „in this deployment". Diferența de o
            // prepoziție e preexistentă; unificarea celor două ar SCHIMBA engleza vizibilă
            // pe una din cele două căi, deci e decizie de QA (Val 5), nu ceva de strecurat
            // într-o extragere. Franceza e aceeași la ambele — prepoziția engleză n-are ce
            // distincție să păstreze.
            'api_key_regex' => 'Only Shippo sandbox keys (shippo_test_...) are accepted in this deployment — never a live key.',
        ],
    ],

];
