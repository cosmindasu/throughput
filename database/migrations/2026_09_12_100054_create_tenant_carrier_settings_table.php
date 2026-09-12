<?php

use Database\Migrations\Concerns\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('tenant_carrier_settings', function (Blueprint $table) {   // RLS — ADR-010
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Doar furnizorii care au implementare. `easypost` a fost scos la 2026-09-12:
            // EasyPost condiționează accesul la chei (inclusiv cele de test) de un abonament
            // lunar, iar supapa pre-autorizată de ADR-010 a fost trasă. Adăugarea unui al
            // treilea furnizor = o valoare în enum + un adaptor + aceeași suită de contract.
            $table->enum('provider', ['shippo', 'demo']);
            $table->text('credentials')->nullable();   // encrypted:array cast — niciodată în clar (ADR-010)
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
            $table->index(['tenant_id', 'is_active']);
        });

        $this->enableRls('tenant_carrier_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_carrier_settings');
    }
};
