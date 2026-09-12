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
        Schema::create('deal_stage_events', function (Blueprint $table) {   // RLS — append-only
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignUlid('from_stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $table->foreignUlid('to_stage_id')->constrained('stages');
            $table->foreignUlid('changed_by')->constrained('users');
            $table->timestamp('changed_at');
            $table->unsignedBigInteger('duration_in_previous_stage_seconds')->nullable();
            $table->index(['tenant_id', 'deal_id', 'changed_at']);
        });

        $this->enableRls('deal_stage_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_events');
    }
};
