<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela standard Cashier, cu `user_id` ULID: Billable e TENANTUL, nu utilizatorul
 * (ADR-006, specs.md §12.2) — abonamentul e al organizației, iar plata nu trebuie să
 * dispară când pleacă persoana care a introdus cardul.
 *
 * Numele coloanei rămâne `user_id` (implicitul Cashier, `Subscription::user()`), deși
 * conținutul e un `tenants.id`. Redenumirea ar fi cerut suprascrieri în trei clase din
 * pachet pentru zero câștig funcțional; `Cashier::useCustomerModel(Tenant::class)` din
 * AppServiceProvider spune explicit ce e de fapt.
 *
 * Fără RLS: tabelele Cashier se ating exclusiv din fluxul de abonament al Owner-ului și
 * din webhook-ul Stripe, care rulează fără context de sesiune (ADR-014, pct. 4).
 * Migrațiile pachetului sunt dezactivate în AppServiceProvider — ale lor ar fi pus
 * `foreignId` (bigint) și coloanele de client pe `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('user_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'stripe_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
