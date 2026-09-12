<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelă standard Laravel, necesară din momentul în care recuperarea parolei (FR-PUB-05,
 * specs.md §4.5) e o cerință formală, nu un fallback nedescris. Grupul A din plan §7.6.
 *
 * Fără `tenant_id`: resetarea parolei se întâmplă înainte de autentificare, deci înainte
 * să existe vreun workspace. Adresa de email e identitate globală, ca `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
