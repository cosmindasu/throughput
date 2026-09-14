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
 */
interface ShippingCarrier
{
    public function createLabel(Shipment $shipment): ShippingLabel;

    public function void(Shipment $shipment): void;

    public function trackingUrl(Shipment $shipment): string;
}
