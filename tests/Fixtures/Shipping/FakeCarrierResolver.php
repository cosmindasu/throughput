<?php

namespace Tests\Fixtures\Shipping;

use App\Services\Shipping\CarrierResolver;
use App\Services\Shipping\ShippingCarrier;

/**
 * Substituie `CarrierResolver::resolve()` cu un carrier fix, de test — `CarrierResolver`
 * NU e `final` exact pentru asta (code review P2, `GenerateShippingLabelJobTest`).
 * Legat în container (`$this->app->instance(CarrierResolver::class, ...)`), înainte de a
 * rula coada, ca `GenerateShippingLabelJob::handle(CarrierResolver $resolver)` să
 * primească acest substitut, nu `CarrierResolver` real.
 */
final class FakeCarrierResolver extends CarrierResolver
{
    public function __construct(private readonly ShippingCarrier $carrier) {}

    public function resolve(): ShippingCarrier
    {
        return $this->carrier;
    }
}
