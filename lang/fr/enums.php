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

];
