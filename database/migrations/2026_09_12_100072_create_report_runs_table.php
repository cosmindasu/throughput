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
        Schema::create('report_runs', function (Blueprint $table) {   // RLS — append-only (log de execuție)
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('report_definition_id')->constrained('report_definitions')->cascadeOnDelete();
            $table->enum('status', ['queued', 'running', 'success', 'failed']);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('file_path')->nullable();
            $table->integer('row_count')->nullable();
            $table->text('error_message')->nullable();
            $table->enum('triggered_by', ['scheduler', 'manual']);
            $table->index(['tenant_id', 'report_definition_id']);
        });

        $this->enableRls('report_runs');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
