<?php

namespace Database\Migrations\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Stratul 2 din ADR-003: politica RLS, aplicată per tabelă, la fiecare migrație.
 *
 * Politica are DOAR `USING`, iar PostgreSQL o folosește ȘI ca `WITH CHECK` la INSERT.
 * Consecința (verificată, nu dedusă): fără context de tenant nu doar că nu se citește
 * nimic — nu se poate nici scrie, `new row violates row-level security policy`. De aici
 * regula din ADR-014, pct. 4: până și seed-ul iterează tenanții cu un context per tenant.
 *
 * `current_setting(..., true)` (missing_ok) întoarce NULL când `app.tenant_id` nu e setat,
 * deci politica devine `tenant_id = NULL` — zero rânduri, nu eroare SQL. Eroarea explicită
 * și inteligibilă rămâne treaba stratului Eloquent (TenantContextMissingException).
 *
 * Fără `FORCE ROW LEVEL SECURITY`: `throughput_app` nu e proprietarul tabelelor
 * (proprietar e `throughput_migrator`, care are BYPASSRLS și ignoră politica oricum),
 * deci FORCE nu ar schimba comportamentul conexiunii aplicației — doar l-ar face redundant.
 */
trait EnablesRowLevelSecurity
{
    /**
     * Politica uniformă: rândurile tenantului curent.
     */
    protected function enableRls(string $table, string $column = 'tenant_id'): void
    {
        $this->enableRlsWithPolicy(
            $table,
            "{$column}::text = current_setting('app.tenant_id', true)"
        );
    }

    /**
     * Politică proprie. Un singur apelant în toată aplicația: `memberships` (ADR-014, pct. 2).
     * Orice al doilea apelant are nevoie de un ADR, nu de un commit.
     */
    protected function enableRlsWithPolicy(string $table, string $using, string $policy = 'tenant_isolation'): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY {$policy} ON {$table} USING ({$using})");
    }
}
