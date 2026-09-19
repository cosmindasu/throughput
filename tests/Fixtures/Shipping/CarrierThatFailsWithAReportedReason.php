<?php

namespace Tests\Fixtures\Shipping;

use App\Models\Shipment;
use App\Services\Shipping\ShippingCarrier;
use App\Services\Shipping\ShippingLabel;
use App\Services\Shipping\ShippingLabelFailed;

/**
 * Simulează un furnizor real care REFUZĂ eticheta cu un motiv specific (adresă
 * invalidă) — `ShippingLabelFailed`, contractul din `ShippingCarrier` (code review P2).
 * `DemoShippingCarrier` nu eșuează niciodată (ADR-010) și `ShippoCarrier` nu există încă
 * (Faza 5), deci acesta e singurul mod de a acoperi ramura „mesaj sigur de arătat" azi.
 */
final class CarrierThatFailsWithAReportedReason implements ShippingCarrier
{
    public const MESSAGE = 'Destination address failed validation (ZIP+4 mismatch).';

    public function createLabel(Shipment $shipment): ShippingLabel
    {
        throw new ShippingLabelFailed(self::MESSAGE);
    }

    public function void(Shipment $shipment): void {}

    public function trackingUrl(Shipment $shipment): string
    {
        return '';
    }
}
