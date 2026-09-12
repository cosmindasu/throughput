<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * FR-DEMO-03 (specs.md §22.1) — reset zilnic al datelor demo. `migrate:fresh` rulează
 * explicit pe conexiunea de migrare (`pgsql_migrations`, rol cu BYPASSRLS): singurul loc
 * din afara migratorului însuși unde acel rol e folosit, pentru că e DDL, nu manipulare de
 * date (ADR-014, pct. 4). Re-seed-ul de mai jos rulează pe conexiunea normală a aplicației.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Drop and recreate the schema, then reseed the full demo dataset (FR-DEMO-03)';

    public function handle(): int
    {
        $this->call('migrate:fresh', ['--database' => 'pgsql_migrations', '--force' => true]);

        return $this->call(DemoSeedVolume::class);
    }
}
