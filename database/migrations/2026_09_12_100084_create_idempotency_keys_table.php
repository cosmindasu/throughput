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
        Schema::create('idempotency_keys', function (Blueprint $table) {   // RLS — §18.4
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key');
            $table->string('request_hash');
            $table->jsonb('response_body')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamp('expires_at');   // 24h — §18.4
            $table->unique(['tenant_id', 'key']);
        });

        $this->enableRls('idempotency_keys');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
