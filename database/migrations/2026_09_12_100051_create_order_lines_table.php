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
        Schema::create('order_lines', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('variant_id')->constrained('variants');
            $table->string('description');   // instantaneu — nu urmărește redenumiri ulterioare
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->integer('quantity_fulfilled')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'order_id']);
        });

        $this->enableRls('order_lines');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
