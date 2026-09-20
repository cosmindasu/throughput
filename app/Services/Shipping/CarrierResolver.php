<?php

namespace App\Services\Shipping;

use App\Models\TenantCarrierSetting;
use RuntimeException;

/**
 * Selecția per tenant (ADR-010, §11.5) — un rând `tenant_carrier_settings` cu
 * `is_active = true` (BR-ORD-03: exact unul per tenant, aplicat la scriere de
 * `App\Actions\Shipping\ActivateCarrierAction`, Settings → Shipping/FR-ORD-06, nu aici).
 * Fără niciun rând activ (tenant nou, încă neconfigurat, sau tenantul public), implicitul
 * e `DemoShippingCarrier` (§11.5 — „implicit pentru tenanții publici").
 *
 * Faza 5 — `shippo` are acum adaptor (`ShippoCarrier`). Un rând `provider = shippo` fără
 * `credentials.api_key` rămâne o stare VALIDĂ în bază (ecranul de Settings o poate salva
 * temporar, înainte de a adăuga o cheie), dar tot nu trebuie să degradeze tăcut la Demo:
 * `resolveShippo()` aruncă o eroare de CONFIGURARE explicită, înainte de orice apel extern
 * — exact tratamentul pe care `GenerateShippingLabelJob` îl aplică deja unei rezoluții
 * eșuate (mesaj generic pe shipment, niciun apel de curierat încercat).
 *
 * NU `final` (code review P2) — `GenerateShippingLabelJobTest` are nevoie să substituie
 * `resolve()` cu un carrier de test care aruncă `ShippingLabelFailed`/o excepție
 * neașteptată, ca să acopere ambele ramuri ale contractului de eroare fără să depindă de
 * ce e configurat efectiv pentru tenant.
 */
class CarrierResolver
{
    public function resolve(): ShippingCarrier
    {
        $setting = $this->activeSetting();

        return match ($setting?->provider ?? 'demo') {
            'shippo' => $this->resolveShippo($setting),
            default => new DemoShippingCarrier,
        };
    }

    /**
     * Faza 3, valul 2 (onorare/expediere) — `App\Actions\Shipments\CreateShipmentAction`
     * are nevoie de identificatorul de furnizor (string, salvat pe `shipments.carrier`,
     * exact convenția deja folosită de `StockAndOrdersSeeder`), nu de instanța
     * `ShippingCarrier`. Citește aceeași sursă ca `resolve()` — un rând
     * `tenant_carrier_settings` cu `is_active = true`, implicit `demo`.
     */
    public function activeProvider(): string
    {
        return $this->activeSetting()?->provider ?? 'demo';
    }

    private function activeSetting(): ?TenantCarrierSetting
    {
        return TenantCarrierSetting::query()->where('is_active', true)->first();
    }

    /**
     * O cheie lipsă e mereu o problemă de CONFIGURARE (ecranul de Settings ar fi trebuit
     * s-o ceară — `App\Actions\Shipping\ActivateCarrierAction`), niciodată un
     * `ShippingLabelFailed` raportat de furnizor: nu s-a făcut încă niciun apel către
     * Shippo la acest punct.
     */
    private function resolveShippo(TenantCarrierSetting $setting): ShippingCarrier
    {
        $apiKey = $setting->credentials['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException(
                'Shippo is configured for this tenant but has no API key yet — add one in Settings → Shipping.'
            );
        }

        return new ShippoCarrier($setting->credentials);
    }
}
