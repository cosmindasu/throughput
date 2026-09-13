<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extensie de model, ca `result_path` (migrația `2026_09_13_090000`): jobul de curățare a
 * exporturilor (plan §7.2, „curățarea exporturilor expirate"; specs.md FR-GDPR-01, aceeași
 * retenție de 7 zile) are nevoie de un moment explicit de expirare per operație, nu doar de
 * `updated_at` — un export poate fi „completed" de mult mai puțin timp decât retenția dacă
 * jobul a reîncercat.
 *
 * `ExportListJob` scrie `expires_at = now() + throughput.limits.export_retention_days` exact
 * când marchează `completed`. RLS deja activ pe tabelă (migrația de creare) — un
 * `ALTER TABLE ADD COLUMN` nu repetă `enableRls()`, politica existentă acoperă și coloana nouă.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_operations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_operations', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
