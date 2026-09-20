<?php

namespace App\Listeners\Billing;

use App\Mail\DunningPaymentFailedMail;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Billing\Events\StripeInvoicePaymentFailed;
use App\Support\Billing\TenantOwners;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * FR-BILL-04 — „Listener propriu, ÎNREGISTRAT EXPLICIT" (`AppServiceProvider::register()`)
 * pe `invoice.payment_failed`, distinct de sincronizarea de status din
 * `App\Jobs\Webhooks\ProcessStripeWebhookJob` (aceeași sursă — webhook Stripe — dar două
 * efecte diferite, niciunul opțional).
 *
 * `ShouldQueue` — trimiterea prin `Mail` e I/O extern (ADR-013): ține un job PROPRIU, nu
 * rulează inline din jobul de webhook care a emis evenimentul.
 *
 * Structură IDENTICĂ cu `App\Jobs\Reports\DeliverReportJob` (ADR-013 + ADR-014 pct. 5,
 * „joburile cu I/O extern își gestionează singure contextul"): o tranzacție SCURTĂ
 * citește tenantul/destinatarii și construiește `Mailable`-ul (unde
 * `AttributesSentEmailToTenant` captează tenantul cât încă e activ), apoi `Mail::send()`
 * rulează STRICT în afara oricărei tranzacții. Nimic de scris înapoi după trimitere.
 */
final class SendPaymentFailedDunningEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(StripeInvoicePaymentFailed $event): void
    {
        [$mailable, $recipients] = TenantContext::run($event->tenantId, function () use ($event): array {
            $tenant = Tenant::query()->find($event->tenantId);

            if ($tenant === null) {
                return [null, []];
            }

            $owners = TenantOwners::forTenant($event->tenantId);

            if ($owners->isEmpty()) {
                return [null, []];
            }

            $mailable = new DunningPaymentFailedMail(
                tenantName: $tenant->name,
                workspaceSlug: $tenant->slug,
                attemptCount: $event->attemptCount,
            );

            return [$mailable, $owners->pluck('email')->all()];
        });

        if ($mailable === null || $recipients === []) {
            return;
        }

        Mail::to($recipients)->send($mailable);
    }
}
