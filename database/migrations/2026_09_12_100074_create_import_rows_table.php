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
        Schema::create('import_rows', function (Blueprint $table) {   // RLS — raw_data nu se șterge niciodată (BR-IMP-01)
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('import_id')->constrained('imports')->cascadeOnDelete();
            $table->integer('row_number');
            $table->jsonb('raw_data');
            $table->enum('status', ['pending', 'valid', 'invalid', 'imported', 'skipped'])->default('pending');
            $table->jsonb('errors')->nullable();
            $table->ulid('created_entity_id')->nullable();
            $table->index(['tenant_id', 'import_id']);
        });

        $this->enableRls('import_rows');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
