<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDatasetSeeder;
use Illuminate\Console\Command;

/**
 * Plan §7.8 — rulează orchestratorul de seed la volum (3 tenanți, ~8.000 conturi,
 * ~50.000 comenzi, istoric pe 24 de luni — specs.md §21). Presupune o schemă proaspătă;
 * pentru un reset complet folosește `demo:reset` (FR-DEMO-03), care rulează întâi
 * `migrate:fresh` și apoi această comandă.
 */
class DemoSeedVolume extends Command
{
    protected $signature = 'demo:seed-volume';

    protected $description = 'Seed the full demo dataset — 3 tenants, ~8k accounts, ~50k orders (specs.md §21)';

    public function handle(): int
    {
        $seeder = (new DemoDatasetSeeder)->setContainer($this->laravel)->setCommand($this);

        $seeder->run();

        return self::SUCCESS;
    }
}
