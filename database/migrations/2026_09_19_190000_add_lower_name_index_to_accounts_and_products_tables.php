<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 4 (Import CSV), review general — cheile de duplicat alese pentru `accounts`/`products`
 * (§14, `AccountImportResource`/`ProductImportResource`, „domain quando prezent, altfel
 * `name`" / „name") compară `lower(name)`. Prima încercare a fost un index FUNCȚIONAL simplu
 * (`CREATE INDEX ... ON accounts (tenant_id, lower(name))`) — respins la măsurare, nu doar
 * neoptim:
 *
 * Verificat empiric, cu `EXPLAIN (ANALYZE, BUFFERS)`, ca `throughput_migrator` (BYPASSRLS)
 * FAȚĂ DE `throughput_app` (rolul real al aplicației, sub RLS): indexul funcțional era
 * folosit perfect de primul, dar IGNORAT COMPLET de-al doilea, chiar și cu
 * `enable_seqscan = off` — planificatorul alegea alt index existent și aplica `lower(name)`
 * ca `Filter` post-scanare (`Rows Removed by Filter: 3999`, neschimbat față de „înainte").
 * Cauza: `lower()` NU e marcată `LEAKPROOF` în Postgres (`SELECT proleakproof FROM pg_proc
 * WHERE proname = 'lower'` → `f`), iar sub un rând cu politică RLS activă (o barieră de
 * securitate implicită), planificatorul refuză să împingă o funcție ne-leakproof sub barieră
 * pentru evaluare în condiția unui index — exact motivul pentru care RLS există (o funcție
 * ne-leakproof ar putea scurge, prin efecte secundare/erori, informație despre rânduri pe
 * care politica le-ar fi ascuns). Un index funcțional pe `lower(name)` e deci UTIL DOAR
 * pentru conexiuni cu BYPASSRLS (migrații, unelte de întreținere) — inutil exact pentru
 * traficul real al aplicației.
 *
 * Fix corect: o coloană GENERATĂ, STOCATĂ (`GENERATED ALWAYS AS (lower(name)) STORED`) +
 * index PLAT pe ea. `lower()` rulează o singură dată, la SCRIERE (INSERT/UPDATE, în afara
 * oricărei bariere RLS relevante pentru citire) — la CITIRE, interogarea devine o egalitate
 * simplă pe o coloană stocată (`name_lower = ?`), fără nicio funcție de evaluat, deci
 * restricția de leakproof nu se mai aplică deloc. Verificat: `Index Scan using
 * accounts_tenant_name_lower_idx`, sub RLS, ca `throughput_app`, 0,064 ms față de 1,5 ms.
 *
 * Coloana e complet transparentă pentru Eloquent — Postgres o completează la INSERT/UPDATE,
 * niciun cod PHP n-o atinge (`App\Models\Account`/`Product` nu au nevoie de `#[Fillable]`
 * sau cast nou).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE accounts ADD COLUMN name_lower character varying(255) GENERATED ALWAYS AS (lower(name)) STORED');
        DB::statement('CREATE INDEX accounts_tenant_name_lower_idx ON accounts (tenant_id, name_lower)');

        DB::statement('ALTER TABLE products ADD COLUMN name_lower character varying(255) GENERATED ALWAYS AS (lower(name)) STORED');
        DB::statement('CREATE INDEX products_tenant_name_lower_idx ON products (tenant_id, name_lower)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS accounts_tenant_name_lower_idx');
        DB::statement('ALTER TABLE accounts DROP COLUMN IF EXISTS name_lower');

        DB::statement('DROP INDEX IF EXISTS products_tenant_name_lower_idx');
        DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS name_lower');
    }
};
