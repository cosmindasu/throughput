<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit GDPR-01 (2026-09-23, P1): Stripe NU garantează ordinea de livrare a webhook-urilor,
 * iar un eveniment eșuat e reîncercat ore mai târziu. Fără o ancoră de ordine,
 * `ProcessStripeWebhookJob::syncSubscription()` aplica orice eveniment peste starea locală:
 * o anulare VECHE, livrată după reactivare, lăsa abonamentul `canceled` local deși în Stripe
 * era `active` — iar după 30 de zile `PurgeCanceledTenantsJob` ștergea un tenant plătitor.
 *
 * Coloana ține `created` al ultimului eveniment Stripe APLICAT pe acest abonament; un
 * eveniment mai vechi decât ea e ignorat. Nullabilă: rândurile existente și evenimentele
 * fără `created` păstrează comportamentul de dinainte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('last_stripe_event_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('last_stripe_event_at');
        });
    }
};
