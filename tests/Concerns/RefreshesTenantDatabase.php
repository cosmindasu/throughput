<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * `RefreshDatabase`, cu două abateri cerute de ADR-003 — fiecare pentru un motiv care,
 * altfel, ar face suita să treacă verde fără să testeze nimic:
 *
 *  1. **Migrațiile rulează pe `pgsql_migrations`**, nu pe conexiunea implicită. Rolul
 *     aplicației (`throughput_app`) nu are `CREATE` pe schema `public` — `migrate:fresh`
 *     ar cădea cu „permission denied". Mai important: dacă testele ar migra cu rolul
 *     aplicației, acesta ar deveni PROPRIETARUL tabelelor, iar PostgreSQL nu aplică
 *     politici RLS proprietarului. Testele de izolare ar fi trecut verde pe o plasă
 *     inexistentă — exact modul de eșec pe care îl semnalează și comentariul din
 *     `phpunit.xml` despre SQLite.
 *
 *  2. **Împachetarea în tranzacție e opțională** (`$wrapInTransaction`). `set_config(…, true)`
 *     e scopat tranzacției, iar sub împachetarea implicită un `commit` din cod e doar
 *     eliberarea unui savepoint: contextul supraviețuiește, deci un test care verifică
 *     „după commit contextul e gol" ar fi verificat artefactul harnessului, nu mecanismul.
 *     Testele care observă commit-uri reale opresc împachetarea.
 */
trait RefreshesTenantDatabase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        return [
            '--drop-views' => false,
            '--drop-types' => false,
            '--database' => 'pgsql_migrations',
            '--force' => true,
            '--seed' => false,
        ];
    }

    protected function refreshTestDatabase()
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->migrateDatabases();

            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        if ($this->wrapsTestInTransaction()) {
            $this->beginDatabaseTransaction();
        }
    }

    protected function wrapsTestInTransaction(): bool
    {
        return ! property_exists($this, 'wrapInTransaction') || $this->wrapInTransaction;
    }
}
