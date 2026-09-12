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
        Schema::create('inventory_levels', function (Blueprint $table) {   // RLS — proiecție materializată
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('variant_id')->constrained('variants')->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->timestamp('updated_at');
            $table->unique(['tenant_id', 'variant_id', 'location_id']);
        });

        $this->enableRls('inventory_levels');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_levels');
    }
};
