<?php

namespace Tests\Fixtures\Shipping;

use App\Models\Shipment;
use App\Services\Shipping\ShippingCarrier;
use App\Services\Shipping\ShippingLabel;
use RuntimeException;

/**
 * Simulează un eșec INTERN al adaptorului (conexiune resetată, credențiale expirate) —
 * orice altă `Throwable` decât `ShippingLabelFailed` din contractul `ShippingCarrier`
 * (code review P2). Mesajul acestei excepții nu ar trebui să ajungă niciodată pe
 * `shipments.error_message`.
 */
final class CarrierThatFailsUnexpectedly implements ShippingCarrier
{
    public const MESSAGE = 'Connection reset by peer while talking to the carrier sandbox.';

    public function createLabel(Shipment $shipment): ShippingLabel
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function void(Shipment $shipment): void {}

    public function trackingUrl(Shipment $shipment): string
    {
        return '';
    }
}
