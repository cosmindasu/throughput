<?php

/**
 * Perechea franceză a lui `lang/en/forms.php` — citește docblock-ul de acolo pentru cum se
 * delimitează cele trei fișiere de validare (`validation.php` / `rules.php` / `forms.php`).
 *
 * Toate mesajele de aici sunt la IMPERATIV („Saisissez", „Choisissez", „Expliquez"), ca în
 * engleză: sunt instrucțiuni adresate utilizatorului, nu constatări despre câmp — exact
 * diferența pentru care aceste suprascrieri există față de mesajele generice din
 * `validation.php`, care sunt la indicativ („Le champ :attribute est obligatoire.").
 */

return [

    'members' => [
        'role_in' => 'Choisissez l’un des quatre rôles de l’espace de travail.',

        'invite' => [
            'email_required' => 'Saisissez l’adresse e-mail à inviter.',
            'email_email' => 'Saisissez une adresse e-mail valide.',
            'role_required' => 'Choisissez un rôle pour le nouveau membre.',
        ],

        'update_role' => [
            'role_required' => 'Choisissez un rôle.',
        ],
    ],

    'stock' => [
        'adjust' => [
            'delta_not_in' => 'L’ajustement doit modifier la quantité d’au moins 1.',
            'note_required' => 'Expliquez pourquoi vous corrigez cette quantité.',
        ],
    ],

    'settings' => [
        'carrier' => [
            // Identic, în franceză, cu `rules.shipping.sandbox_key_only` — deliberat: cele
            // două engleze diferă printr-o prepoziție („in"/„on this deployment"), o
            // distincție care n-are ce traduce. Vezi nota din `lang/en/forms.php`.
            'api_key_regex' => 'Seules les clés sandbox Shippo (shippo_test_...) sont acceptées sur ce déploiement — jamais une clé live.',
        ],
    ],

];
