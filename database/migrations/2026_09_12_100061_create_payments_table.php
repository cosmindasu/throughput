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
        Schema::create('payments', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['bank_transfer', 'check', 'manual']);
            $table->timestamp('paid_at');
            $table->foreignUlid('created_by')->constrained('users');
            $table->index(['tenant_id', 'invoice_id']);
        });

        $this->enableRls('payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
