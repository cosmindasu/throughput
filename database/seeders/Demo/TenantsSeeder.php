<?php

namespace Database\Seeders\Demo;

use App\Models\Tenant;

/**
 * Cei 3 tenanți demo (specs.md §21.1). `tenants` nu are RLS — e chiar unitatea de scopare
 * (§19.1) — deci se creează în afara oricărui `TenantContext::run()`.
 */
final class TenantsSeeder
{
    /**
     * @param  array<string, array<string, mixed>>  $configs  slug => config
     * @return array<string, Tenant> slug => Tenant
     */
    public function run(array $configs): array
    {
        $tenants = [];

        foreach ($configs as $slug => $config) {
            $tenants[$slug] = Tenant::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $config['name'],
                    'industry' => $config['industry'],
                    'currency' => 'USD',
                ]
            );
        }

        return $tenants;
    }
}
