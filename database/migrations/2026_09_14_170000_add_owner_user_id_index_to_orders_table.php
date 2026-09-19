<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-005 (review general, lotul „Membri și roluri") — `App\Support\Members\UnassignedRecordsCounter`
 * (indicatorul numeric din navigație, FR-TEN-05) citește jumătatea `orders` cu
 * `whereIn('status', [draft, confirmed, partially_fulfilled])->whereNotIn('owner_user_id', ...)`.
 * Indexul existent pe `orders` e `(tenant_id, status, created_at)` — util pentru filtrul de
 * status, dar planificatorul termină acolo: `owner_user_id` nu are index propriu, deci
 * `whereNotIn` peste el se aplică DUPĂ un scan al tuturor rândurilor cu statusurile active,
 * nu prin index.
 *
 * Măsurat sub RLS, ca `throughput_app`, cu interogarea EXACTĂ din `UnassignedRecordsCounter`
 * (tenant Marlin, ~30.000 de comenzi, ~9.567 active): `EXPLAIN (ANALYZE, BUFFERS)` ÎNAINTE —
 * `Seq Scan` pe `orders`, toate cele 9.567 de rânduri active citite și aruncate (owner
 * activ), 244 ms cu cache rece, 3-11 ms cu cache cald — pe FIECARE cerere a unui Owner/
 * Manager (prop comun, calculat per request în `HandleInertiaRequests`).
 *
 * `(tenant_id, owner_user_id)`, simetric cu indexul deja existent pe `deals` (migrația de
 * creare a tabelei, §7.7 din plan) — motivul pentru care `deals` nu avea aceeași problemă.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'owner_user_id']);
        });
    }
};
