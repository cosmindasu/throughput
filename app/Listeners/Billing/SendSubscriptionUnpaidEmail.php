<?php

namespace App\Listeners\Billing;

use App\Mail\SubscriptionUnpaidMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Billing\Events\SubscriptionBecameUnpaid;
use App\Support\Billing\TenantOwners;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * specs.md §12.2 — email O SINGURĂ DATĂ, la tranziția `past_due → unpaid`. Structură
 * IDENTICĂ cu `SendPaymentFailedDunningEmail` (ADR-013/014 pct. 5): tranzacție scurtă
 * pentru citire + construcție a `Mailable`-ului, `Mail::send()` strict în afara ei.
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
 * `SubscriptionUnpaidMail` rămâne construit ÎNĂUNTRUL `TenantContext::run()` de mai jos
 * (neschimbat față de dinainte de acest lot): `App\Mail\Concerns\AttributesSentEmailToTenant`
 * captează tenantul curent LA CONSTRUCȚIE, cât timp contextul e încă activ — exact fixul
 * documentat pe `App\Jobs\Reports\DeliverReportJob` (P3, review). Construit în AFARA
 * `TenantContext::run()`, antetul `X-Throughput-Tenant-Id` ar lipsi din jurnalul „Sent
 * Emails". Apelul `Mail::send()` propriu-zis rămâne STRICT în afara tranzacției (ADR-013).
 */
final class SendSubscriptionUnpaidEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(SubscriptionBecameUnpaid $event): void
    {
        /** @var list<array{owner: User, mailable: SubscriptionUnpaidMail}> $deliveries */
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
                    'mailable' => new SubscriptionUnpaidMail(
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
