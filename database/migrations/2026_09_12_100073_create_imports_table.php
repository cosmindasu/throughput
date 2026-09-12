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
        Schema::create('imports', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('resource_type', ['accounts', 'contacts', 'products', 'variants']);
            $table->string('original_filename');
            $table->jsonb('column_mapping')->nullable();
            $table->enum('status', ['uploaded', 'mapped', 'validating', 'validated', 'importing', 'completed', 'completed_with_errors', 'failed'])->default('uploaded');
            $table->integer('total_rows')->nullable();
            $table->integer('valid_rows')->nullable();
            $table->integer('error_rows')->nullable();
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        $this->enableRls('imports');
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
