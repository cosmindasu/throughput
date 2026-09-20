<?php

namespace App\Services\Shipping;

/**
 * Sursă unică pentru metadatele furnizorilor pe care Settings → Shipping le arată —
 * simetric cu `App\Support\Permissions::catalog()` pentru RBAC. Valorile `array_keys()`
 * ale lui `all()` trebuie să rămână exact enumul `provider` din migrația
 * `tenant_carrier_settings` (`shippo`, `demo`) — un al treilea furnizor (dacă apare
 * vreodată, ADR-010) se adaugă AICI + un adaptor + o valoare de enum, nu presărat prin
 * ecranul de Settings.
 */
final class CarrierCatalog
{
    public const DEMO = 'demo';

    public const SHIPPO = 'shippo';

    /**
     * @return array<string, array{label: string, description: string, requiresApiKey: bool}>
     */
    public static function all(): array
    {
        return [
            self::DEMO => [
                'label' => 'Demo',
                'description' => 'No external calls. Every label is a plausible PDF with a fake tracking number, generated instantly — the default for tenants that have not configured a real carrier yet.',
                'requiresApiKey' => false,
            ],
            self::SHIPPO => [
                'label' => 'Shippo',
                'description' => 'Real sandbox integration. Every label is purchased through Shippo\'s test API — valid in shape, no real shipment and no cost.',
                'requiresApiKey' => true,
            ],
        ];
    }
}
