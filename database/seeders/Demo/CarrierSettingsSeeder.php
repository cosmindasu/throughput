<?php

namespace Database\Seeders\Demo;

use App\Models\Tenant;
use App\Models\TenantCarrierSetting;
use Database\Factories\TenantCarrierSettingFactory;

/**
 * `tenant_carrier_settings` (ADR-010): Marlin → `demo` (fără credențiale, deci fluxul de
 * onorare merge fără dependență externă), Cascade și Northgate → `shippo`, cu credențiale
 * din `config('throughput.demo.shippo_sandbox_key')` (pot fi goale local).
 *
 * Northgate primea `easypost` până la 2026-09-12, când furnizorul a fost scos: EasyPost
 * condiționează accesul la chei, inclusiv cele de test, de un abonament lunar — supapa
 * pre-autorizată de ADR-010 a fost trasă. Cele două rânduri de `shippo` rămân utile: arată
 * că furnizorul se configurează **per tenant**, cu credențiale proprii, care e chiar ideea
 * deciziei; nu arată două integrări diferite, ceea ce e pierderea asumată.
 *
 * Scris prin Eloquent `create()`, NICIODATĂ prin `insert()` în bloc: `credentials` are
 * cast `encrypted:array` — criptarea trece prin encrypter-ul Laravel, invizibil pentru
 * un INSERT brut prin query builder.
 */
final class CarrierSettingsSeeder
{
    public function run(Tenant $tenant, array $config): void
    {
        $credentials = match ($config['carrier']) {
            'shippo' => array_filter(['api_key' => (string) env('SHIPPO_SANDBOX_KEY')]),
            default => [],
        };

        $row = (new TenantCarrierSettingFactory)->definition();
        $row['provider'] = $config['carrier'];
        $row['credentials'] = $credentials;
        $row['is_active'] = true;

        TenantCarrierSetting::create($row);
    }
}
