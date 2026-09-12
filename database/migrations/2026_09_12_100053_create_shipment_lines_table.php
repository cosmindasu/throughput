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
        Schema::create('shipment_lines', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignUlid('order_line_id')->constrained('order_lines')->cascadeOnDelete();
            $table->integer('quantity');
        });

        $this->enableRls('shipment_lines');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
    }
};
