<?php

namespace App\Services\Shipping;

use App\Models\Shipment;

/**
 * ADR-010 / specs.md §11.5 — o interfață, mai multe implementări selectabile PER
 * TENANT (`tenant_carrier_settings`). Faza asta scrie interfața și `DemoShippingCarrier`
 * ca a treia implementare PERMANENTĂ (nu un mock aruncabil): `ShippoCarrier` vine în
 * Faza 5 fără să schimbe această interfață sau vreun apelant (§11.5, „restul aplicației
 * rămâne neschimbat față de care implementare e activă").
 *
 * ADR-013 — orice implementare reală (Shippo) face un apel extern, deci orice apelant
 * al acestei interfețe trebuie să ruleze într-o coadă, niciodată în cererea HTTP.
 * `DemoShippingCarrier` nu are această constrângere (n-are niciun apel extern), dar
 * interfața nu poate presupune asta pentru toate implementările.
 *
 * Contractul de eroare al `createLabel()` (code review P2, US-ORD-03): un eșec RAPORTAT
 * de furnizor (adresă invalidă, serviciu indisponibil, cântar peste limită) se aruncă
 * exclusiv ca `ShippingLabelFailed`, cu mesajul EXACT primit de la furnizor — apelantul
 * (`GenerateShippingLabelJob`) îl scrie direct pe `shipments.error_message`, vizibil
 * oricărui rol care vede comanda. Orice altă excepție (rețea, credențiale, un bug intern
 * al adaptorului) rămâne tipul ei natural — apelantul o tratează ca eroare INTERNĂ,
 * salvează un mesaj generic pe shipment și loghează detaliile complete separat.
 */
interface ShippingCarrier
{
    public function createLabel(Shipment $shipment): ShippingLabel;

    public function void(Shipment $shipment): void;

    public function trackingUrl(Shipment $shipment): string;
}
