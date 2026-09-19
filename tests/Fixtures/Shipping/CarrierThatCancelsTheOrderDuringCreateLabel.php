<?php

namespace Tests\Fixtures\Shipping;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipping\ShippingCarrier;
use App\Services\Shipping\ShippingLabel;
use App\Services\Tenancy\TenantContext;

/**
 * Simulează cursa pe care `GenerateShippingLabelJob` o apără la faza finală (code review
 * P1, apărare în adâncime): comanda iese din `confirmed`/`partially_fulfilled` CHIAR ÎN
 * TIMPUL apelului extern (aici, direct pe rând — `CancelOrderAction` normal ar refuza,
 * dat fiind fix-ul din `CancelOrderAction::execute()`, deci testul provoacă exact starea
 * pe care acel fix o previne, ca dovadă independentă a plasei de siguranță din job).
 * Returnează totuși o etichetă „reușită" — jobul trebuie s-o arunce, nu s-o scrie.
 */
final class CarrierThatCancelsTheOrderDuringCreateLabel implements ShippingCarrier
{
    public function __construct(private readonly string $tenantId) {}

    public function createLabel(Shipment $shipment): ShippingLabel
    {
        TenantContext::run($this->tenantId, function () use ($shipment): void {
            Order::query()->whereKey($shipment->order_id)->update(['status' => OrderStatus::Cancelled]);
        });

        return new ShippingLabel(trackingNumber: 'RACE123', labelUrl: 'https://storage.demo.throughput.dev/labels/race.pdf', cost: null);
    }

    public function void(Shipment $shipment): void {}

    public function trackingUrl(Shipment $shipment): string
    {
        return '';
    }
}
