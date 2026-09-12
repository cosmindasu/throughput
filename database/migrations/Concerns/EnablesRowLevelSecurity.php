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
        $this->enableRlsWithPolicy($table, self::matchesSetting($column, 'app.tenant_id'));
    }

    /**
     * Cast-ul stă pe SETARE, nu pe COLOANĂ — și asta decide dacă RLS folosește indexul.
     *
     * Forma din plan §7.2 era `tenant_id::text = current_setting(...)`. Măsurat pe seed-ul
     * complet, ca `throughput_app`, după ANALYZE: cu cast pe coloană, interogarea de listă a
     * comenzilor făcea `Seq Scan` peste toate cele 50.000 de rânduri (45.520 aruncate de
     * filtru, estimare de 37 rânduri față de 4.480 reale), deși indexul compus
     * `(tenant_id, status, created_at)` exista. Coloana e `character(26)`; învelită într-un
     * cast, nu mai corespunde indexului de btree, iar planificatorul nu mai are nici
     * statistici pe ea. Fără cast pe coloană: `Index Scan Backward`, sub 1 ms.
     *
     * Exact capcana din addendumul ADR-003 („tenant_id coloană de lider, altfel RLS poate fi
     * cu ordine de mărime mai lent") — doar că nu stătea în definiția indexului, ci în
     * expresia politicii. Global scope-ul Eloquent o masca: adaugă `tenant_id = ?` fără cast,
     * deci cererile obișnuite mergeau repede; plătea exact ce RLS e acolo să prindă — SQL-ul
     * brut și agregatele. `db:explain-critical` a prins-o, nu citirea codului.
     *
     * `bpchar`, nu `character(26)`: operatorul `bpchar = bpchar` e cel indexat, iar lungimea
     * nu intră în alegerea lui. Semantica rămâne identică: setare lipsă → NULL → zero rânduri;
     * setare goală → nicio potrivire → zero rânduri. Cade tot închis.
     */
    protected static function matchesSetting(string $column, string $setting): string
    {
        return "{$column} = current_setting('{$setting}', true)::bpchar";
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
