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
        Schema::create('stock_movements', function (Blueprint $table) {   // RLS — append-only, ADR-004
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('variant_id')->constrained('variants');
            $table->foreignUlid('location_id')->constrained('locations');
            $table->integer('delta');   // semnat
            $table->enum('reason', ['receipt', 'sale', 'adjustment', 'return', 'transfer']);
            $table->string('ref_type')->nullable();
            $table->ulid('ref_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'variant_id', 'location_id', 'created_at']);
        });

        $this->enableRls('stock_movements');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
