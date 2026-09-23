<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-05 (audit 2026-09-23, §2) — `ActivityLogController::index()` (linia 53) adaugă
 * necondiționat `WHERE user_id = ?` pentru un Agent (fără `activity_log.view`, doar
 * `activity_log.view_own`), peste `ORDER BY created_at DESC, id DESC LIMIT 50`. Niciun
 * index existent nu conține `user_id`: `(tenant_id, created_at)` și
 * `(tenant_id, auditable_type, auditable_id)` (migrația de creare) lasă filtrul de
 * utilizator să se aplice DUPĂ scanarea indexului de tenant — cost proporțional cu
 * întreaga istorie a tenantului, nu cu cea a utilizatorului.
 *
 * Măsurat sub RLS, ca `throughput_app`, cu interogarea EXACTĂ din `ActivityLogController`
 * (tenant Marlin, 108.166 rânduri de `activity_log`, agent cu 15.236 rânduri proprii,
 * seed complet `demo:seed-volume`): `EXPLAIN (ANALYZE, BUFFERS)` ÎNAINTE — `Bitmap Heap
 * Scan` pe indexul de tenant existent, toate cele 108.166 rânduri ale tenantului citite,
 * `Rows Removed by Filter: 92930` (filtrul de `user_id` aplicat post-scan), 49,2 ms,
 * `Buffers: shared hit=4525`.
 *
 * Ordinea coloanelor: `tenant_id` (egalitate, RLS + global scope Eloquent — vezi
 * `tenancy.md`, cast pe setare, nu pe coloană), apoi `user_id` (egalitate, singurul
 * predicat suplimentar aplicat necondiționat la fiecare cerere de Agent), apoi
 * `created_at` (interval/ordonare — servește direct `ORDER BY created_at DESC` fără sortare
 * separată). Fără `id` ca a patra coloană: convenția din
 * `2026_09_14_120000_add_created_at_index_to_orders_table.php`/
 * `2026_09_14_170000_add_owner_user_id_index_to_orders_table.php` nu include tiebreaker-ul
 * de cheie primară în index — la egalitate de secundă pe `created_at` (precizie 0, vezi
 * `tenancy.md`), Postgres sortează în memorie doar rândurile din acea secundă, un cost
 * neglijabil față de scanarea întregului tenant.
 *
 * Indexul existent `(tenant_id, created_at)` NU devine redundant: servește `index()` fără
 * filtru de utilizator (Owner/Manager cu `activity_log.view`) și feed-ul de dashboard
 * (`db:explain-critical`, cazul „activity_log — feed de dashboard”), niciunul dintre ele
 * cu predicat pe `user_id`. Rămâne, nu se șterge — nicio dovadă de redundanță.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'user_id', 'created_at']);
        });
    }
};
