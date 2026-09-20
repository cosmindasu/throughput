<?php

namespace App\Listeners\Billing;

use App\Mail\SubscriptionCanceledMail;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Billing\Events\SubscriptionCanceled;
use App\Support\Billing\TenantOwners;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * specs.md §12.2/§20.5 — email „la tranziție" spre `canceled`. Structură IDENTICĂ cu
 * `SendPaymentFailedDunningEmail`/`SendSubscriptionUnpaidEmail`.
 */
final class SendSubscriptionCanceledEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 30;

    public function handle(SubscriptionCanceled $event): void
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

            $mailable = new SubscriptionCanceledMail(
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
