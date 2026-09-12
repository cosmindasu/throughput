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
        Schema::create('variants', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku');
            $table->jsonb('attributes')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('cost', 10, 2);   // ascuns pentru Agent/Viewer — §7.4 din specs.md, la nivel de Resource
            $table->decimal('weight', 8, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });

        $this->enableRls('variants');
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
