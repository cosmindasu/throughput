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

    /**
     * Descrierile scope-urilor de jeton API (US-API-01), afisate ca text sub codul
     * scope-ului pe `Settings/ApiTokens/Index`. Cheia stocata pe `api_tokens.abilities`
     * ramane identificatorul tehnic (`orders:read`) — neschimbat de locale, exact ca
     * valorile de `order_status` de mai sus: apare in `EnsureTokenAbility`, in payload-ul
     * jetonului si in testele de API.
     *
     * Gasite abia la Valul 4 al Lotului I18N, scrise direct in
     * `ApiToken::abilityCatalog()`: 11 siruri engleze randate pe o interfata altfel
     * franceza. Val 2 se uitase la mesajele flash si la validari, nu la un catalog
     * static de pe un model — iar niciun test nu putea vedea diferenta, fiindca nimic
     * nu cerea acelui catalog sa treaca prin `trans()`.
     */
    'api_abilities' => [
        'accounts:read' => 'Read accounts',
        'contacts:read' => 'Read contacts',
        'contacts:write' => 'Create contacts',
        'deals:read' => 'Read deals',
        'deals:write' => 'Create deals',
        'orders:read' => 'Read orders',
        'orders:write' => 'Create orders',
        'invoices:read' => 'Read invoices',
        'invoices:write' => 'Create invoices',
        'inventory:read' => 'Read stock levels and movements',
        'inventory:write' => 'Record stock movements',
    ],

];
