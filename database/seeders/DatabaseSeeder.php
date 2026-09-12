<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * NU folosește `WithoutModelEvents`: `DemoDatasetSeeder` are nevoie de evenimentele
 * Eloquent pornite (BelongsToTenant completează `tenant_id`, HasUlids generează `id`)
 * pentru toate entitățile persistate prin `create()` — dezactivarea lor global ar lăsa
 * acele rânduri fără tenant/id.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoDatasetSeeder::class);
    }
}
