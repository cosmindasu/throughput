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
        Schema::create('activity_log', function (Blueprint $table) {   // RLS — append-only
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('action', ['created', 'updated', 'deleted', 'login', 'login_failed', 'exported', 'imported', 'bulk_action', 'role_changed']);
            $table->string('auditable_type')->nullable();
            $table->ulid('auditable_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address');
            $table->string('user_agent');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'auditable_type', 'auditable_id']);
        });

        $this->enableRls('activity_log');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
