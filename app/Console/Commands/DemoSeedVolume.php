<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDatasetSeeder;
use Illuminate\Console\Command;

/**
 * Plan §7.8 — rulează orchestratorul de seed la volum (3 tenanți, ~8.000 conturi,
 * ~50.000 comenzi, istoric pe 24 de luni — specs.md §21). Presupune o schemă proaspătă;
 * pentru un reset complet folosește `demo:reset` (FR-DEMO-03), care rulează întâi
 * `migrate:fresh` și apoi această comandă.
 *
 * `--scale` produce același set, cu aceleași reguli de realism, la o fracțiune din volum —
 * pentru suita E2E, unde seed-ul complet ar costa peste un minut de CI la fiecare rulare.
 */
class DemoSeedVolume extends Command
{
    protected $signature = 'demo:seed-volume
        {--scale=1 : Fracțiune din volumul complet, în intervalul (0, 1] — ex: 0.01 pentru suita E2E}';

    protected $description = 'Seed the demo dataset — 3 tenants, ~8k accounts, ~50k orders at full scale (specs.md §21)';

    public function handle(): int
    {
        $scale = (float) $this->option('scale');

        if ($scale <= 0 || $scale > 1) {
            $this->components->error('--scale trebuie să fie în intervalul (0, 1].');

            return self::INVALID;
        }

        $seeder = (new DemoDatasetSeeder)->setContainer($this->laravel)->setCommand($this);

        $seeder->scaledTo($scale)->run();

        return self::SUCCESS;
    }
}
