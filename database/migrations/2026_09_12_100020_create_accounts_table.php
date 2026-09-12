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
        Schema::create('accounts', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('industry')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->string('phone')->nullable();
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('tags')->nullable();
            $table->enum('status', ['prospect', 'active', 'inactive'])->default('prospect');
            $table->enum('credit_terms', ['net_15', 'net_30', 'net_60', 'prepaid'])->default('net_30');
            $table->string('source')->nullable();
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'owner_user_id']);
            $table->index(['tenant_id', 'status']);
        });

        $this->enableRls('accounts');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
