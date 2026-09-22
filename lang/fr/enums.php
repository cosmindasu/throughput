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

];
