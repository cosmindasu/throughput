<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 3, valul 2 (plan §17, intrarea 1.24) — vederea IMPLICITĂ a comenzilor
 * (`OrderList::defaultSort()` = `-created_at`, FĂRĂ filtru de status pentru Owner/Manager,
 * singurul rol fără `owner=me` implicit) nu e servită de indexul existent
 * `(tenant_id, status, created_at)` (migrația de creare `orders`, §7.7 din plan): acela
 * ordonează rândurile DUPĂ status înăuntrul fiecărui tenant, deci `created_at` e sortat
 * doar în interiorul fiecărei valori de status, nu peste toate — o interogare fără
 * predicat de status nu poate citi indexul în ordinea cerută de `ORDER BY created_at DESC`.
 *
 * Măsurat sub RLS, ca `throughput_app`, cu interogarea EXACTĂ generată de `OrderList`
 * (`DB::enableQueryLog()`, tenant Marlin, 30.000 de comenzi), pe seed-ul de dev complet:
 * `EXPLAIN (ANALYZE, BUFFERS)` ÎNAINTE — `Seq Scan` pe `orders` (30.000 rânduri scanate,
 * 20.000 aruncate de filtrul de tenant), `Sort` separat pe rezultat, 37,5 ms. Nu e
 * redundant cu indexul existent — servește exact cazul fără predicat de status, absent
 * din tabelul §7.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
        });
    }
};
