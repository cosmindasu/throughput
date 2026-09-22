<?php

declare(strict_types=1);

/**
 * Perechea franceză a lui `lang/en/imports.php` — citește docblock-ul de acolo pentru rol.
 *
 * GARDA BR-I18N-01, aplicată la scriere, nu doar verificată la final: fiecare traducere de
 * mai jos a fost trecută prin `ColumnMappingSuggester::normalize()`
 * (`preg_replace('/[^a-z0-9]+/', '', strtolower($v))` — elimină diacriticele, „Prénom" →
 * `prnom`) și verificată să cadă pe un alias deja existent al câmpului respectiv, în
 * `app/Support/Imports/Resources/*ImportResource.php`. Nu s-a schimbat nicio traducere ca
 * să încapă pe un alias — toate cădeau deja, fiindcă aliasurile FR ale Valului 2 au fost
 * scrise anticipând exact aceste etichete.
 *
 * CONSECVENȚĂ CU EXPORTUL (BR-I18N-01, obligatorie funcțional — un export francez trebuie
 * să se reimporte automat): unde import și export numesc ACELAȘI concept, traducerea de aici
 * normalizează la același șir ca `lang/fr/exports.php`, sau alias-ul îl acoperă deja pe cel
 * diferit. Cazul „diferit, dar acoperit": `accounts.name` la import e „Company name" (nu
 * „Name" ca la export) — eticheta franceză aleasă mai jos, „Nom de l'entreprise", NU e
 * identică cu „Nom" de la export, dar normalizează pe alias-ul `"nom de l'entreprise"` deja
 * prezent pe câmp, iar „Nom" de la export normalizează separat pe alias-ul `'nom'` — ambele
 * căi de reimport funcționează, fără să depindă una de cealaltă. Simetric la `contacts.last_
 * name`, unde eticheta de import ȘI cea de export coincid deja („Nom").
 *
 * SKU rămâne des netradus în franceza de comerț/logistică (vezi comentariul din
 * `VariantImportResource::fields()`) — „Référence" e echivalentul ales pentru etichetă,
 * cu „SKU" păstrat explicit ca alias.
 */
return [
    'resources' => [
        'accounts' => 'Comptes',
        'contacts' => 'Contacts',
        'products' => 'Produits',
        'variants' => 'Produits/Variantes',
    ],

    'fields' => [
        'accounts' => [
            // Apostrof TIPOGRAFIC (U+2019), nu cel drept: e convenția franceză a proiectului
            // (toate cele 16 cataloage `resources/js/locales/fr/*.json` și `lang/fr/flash.php`
            // îl folosesc), iar eticheta asta se randează pe `Imports/Show` chiar lângă text
            // venit din catalogul JS — două stiluri de apostrof pe același ecran ar fi exact
            // detaliul pe care §1.5 îl are de pierdut. Fără efect asupra potrivirii:
            // `ColumnMappingSuggester::normalize()` scoate oricum orice nu e `[a-z0-9]`, deci
            // ambele forme cad pe același alias (`nomdelentreprise`).
            'name' => 'Nom de l’entreprise',        // ↔ alias "nom de l'entreprise" (normalizat: nomdelentreprise)
            'domain' => 'Domaine',                    // ↔ alias 'domaine'
            'industry' => 'Secteur',                  // ↔ alias 'secteur'
            'phone' => 'Téléphone',                   // ↔ alias 'téléphone' (normalizat: tlphone)
            'source' => 'Source',                     // ↔ alias 'source' (identic EN/FR)
        ],

        'contacts' => [
            'first_name' => 'Prénom',                 // ↔ alias 'prénom' (normalizat: prnom)
            'last_name' => 'Nom',                      // ↔ alias 'nom'
            'email' => 'E-mail',                       // ↔ alias 'e-mail' (normalizat: email)
            'phone' => 'Téléphone',                    // ↔ alias 'téléphone'
            'title' => 'Poste',                         // ↔ alias 'poste'
            'account_name' => 'Société',                // ↔ alias 'société' (normalizat: socit)
        ],

        'products' => [
            'name' => 'Nom du produit',                  // ↔ alias 'nom du produit'
            'category' => 'Catégorie',                   // ↔ alias 'catégorie'
            'unit_of_measure' => 'Unité de mesure',       // ↔ alias 'unité de mesure'
        ],

        'variants' => [
            'sku' => 'Référence',                          // ↔ alias 'référence'
            'product_name' => 'Nom du produit',            // ↔ alias 'nom du produit'
            'category' => 'Catégorie',                     // ↔ alias 'catégorie'
            'unit_of_measure' => 'Unité de mesure',         // ↔ alias 'unité de mesure'
            'price' => 'Prix',                              // ↔ alias 'prix'
            'cost' => 'Coût',                                // ↔ alias 'coût' (normalizat: cot)
            'weight' => 'Poids',                             // ↔ alias 'poids'
        ],
    ],

    /*
     * Ghilimele franceze « », nu cele drepte din engleză: `:field` e o etichetă a APLICAȚIEI,
     * nu conținut scris de utilizator. Distincția contează — `lang/fr/mail.php` păstrează
     * deliberat ghilimelele drepte la `report_delivery.body`, fiindcă acolo ce se citează e
     * numele unui raport introdus de utilizator (FR-I18N-06: nu se retraduce ȘI nu se
     * reformatează).
     *
     * Fraza e ancorată pe „Le champ" ca acordul să nu depindă de eticheta interpolată:
     * genul etichetelor variază („Nom" masculin, „Référence" feminin), iar „associé" acordă
     * cu „champ", invariabil. Aceeași capcană ca la `entries.*` din `lang/fr/activity.php`,
     * rezolvată la fel — prin formulare, nu prin alegerea arbitrară a unui gen.
     */
    'validation' => [
        'required_field_unmapped' => 'Le champ « :field » est obligatoire pour :resource et doit être associé à une colonne.',
    ],
];
