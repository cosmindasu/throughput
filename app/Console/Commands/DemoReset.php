<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * FR-DEMO-03 (specs.md §22.1) — reset zilnic al datelor demo. `migrate:fresh` rulează
 * explicit pe conexiunea de migrare (`pgsql_migrations`, rol cu BYPASSRLS): singurul loc
 * din afara migratorului însuși unde acel rol e folosit, pentru că e DDL, nu manipulare de
 * date (ADR-014, pct. 4). Re-seed-ul de mai jos rulează pe conexiunea normală a aplicației.
 *
 * `exports/` (fișierele scrise de `ExportListJob`, §13.2) se șterge integral aici, nu doar
 * lăsat pentru `PruneExpiredExportsJob`: `migrate:fresh` golește `bulk_operations`, deci la
 * finalul acestei comenzi CHIAR TOATE fișierele de pe disc sunt orfane, cu certitudine — nu
 * are rost să aștepte pragul de retenție (implicit 7 zile) al măturării de orfani, care e
 * gândită pentru cazul rar, nu pentru „100% din fișiere, în fiecare noapte". Fără asta, CSV-uri
 * cu date de contact reale (demo-ul are scriere publică, §22) s-ar aduna la nesfârșit pe disc.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Drop and recreate the schema, then reseed the full demo dataset (FR-DEMO-03)';

    public function handle(): int
    {
        $this->call('migrate:fresh', ['--database' => 'pgsql_migrations', '--force' => true]);

        $exitCode = $this->call(DemoSeedVolume::class);

        Storage::disk('local')->deleteDirectory('exports');

        return $exitCode;
    }
}
