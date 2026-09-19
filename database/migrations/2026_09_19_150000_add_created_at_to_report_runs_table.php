<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix P2 (review lotul K, Faza 4) — `report_runs` n-avea nicio coloană de timp de creare
 * (migrația originală, §16.1, o desenase ca „log de execuție" cu doar `started_at`/
 * `finished_at`, ambele nule cât timp rularea e `queued`). Detectarea dublării la
 * scheduler (`App\Jobs\System\DispatchScheduledReportsJob`) decodea în schimb timpul din
 * ULID-ul rândului (`Symfony\Component\Uid\Ulid`) — funcțional, dar leagă o decizie de
 * business de un detaliu de encoding, și nu e indexabilă/interogabilă direct în SQL.
 *
 * RLS deja activ pe tabelă (migrația de creare) — un `ALTER TABLE ADD COLUMN` nu repetă
 * `enableRls()`, politica existentă acoperă și coloana nouă.
 *
 * `useCurrent()` — Postgres populează `DEFAULT CURRENT_TIMESTAMP` la INSERT, indiferent
 * dacă apelantul trimite explicit valoarea sau nu. Modelul rămâne `$timestamps = false`
 * (nu există `updated_at` — un log de execuție nu se „actualizează" în acel sens), deci
 * Eloquent nu încearcă să gestioneze automat coloana; DB-ul o completează singur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_runs', function (Blueprint $table) {
            $table->timestamp('created_at')->useCurrent()->after('tenant_id');
            $table->index(['report_definition_id', 'triggered_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('report_runs', function (Blueprint $table) {
            $table->dropIndex(['report_definition_id', 'triggered_by', 'created_at']);
            $table->dropColumn('created_at');
        });
    }
};
