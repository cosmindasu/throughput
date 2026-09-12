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
        Schema::create('saved_views', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users');
            $table->enum('resource_type', ['accounts', 'contacts', 'deals', 'orders', 'products', 'invoices']);
            $table->string('name');
            $table->jsonb('filters');
            $table->jsonb('columns');
            $table->jsonb('sort')->nullable();
            $table->enum('visibility', ['private', 'team'])->default('private');
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'resource_type']);
        });

        $this->enableRls('saved_views');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
