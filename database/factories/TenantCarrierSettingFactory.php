<?php

namespace Database\Factories;

use App\Models\TenantCarrierSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantCarrierSetting>
 *
 * Persistat mereu prin Eloquent `create()` (niciodată `insert()` în bloc): `credentials`
 * are cast `encrypted:array` (ADR-010) — criptarea trece prin encrypter-ul Laravel, care
 * nu se declanșează pe o inserare brută prin query builder.
 */
class TenantCarrierSettingFactory extends Factory
{
    protected $model = TenantCarrierSetting::class;

    public function definition(): array
    {
        return [
            'provider' => 'demo',
            'credentials' => [],
            'is_active' => true,
        ];
    }

    public function shippo(?string $apiKey): static
    {
        return $this->state(fn () => [
            'provider' => 'shippo',
            'credentials' => $apiKey ? ['api_key' => $apiKey] : [],
        ]);
    }
}
