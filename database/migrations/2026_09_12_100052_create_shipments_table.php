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
        Schema::create('shipments', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained('locations');
            $table->string('carrier');
            $table->string('service_level')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('label_url')->nullable();
            // `label_pending` = jobul de etichetă e în coadă; `label_failed` = a eșuat, cu buton de reîncercare.
            // Ambele stări sunt cerute de ADR-013 (apelul la curier nu se face în cererea HTTP).
            $table->enum('status', ['label_pending', 'label_failed', 'label_purchased', 'in_transit', 'delivered', 'exception'])->default('label_pending');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'order_id']);
        });

        $this->enableRls('shipments');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
