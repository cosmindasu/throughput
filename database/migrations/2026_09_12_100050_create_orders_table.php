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
        Schema::create('orders', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('order_number')->nullable();   // secvențial per tenant, la confirmare — BR-ORD-02
            $table->foreignUlid('account_id')->constrained('accounts');
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->foreignUlid('owner_user_id')->constrained('users');
            $table->enum('status', ['draft', 'confirmed', 'partially_fulfilled', 'fulfilled', 'cancelled'])->default('draft');
            $table->string('currency', 3)->default('USD');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->timestamp('placed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'account_id']);
            $table->unique(['tenant_id', 'order_number']);
        });

        $this->enableRls('orders');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
