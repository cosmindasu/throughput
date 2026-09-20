<?php

/**
 * Etichetele AFISATE ale enum-urilor de business — NICIODATA valorile stocate in DB
 * (`draft`, `confirmed`, `partially_fulfilled`, `fulfilled`, `cancelled`). Acelea raman
 * chei tehnice, in engleza, neschimbate de locale: apar in interogari SQL, in migratii, in
 * teste care compara direct enum-ul (`OrderStatus::Confirmed`), nu in text afisat.
 *
 * Doar ce vede utilizatorul trece prin acest catalog — `App\Enums\OrderStatus::label()`
 * e singurul apelant (ADR-022, plan-implementare.md „Lot I18N" Val 2).
 */
return [

    'order_status' => [
        'draft' => 'Draft',
        'confirmed' => 'Confirmed',
        'partially_fulfilled' => 'Partially fulfilled',
        'fulfilled' => 'Fulfilled',
        'cancelled' => 'Cancelled',
    ],

];
