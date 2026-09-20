<?php

namespace App\Listeners\Billing;

use App\Mail\SubscriptionUnpaidMail;
use App\Models\Tenant;
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
 */
final class SendSubscriptionUnpaidEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(SubscriptionBecameUnpaid $event): void
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

            $mailable = new SubscriptionUnpaidMail(
                tenantName: $tenant->name,
                workspaceSlug: $tenant->slug,
            );

            return [$mailable, $owners->pluck('email')->all()];
        });

        if ($mailable === null || $recipients === []) {
            return;
        }

        Mail::to($recipients)->send($mailable);
    }
}
