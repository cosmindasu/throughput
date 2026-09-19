<?php

namespace App\Services\Shipping;

use RuntimeException;

/**
 * Code review P2 (Faza 3, valul 2) — contractul de eroare al `ShippingCarrier::createLabel()`.
 *
 * SINGURA excepție al cărei `getMessage()` e sigur de scris direct pe
 * `shipments.error_message`, vizibil oricui vede comanda (inclusiv Viewer-ului, R pe
 * onorare/expediere — §7.4): motivul SPECIFIC raportat de furnizor (adresă invalidă,
 * cântar peste limită, serviciu indisponibil) — exact ce cere US-ORD-03 („mesajul
 * specific al furnizorului, nu Something went wrong").
 *
 * Orice ALTĂ `Throwable` scăpată dintr-un adaptor (eroare de rețea, credențiale expirate,
 * un bug intern) e tratată de apelant (`GenerateShippingLabelJob`) ca eroare INTERNĂ: un
 * mesaj generic se scrie pe shipment, detaliile ajung doar în log (`report()`) — un
 * asemenea mesaj brut poate conține adrese, stive de apel sau alte detalii care n-au ce
 * căuta pe un ecran vizibil oricărui rol.
 */
final class ShippingLabelFailed extends RuntimeException {}
