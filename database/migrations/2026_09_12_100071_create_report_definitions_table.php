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
        Schema::create('report_definitions', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('saved_view_id')->nullable()->constrained('saved_views')->nullOnDelete();
            $table->enum('report_type', ['saved_view_export', 'deal_velocity', 'inventory_valuation'])->nullable();
            $table->string('name');
            $table->enum('format', ['csv', 'xlsx', 'pdf']);
            $table->enum('schedule_frequency', ['none', 'daily', 'weekly', 'monthly'])->default('none');
            $table->time('schedule_time')->nullable();
            $table->unsignedTinyInteger('schedule_day')->nullable();
            $table->jsonb('recipients');
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        $this->enableRls('report_definitions');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
