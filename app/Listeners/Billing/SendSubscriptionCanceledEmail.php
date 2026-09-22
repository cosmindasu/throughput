<?php

namespace App\Listeners\Billing;

use App\Mail\SubscriptionCanceledMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Billing\Events\SubscriptionCanceled;
use App\Support\Billing\TenantOwners;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * specs.md §12.2/§20.5 — email „la tranziție" spre `canceled`. Structură IDENTICĂ cu
 * `SendPaymentFailedDunningEmail`/`SendSubscriptionUnpaidEmail`.
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
 * `SubscriptionCanceledMail` rămâne construit ÎNĂUNTRUL `TenantContext::run()` de mai jos
 * (neschimbat față de dinainte de acest lot): `App\Mail\Concerns\AttributesSentEmailToTenant`
 * captează tenantul curent LA CONSTRUCȚIE, cât timp contextul e încă activ — exact fixul
 * documentat pe `App\Jobs\Reports\DeliverReportJob` (P3, review). Construit în AFARA
 * `TenantContext::run()`, antetul `X-Throughput-Tenant-Id` ar lipsi din jurnalul „Sent
 * Emails" pentru toate cele trei email-uri de facturare. Apelul `Mail::send()` propriu-zis
 * rămâne STRICT în afara tranzacției (ADR-013) — de-asta bucla de trimitere e după ce
 * `TenantContext::run()` a returnat.
 */
final class SendSubscriptionCanceledEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(SubscriptionCanceled $event): void
    {
        /** @var list<array{owner: User, mailable: SubscriptionCanceledMail}> $deliveries */
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
                    'mailable' => new SubscriptionCanceledMail(
                        tenantName: $tenant->name,
                        workspaceSlug: $tenant->slug,
                    ),
                ])
                ->all();
        });

        foreach ($deliveries as $delivery) {
            Mail::to($delivery['owner'])->send($delivery['mailable']);
        }
    }
}
