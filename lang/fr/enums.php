<?php

/**
 * Traducere asistent, de revizuit de proprietar inainte de public (ADR-022, „Cost, stated
 * honestly"). DE VERIFICAT — acordul de gen e pe „la commande" (feminin): Confirmee/Honoree/
 * Annulee, forme feminine, nu cele masculine (Confirme/Honore/Annule) — verificare de
 * vorbitor nativ recomandata explicit aici.
 *
 * Oglinda exacta, cheie cu cheie, a `lang/en/enums.php` — `php artisan i18n:coverage` pica
 * daca vreo cheie lipseste aici sau e orfana (FR-I18N-02).
 */
return [

    'order_status' => [
        'draft' => 'Brouillon',
        'confirmed' => 'Confirmée',
        'partially_fulfilled' => 'Partiellement honorée',
        'fulfilled' => 'Honorée',
        'cancelled' => 'Annulée',
    ],

    /**
     * Perechea franceza a lui `lang/en/enums.php` — vezi acolo pentru context.
     *
     * „Read" devine « Lecture des … » si „Create" devine « Creation de … »: substantive,
     * nu imperative, fiindca sirurile descriu o CAPACITATE a jetonului, nu o actiune pe
     * care o face cititorul. Engleza e ambigua intre cele doua; franceza nu poate fi.
     */
    'api_abilities' => [
        'accounts:read' => 'Lecture des comptes',
        'contacts:read' => 'Lecture des contacts',
        'contacts:write' => 'Création de contacts',
        'deals:read' => 'Lecture des affaires',
        'deals:write' => 'Création d’affaires',
        'orders:read' => 'Lecture des commandes',
        'orders:write' => 'Création de commandes',
        'invoices:read' => 'Lecture des factures',
        'invoices:write' => 'Création de factures',
        'inventory:read' => 'Lecture des niveaux et mouvements de stock',
        'inventory:write' => 'Enregistrement de mouvements de stock',
    ],

    /**
     * ACORD DE GEN — le nom devant `:name` est celui d'une personne de genre inconnu.
     * Forme choisie : « (désactivé) », masculin par défaut, SANS marque « (e) ». Diffère
     * délibérément de `lang/fr/mail.php` (`members.deactivated` → « a été désactivé(e) »),
     * qui marque les deux genres pour une PHRASE complète. Ici, le texte d'aide déjà publié
     * (`resources/js/locales/fr/help.json`, clés `members.details.rules` et
     * `unassigned.rules`, 3 occurrences) promet explicitement « (désactivé) » à l'utilisateur
     * — reprendre ce libellé exact évite une INCOHÉRENCE entre l'aide et l'écran, ce qui
     * compte ici plus que l'accord de genre sur une étiquette courte entre parenthèses (pas
     * une phrase). DE VERIFICAT par le propriétaire (ADR-022) si cet arbitrage doit changer.
     */
    'membership' => [
        'deactivated_name' => ':name (désactivé)',
    ],

    // Formulation IDENTIQUE à `resources/js/locales/fr/invoices.json`
    // (`show.payments.methodOptions`) — le dropdown du formulaire et la liste des paiements
    // affichent désormais le MÊME texte sur le même écran (Val 5, corrige la divergence).
    'payment_method' => [
        'bank_transfer' => 'Virement bancaire',
        'check' => 'Chèque',
        'manual' => 'Manuel',
    ],

];
