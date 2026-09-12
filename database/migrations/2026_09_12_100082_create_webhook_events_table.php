<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {   // fără RLS — cross-tenant pe deduplicare
            $table->ulid('id')->primary();
            $table->enum('source', ['stripe', 'carrier']);
            $table->string('event_id');
            $table->string('type');
            $table->jsonb('payload');
            $table->string('payload_hash');
            $table->enum('status', ['received', 'processing', 'processed', 'failed'])->default('received');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unique(['source', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
