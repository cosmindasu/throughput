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
 *
 * NU `final` (code review P2) — `GenerateShippingLabelJobTest` are nevoie să substituie
 * `resolve()` cu un carrier de test care aruncă `ShippingLabelFailed`/o excepție
 * neașteptată, ca să acopere ambele ramuri ale contractului de eroare fără să existe deja
 * un al doilea adaptor real (`ShippoCarrier` vine în Faza 5).
 */
class CarrierResolver
{
    public function resolve(): ShippingCarrier
    {
        return match ($this->activeProvider()) {
            'shippo' => throw new RuntimeException(
                'The Shippo carrier adapter ships in Faza 5 (ADR-010) — this tenant has it configured but no adapter exists yet.'
            ),
            default => new DemoShippingCarrier,
        };
    }

    /**
     * Faza 3, valul 2 (onorare/expediere) — `App\Actions\Shipments\CreateShipmentAction`
     * are nevoie de identificatorul de furnizor (string, salvat pe `shipments.carrier`,
     * exact convenția deja folosită de `StockAndOrdersSeeder`), nu de instanța
     * `ShippingCarrier`. Extras din `resolve()` ca ambele să citească aceeași sursă —
     * un rând `tenant_carrier_settings` cu `is_active = true`, implicit `demo`.
     */
    public function activeProvider(): string
    {
        return TenantCarrierSetting::query()->where('is_active', true)->value('provider') ?? 'demo';
    }
}
