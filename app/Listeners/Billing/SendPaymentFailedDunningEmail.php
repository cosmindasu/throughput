<?php

namespace App\Listeners\Billing;

use App\Mail\DunningPaymentFailedMail;
use App\Models\Tenant;
use App\Models\User;
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
 *
 * Lot I18N, Val 5 (plan-implementare.md, gol lăsat deschis din Val 2, decis explicit acum:
 * „bucla per destinatar") — un `Mailable` PROASPĂT per Owner, niciodată reutilizat.
 * `Illuminate\Mail\Mailable::to()` (`setAddress()`) ADAUGĂ la lista de destinatari a
 * INSTANȚEI, nu o înlocuiește — trimiterea aceleiași instanțe de două ori ar aduna
 * Owner-ii unul peste altul (al doilea Owner ar primi un email adresat și lui, și
 * primului). `Mail::to($owner)` — modelul, NU adresa — e ce declanșează
 * `Illuminate\Mail\PendingMail::to()` să citească `$owner->preferredLocale()`
 * (`App\Models\User implements HasLocalePreference`); mecanismul nativ de rezolvare a
 * limbii NU funcționează pentru un array de adrese, doar pentru UN SINGUR model — de-asta
 * `Mail::to($recipients)` cu array-ul de mai jos (dinainte de acest lot) trimitea tuturor
 * Owner-ilor în ACEEAȘI limbă, oricare ar fi fost `users.locale` al fiecăruia.
 *
 * `DunningPaymentFailedMail` rămâne construit ÎNĂUNTRUL `TenantContext::run()` de mai jos
 * (neschimbat față de dinainte de acest lot, vezi paragraful de mai sus despre
 * `AttributesSentEmailToTenant`) — construit în AFARA lui, antetul
 * `X-Throughput-Tenant-Id` ar lipsi din jurnalul „Sent Emails". Bucla de trimitere rămâne
 * după ce `TenantContext::run()` a returnat (ADR-013).
 */
final class SendPaymentFailedDunningEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(StripeInvoicePaymentFailed $event): void
    {
        /** @var list<array{owner: User, mailable: DunningPaymentFailedMail}> $deliveries */
        $deliveries = TenantContext::run($event->tenantId, function () use ($event): array {
            $tenant = Tenant::query()->find($event->tenantId);

            if ($tenant === null) {
                return [];
            }

            $owners = TenantOwners::forTenant($event->tenantId);

            if ($owners->isEmpty()) {
                return [];
            }

            return $owners
                ->map(fn (User $owner): array => [
                    'owner' => $owner,
                    'mailable' => new DunningPaymentFailedMail(
                        tenantName: $tenant->name,
                        workspaceSlug: $tenant->slug,
                        attemptCount: $event->attemptCount,
                    ),
                ])
                ->all();
        });

        foreach ($deliveries as $delivery) {
            Mail::to($delivery['owner'])->send($delivery['mailable']);
        }
    }
}
