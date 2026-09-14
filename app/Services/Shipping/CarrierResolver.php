<?php

namespace App\Services\Shipping;

use App\Models\TenantCarrierSetting;
use RuntimeException;

/**
 * Selecția per tenant (ADR-010, §11.5) — un rând `tenant_carrier_settings` cu
 * `is_active = true` (BR-ORD-03: exact unul per tenant, aplicat la scriere de ecranul
 * de Settings → Shipping, Faza 5/FR-ORD-06, nu aici). Fără niciun rând activ (tenant
 * nou, încă neconfigurat, sau tenantul public), implicitul e `DemoShippingCarrier`
 * (§11.5 — „implicit pentru tenanții publici").
 *
 * `shippo` nu are încă adaptor (Faza 5, ADR-010 — supapa EasyPost trasă la
 * 2026-09-12): o setare `provider = shippo` fără implementare concretă e o stare
 * validă în baza de date (ecranul de Settings o poate salva), dar necesită o eroare
 * explicită dacă cineva încearcă s-o FOLOSEASCĂ înainte ca adaptorul să existe —
 * nu o degradare tăcută la Demo, care ar ascunde o configurare greșită.
 */
final class CarrierResolver
{
    public function resolve(): ShippingCarrier
    {
        $active = TenantCarrierSetting::query()->where('is_active', true)->first();

        return match ($active?->provider) {
            null, 'demo' => new DemoShippingCarrier,
            'shippo' => throw new RuntimeException(
                'The Shippo carrier adapter ships in Faza 5 (ADR-010) — this tenant has it configured but no adapter exists yet.'
            ),
            default => new DemoShippingCarrier,
        };
    }
}
