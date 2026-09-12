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
        Schema::create('stages', function (Blueprint $table) {   // RLS
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position');
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->unsignedTinyInteger('probability')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'pipeline_id', 'position']);
        });

        $this->enableRls('stages');
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
    }
};
