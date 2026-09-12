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
        Schema::create('bulk_operations', function (Blueprint $table) {   // RLS — extensie de model, §13.2
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users');
            $table->string('resource_type');
            $table->string('action');
            $table->jsonb('filter_snapshot');
            $table->integer('total_rows');
            $table->string('batch_id')->nullable();   // FK logic către job_batches (nativ Laravel, fără FK real)
            $table->ulid('group_id')->nullable();     // leagă operațiile aceleiași acțiuni de utilizator — BR-BULK-04
            $table->enum('status', ['pending', 'running', 'completed', 'cancelled', 'failed'])->default('pending');
            $table->timestamps();
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'group_id']);  // progres agregat pe grup (US-TEN-03)
        });

        $this->enableRls('bulk_operations');
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_operations');
    }
};
