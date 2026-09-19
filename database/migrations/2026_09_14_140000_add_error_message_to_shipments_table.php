<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 3, valul 2 (onorare/expediere, US-ORD-03) — `label_failed` are nevoie de „mesajul
 * specific al furnizorului (nu Something went wrong)", scris de `GenerateShippingLabelJob`.
 * Convenția `error_message` (text, nullable) e deja folosită identic pe `bulk_operations`,
 * `report_runs`, `data_export_requests`, `webhook_events` — aceeași formă aici, nu un nume nou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->text('error_message')->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('error_message');
        });
    }
};
