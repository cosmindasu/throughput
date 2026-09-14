<?php

namespace App\Services\Shipping;

use App\Models\Shipment;
use Illuminate\Support\Str;

/**
 * ADR-010 — a treia implementare, PERMANENTĂ, nu un mock: fără niciun apel extern
 * (FR-ORD-01), implicită pentru tenantul public, plasă de siguranță dacă un sandbox
 * Shippo cade. Formatul tracking number/label URL urmează exact convenția deja folosită
 * de `StockAndOrdersSeeder` la semănarea shipment-urilor deja „expediate", ca un
 * shipment demo creat prin acțiune și unul semănat să arate identic.
 */
final class DemoShippingCarrier implements ShippingCarrier
{
    public function createLabel(Shipment $shipment): ShippingLabel
    {
        $trackingNumber = strtoupper(Str::random(2)).random_int(100000000, 999999999);

        return new ShippingLabel(
            trackingNumber: $trackingNumber,
            labelUrl: "https://storage.demo.throughput.dev/labels/{$shipment->getKey()}.pdf",
            cost: null,
        );
    }

    /**
     * Nimic de anulat în afara aplicației: eticheta „demo" n-a existat niciodată la
     * un furnizor real, deci nu există niciun apel de void de făcut. Metoda există
     * ca implementare a interfeței, nu ca no-op accidental — un revizor tehnic care
     * citește doar `ShippingCarrier` nu trebuie să ghicească asta.
     */
    public function void(Shipment $shipment): void
    {
        // Intenționat gol — vezi docblock-ul metodei.
    }

    public function trackingUrl(Shipment $shipment): string
    {
        return "https://track.demo.throughput.dev/{$shipment->tracking_number}";
    }
}
