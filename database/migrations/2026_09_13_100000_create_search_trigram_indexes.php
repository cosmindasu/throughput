<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Căutarea globală (FR-SEARCH-01/02, BR-SEARCH-01, specs.md §15.5) — `pg_trgm` direct în
 * PostgreSQL, fără motor de căutare dedicat (plan §3, §8, §14: pragul real al unui motor
 * extern e la zeci de milioane de rânduri, nu la scara acestui produs).
 *
 * `pg_trgm` e activă din Faza 1 (`docker/postgres/init/01-roles-and-extensions.sql`) — aici
 * se adaugă DOAR indexurile, o dată cu UI-ul care le consumă (BR-SEARCH-01): indexul
 * depinde de coloane deja existente, structura de index se adaugă când feature-ul e construit.
 *
 * GIN, nu GiST, pe `gin_trgm_ops`: rapid la citire, ceea ce contează pentru autocomplete —
 * costul mai mare de scriere e acceptabil, conturile/contactele/deals nu se scriu în masă la
 * fiecare tastă. Contactele indexează CONCATENAREA `first_name || ' ' || last_name` (un
 * index pe expresie, nu pe coloană) — exact forma din plan §8, ca „John Smith" să matcheze
 * pe numele întreg, nu doar pe un singur câmp.
 *
 * Products/variants incluse acum (plan §8 le enumeră explicit lângă accounts/contacts/deals),
 * deși ecranele de Produse nu există în Faza 2 (Faza 3, plan §9) — indexul e ieftin de creat
 * devreme și pregătește Faza 3; `SearchController` (Faza 2) NU expune încă un grup Products
 * în rezultate, ca să nu producă un link mort către un ecran inexistent (semnalat în raport).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS accounts_name_trgm ON accounts USING GIN (name gin_trgm_ops)');
        DB::statement("CREATE INDEX IF NOT EXISTS contacts_name_trgm ON contacts USING GIN ((first_name || ' ' || last_name) gin_trgm_ops)");
        DB::statement('CREATE INDEX IF NOT EXISTS deals_title_trgm ON deals USING GIN (title gin_trgm_ops)');

        // Pregătire Faza 3 (§9) — vezi nota de clasă.
        DB::statement('CREATE INDEX IF NOT EXISTS products_name_trgm ON products USING GIN (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS variants_sku_trgm ON variants USING GIN (sku gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS accounts_name_trgm');
        DB::statement('DROP INDEX IF EXISTS contacts_name_trgm');
        DB::statement('DROP INDEX IF EXISTS deals_title_trgm');
        DB::statement('DROP INDEX IF EXISTS products_name_trgm');
        DB::statement('DROP INDEX IF EXISTS variants_sku_trgm');
    }
};
