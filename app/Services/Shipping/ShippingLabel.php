<?php

namespace App\Services\Shipping;

/**
 * Rezultatul `ShippingCarrier::createLabel()` — specs.md §11.5 („createLabel(shipment):
 * LabelResult"). `readonly`, ca orice altă valoare imutabilă din `app/DTOs`-style al
 * proiectului (ex: nicio mutare posibilă după ce jobul de etichetă a citit rezultatul).
 */
final readonly class ShippingLabel
{
    public function __construct(
        public string $trackingNumber,
        public string $labelUrl,
        public ?float $cost = null,
    ) {}
}
