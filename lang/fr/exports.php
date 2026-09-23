<?php

declare(strict_types=1);

/**
 * Perechea franceză a lui `lang/en/exports.php` — citește docblock-ul de acolo pentru
 * cuplajul cu importul, care e regula care constrânge alegerile de mai jos.
 *
 * Termenii marcați „↔ alias" sunt cei care TREBUIE să rămână potrivibili cu aliasurile
 * franceze din `app/Support/Imports/Resources/*ImportResource.php`, după normalizarea
 * din `ColumnMappingSuggester` (minuscule, fără diacritice, fără separatori). Nu-i
 * schimba fără să rulezi `tests/Feature/Imports/ExportHeaderRoundTripTest.php` —
 * un sinonim mai elegant care nu e în lista de aliasuri rupe reimportul tăcut, fără
 * să pice nimic altceva.
 *
 * ⚠ NATIV — de verificat de un vorbitor nativ, fiind terminologie comercială și
 * contabilă: „Conditions de paiement" (credit terms), „Total TTC" (grand total —
 * ales pentru că include taxele, spre deosebire de „Total HT"), „Montant réglé"
 * (amount paid), „Solde dû" (balance due), „Refus marketing" (marketing opt-out —
 * formulare mai scurtă decât „Désinscription marketing", de cântărit care e mai clară
 * într-un antet de coloană).
 */
return [
    'accounts' => [
        'name' => 'Nom',                              // ↔ alias 'nom'
        'domain' => 'Domaine',                        // ↔ alias 'domaine'
        'industry' => 'Secteur',                      // ↔ alias 'secteur'
        'status' => 'Statut',
        'credit_terms' => 'Conditions de paiement',
        'owner' => 'Responsable',
        'created_at' => 'Créé le',
    ],

    'contacts' => [
        'first_name' => 'Prénom',                     // ↔ alias 'prénom' (normalizat: prnom)
        'last_name' => 'Nom',                         // ↔ alias 'nom'
        'email' => 'E-mail',                          // ↔ alias 'e-mail' (normalizat: email)
        'phone' => 'Téléphone',                       // ↔ alias 'téléphone' (normalizat: tlphone)
        'title' => 'Poste',                           // ↔ alias 'poste'
        'account' => 'Société',                       // ↔ alias 'société' (normalizat: socit)
        'primary_contact' => 'Contact principal',
        'marketing_opt_out' => 'Refus marketing',
        'created_at' => 'Créé le',
    ],

    'orders' => [
        'order_number' => 'Numéro de commande',
        'status' => 'Statut',
        'account' => 'Société',
        'owner' => 'Responsable',
        'grand_total' => 'Total TTC',
        'currency' => 'Devise',
        'placed_at' => 'Passée le',
        'created_at' => 'Créée le',
    ],

    'invoices' => [
        'invoice_number' => 'Numéro de facture',
        'status' => 'Statut',
        'account' => 'Société',
        'order' => 'Commande',
        'issue_date' => 'Date d’émission',
        'due_date' => 'Date d’échéance',
        'currency' => 'Devise',
        'total' => 'Total',
        'amount_paid' => 'Montant réglé',
        'balance_due' => 'Solde dû',
    ],

    // I18N-08/I18N-09 — perechea franceză a lui `lang/en/exports.php`. Ghilimele DREPTE
    // păstrate deliberat în jurul lui `:format` (vezi comentariul din
    // `ExportFormat::fromRequest()` — valoare de utilizator, FR-I18N-06, nu etichetă a
    // aplicației); `zip_not_supported` n-are niciun parametru, deci nicio ghilimea de ales.
    'errors' => [
        'unknown_format' => 'Format d’export inconnu ":format". Utilisez csv, pdf ou zip.',
        'zip_not_supported' => 'Cette liste ne peut pas être exportée sous forme d’archive zip. Utilisez csv à la place.',
    ],
];
