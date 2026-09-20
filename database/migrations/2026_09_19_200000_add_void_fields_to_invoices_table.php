<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-BILL-01/§12.1 — "→ void (din orice stare, cu MOTIV)". Migrația originală a
 * `invoices` (Faza 1, `2026_09_12_100060_create_invoices_table.php`, congelată — nu se
 * atinge aici) n-a prevăzut unde se scrie acel motiv. Fără o coloană, „Void" ar schimba
 * doar `status`, iar motivul tastat de Manager/Owner s-ar pierde imediat — exact
 * genul de regresie tăcută pe care celelalte reguli de audit din acest proiect le
 * interzic explicit (BR-AUD-01).
 *
 * Adăugare minimă, aditivă: nu atinge nicio coloană existentă, nu schimbă nicio
 * migrație deja aplicată — sigură de rulat oricând peste schema Fazei 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->text('void_reason')->nullable()->after('pdf_status');
            $table->timestamp('voided_at')->nullable()->after('void_reason');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['void_reason', 'voided_at']);
        });
    }
};
