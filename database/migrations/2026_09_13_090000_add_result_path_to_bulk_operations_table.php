<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extensie de model, pachetul A (Faza 2): §13.2 descrie `bulk_operations` fără o coloană
 * de rezultat, dar exportul (primul caz de uz al mecanismului) produce un fișier care
 * trebuie găsit la descărcare (`exports.download`) și un motiv de eșec afișabil, dincolo
 * de simplul `status = failed`.
 *
 * RLS deja activ pe tabelă (migrația de creare) — un `ALTER TABLE ADD COLUMN` nu repetă
 * `enableRls()`, politica existentă acoperă și coloanele noi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_operations', function (Blueprint $table) {
            $table->string('result_path')->nullable()->after('total_rows');
            $table->text('error_message')->nullable()->after('result_path');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_operations', function (Blueprint $table) {
            $table->dropColumn(['result_path', 'error_message']);
        });
    }
};
