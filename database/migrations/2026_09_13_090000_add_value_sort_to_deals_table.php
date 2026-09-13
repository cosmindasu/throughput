<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coloană generată pentru sortarea pe `value` — Pachetul C, capcana (e) semnalată
     * în task: `deals.value` e nullabil (§9.2 — „nullabil până la calificare"), iar
     * `cursorPaginate()` compară strict pe coloana de sortare cu `>`/`<`. O comparație
     * SQL cu NULL nu e niciodată adevărată, deci un deal necalificat nu doar că iese
     * din ordine — DISPARE din toate paginile pe `sort=-value`, silențios.
     *
     * `value_sort` e NOT NULL prin construcție (`GENERATED ALWAYS AS ... STORED`), deci
     * cursorul include mereu toate rândurile. Sentinela `-1`: `value` e mereu >= 0 când
     * e setat (monedă), deci un deal necalificat sortează sub orice deal calificat —
     * decizie de business asumată aici (deals fără valoare = prioritate minimă în
     * clasamentul „by value"), nu doar tehnică. Semnalată în raportul agentului.
     *
     * Indexul compus `(tenant_id, value_sort)` — `tenant_id` coloană de lider, la fel
     * ca toate celelalte din §7.7 din plan — ca sortarea pe valoare să rămână un
     * Index Scan, nu un Seq Scan, la volumul de §21.
     */
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->decimal('value_sort', 12, 2)->storedAs('COALESCE(value, -1)');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->index(['tenant_id', 'value_sort']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'value_sort']);
            $table->dropColumn('value_sort');
        });
    }
};
