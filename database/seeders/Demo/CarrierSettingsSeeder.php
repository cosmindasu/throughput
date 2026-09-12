<?php

namespace Database\Seeders\Demo;

use App\Models\Tenant;
use App\Models\TenantCarrierSetting;
use Database\Factories\TenantCarrierSettingFactory;

/**
 * `tenant_carrier_settings` (ADR-010, task brief pct. f): Marlin → `demo` (fără
 * credențiale), Cascade → `shippo`, Northgate → `easypost`, ambele cu credențiale din
 * `SHIPPO_SANDBOX_KEY`/`EASYPOST_SANDBOX_KEY` (pot fi goale local).
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
            'easypost' => array_filter(['api_key' => (string) env('EASYPOST_SANDBOX_KEY')]),
            default => [],
        };

        $row = (new TenantCarrierSettingFactory)->definition();
        $row['provider'] = $config['carrier'];
        $row['credentials'] = $credentials;
        $row['is_active'] = true;

        TenantCarrierSetting::create($row);
    }
}
