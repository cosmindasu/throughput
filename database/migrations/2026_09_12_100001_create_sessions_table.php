<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelă standard Laravel, separată din migrația de `users` a scheletului pentru regula
 * „un fișier per tabelă" din plan §7.6.
 *
 * Driverul de sesiune e Redis (§2.3), deci tabela nu se folosește la runtime — rămâne
 * ca plasă pentru un mediu fără Redis. `user_id` e ULID, ca `users.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
