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
        Schema::create('invoices', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained('orders');
            $table->string('invoice_number')->nullable();
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'void'])->default('draft');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance_due', 12, 2)->default(0);
            $table->string('pdf_path')->nullable();
            // ADR-013: PDF-ul se generează într-un job (Chromium), nu în cerere. Butonul de descărcare
            // e activ doar pe `ready`; pe `failed` se afișează motivul și un buton de reîncercare.
            $table->enum('pdf_status', ['pending', 'ready', 'failed'])->default('pending');
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'due_date']);
            $table->unique(['tenant_id', 'invoice_number']);
        });

        $this->enableRls('invoices');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
