<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupul A din plan §7.6 — fundație.
 *
 * `tenants` NU are RLS: e chiar unitatea de scopare. Consecință utilă, nu scăpare —
 * ruta publică de webhook Stripe rezolvă tenantul după `stripe_id` fără niciun context
 * de sesiune (ADR-014, pct. 4).
 *
 * Coloanele `stripe_*`/`trial_ends_at` stau aici, nu pe `users`: Billable e TENANTUL
 * (ADR-006, specs.md §12.2). Migrația de „customer columns" a lui Cashier e dezactivată
 * în AppServiceProvider tocmai pentru că altfel le-ar fi pus pe tabela greșită.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();          // segment de cale — ADR-002
            $table->string('industry')->nullable();     // subtitlul dashboard-ului — FR-DEMO-01
            $table->string('currency', 3)->default('USD');
            $table->string('stripe_id')->nullable();    // Billable — Cashier, §12.2
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            // Ancora retenției de 30 de zile — BR-BILL-05, specs.md §12.2.
            $table->timestamp('subscription_canceled_at')->nullable();
            $table->timestamps();

            $table->index('stripe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
