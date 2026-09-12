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
        Schema::create('data_export_requests', function (Blueprint $table) {   // RLS — nou v1.3, specs.md §20.5 (FR-GDPR-01/02)
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('requested_by')->constrained('users');
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->string('file_path')->nullable();
            $table->timestamp('expires_at')->nullable();   // link valid 7 zile — golit de job-ul zilnic de curățare
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->index(['tenant_id', 'status']);
        });

        $this->enableRls('data_export_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('data_export_requests');
    }
};
