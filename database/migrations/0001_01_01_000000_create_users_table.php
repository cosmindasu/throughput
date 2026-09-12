<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupul A din plan §7.6 — fundație.
 *
 * `users` e identitate GLOBALĂ, nu date de tenant: nu are `tenant_id` și, în consecință,
 * nu are RLS. Legătura cu o organizație e `memberships`, care are (cu o politică proprie,
 * ADR-014 pct. 2). Un utilizator dezactivat într-un tenant poate rămâne activ în altul
 * (BR-TEN-04) — ceea ce ar fi imposibil dacă identitatea ar fi scopată pe tenant.
 *
 * Cheia e ULID, nu `id()` auto-incrementat: BR-DATA-01, mitigare BOLA (§18.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Preferințe de interfață (specs.md §15.6/§15.7). Stau pe `users`, nu pe
            // `memberships`: preferința e a persoanei și nu se schimbă la comutarea
            // workspace-ului (FR-PREF-02).
            $table->enum('theme', ['system', 'light', 'dark'])->default('system');
            $table->jsonb('dismissed_hints')->default('[]');   // BR-HELP-02

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
