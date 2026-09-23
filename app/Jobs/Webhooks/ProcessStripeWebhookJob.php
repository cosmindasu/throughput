<?php

namespace App\Jobs\Webhooks;

use App\Jobs\Middleware\ApplyTenantContextToJob;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Support\Billing\Events\StripeInvoicePaymentFailed;
use App\Support\Billing\Events\SubscriptionBecameUnpaid;
use App\Support\Billing\Events\SubscriptionCanceled;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * §12.3, pct. 4 — „dispatch job în coadă pentru procesarea efectivă... job idempotent
 * (verifică starea curentă înainte de a aplica efectul, nu doar aplică orbește)".
 *
 * Job de TENANT (ADR-014 pct. 4): `tenantId` scalar, rezolvat de
 * `App\Http\Controllers\Webhooks\StripeWebhookController` ÎNAINTE de dispatch (nu aici) —
 * un `stripe_id` nemapat produce `webhook_events.status = failed` direct în controller,
 * fără să mai ajungă job.
 *
 * FĂRĂ I/O extern (spre deosebire de `GenerateShippingLabelJob`/`DeliverReportJob`):
 * ambele efecte tratate aici (sincronizarea abonamentului, dispecerizarea evenimentului de
 * dunning) sunt scrieri Postgres + o inserare în coada de joburi — `event()` peste un
 * listener `ShouldQueue` NU trimite email-ul, doar îl pune în coadă. De aceea întregul
 * `handle()` poate sta într-o SINGURĂ tranzacție prin `ApplyTenantContextToJob`, ca
 * `App\Jobs\Bulk\ProcessBulkChunkJob`.
 *
 * SCOP DELIBERAT ÎNGUSTAT — doar trei tipuri de eveniment, cele necesare modelului de
 * degradare pe 3 trepte (specs.md §12.2) și ferestrei de retenție (BR-BILL-05):
 * `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_failed`.
 * Restul evenimentelor Cashier tratează implicit (subscription.created, customer.updated,
 * payment_method.automatically_updated, invoice.payment_succeeded/.payment_action_required)
 * NU sunt replicate aici — vezi raportul lotului pentru motivare (checkout/plan-upgrade nu
 * face parte din acest lot) — sunt marcate `processed` fără efect, nu `failed`: un
 * eveniment necunoscut nu e o eroare.
 *
 * NOTĂ pentru cine implementează US-BILL-03 (checkout/schimbare de plan): handler-ul
 * original Cashier (`WebhookController::handleCustomerSubscriptionUpdated()`) ȘTERGE
 * abonamentul local când `status === incomplete_expired` (un abonament care n-a fost
 * niciodată confirmat cu succes la Checkout). Ramura lipsește AICI deliberat — acest lot nu
 * creează abonamente prin Checkout, deci `incomplete`/`incomplete_expired` nu apar azi în
 * `subscriptions` (efect nul, verificat). Dacă un lot viitor adaugă Checkout, ramura trebuie
 * adăugată — altfel un abonament niciodată confirmat rămâne etern `incomplete_expired` local.
 *
 * NOTĂ, review-ul lotului pct. 1 — dincolo de sincronizarea de status, `syncSubscription()`
 * declanșează și cele două emailuri „de tranziție" din tabelul §12.2 (`unpaid`/`canceled`),
 * cu ACEEAȘI disciplină de idempotență ca `subscription_canceled_at` (BR-BILL-05): comparare
 * cu statusul ANTERIOR scrierii curente, nu cu un tabel separat de „emailuri trimise".
 */
final class ProcessStripeWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $tenantId,
        public string $webhookEventId,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new ApplyTenantContextToJob];
    }

    public function handle(): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        // Idempotent — un eveniment deja PROCESAT cu succes nu mai aplică nimic a doua
        // oară, fie că a fost redelivrat de coadă (crash între commit și ack), fie
        // (teoretic) reintrat pe altă cale. `failed` NU e terminal aici, deliberat: un
        // eventual ecran de operare (§25.2, alt lot) poate redispecera manual un eveniment
        // eșuat după ce cauza a fost reparată, fără cod special de „retry".
        if ($event === null || $event->status === WebhookEvent::STATUS_PROCESSED) {
            return;
        }

        $event->update(['status' => WebhookEvent::STATUS_PROCESSING]);

        match ($event->type) {
            'customer.subscription.updated' => $this->syncSubscription($event),
            'customer.subscription.deleted' => $this->syncSubscription($event, deleted: true),
            'invoice.payment_failed' => $this->dispatchDunningNotice($event),
            default => null,
        };

        $event->update([
            'status' => WebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * §12.3, criteriul de acceptanță — la a treia încercare eșuată, `status = failed` cu
     * `error_message` populat, vizibil într-un ecran de operare (alt lot).
     */
    public function failed(Throwable $e): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->update([
            'status' => WebhookEvent::STATUS_FAILED,
            'error_message' => $e->getMessage(),
        ]);
    }

    /**
     * `customer.subscription.updated`/`.deleted` — SINGURUL loc care scrie
     * `subscriptions.stripe_status` local (Cashier nu mai are ocazia: ruta lui implicită
     * e dezactivată — `Cashier::ignoreRoutes()`, `AppServiceProvider`). Formă apropiată de
     * `Laravel\Cashier\Http\Controllers\WebhookController::handleCustomerSubscriptionUpdated()`,
     * dar FĂRĂ ramurile care ating alte fluxuri (checkout, `newSubscriptionType`) — acest
     * lot nu construiește crearea de-abonamente noi prin Checkout (US-BILL-03, în afara
     * bulletului primit).
     */
    private function syncSubscription(WebhookEvent $event, bool $deleted = false): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $data = $event->payload['data']['object'] ?? [];

        if (! isset($data['id'])) {
            return;
        }

        $subscription = $tenant->subscriptions()->firstOrNew(['stripe_id' => $data['id']]);
        $previousStatus = $subscription->stripe_status;

        $subscription->type = $subscription->type ?? ($data['metadata']['type'] ?? 'default');

        $items = $data['items']['data'] ?? [];
        if ($items !== []) {
            $isSinglePrice = count($items) === 1;
            $subscription->stripe_price = $isSinglePrice ? $items[0]['price']['id'] : null;
            $subscription->quantity = $isSinglePrice ? ($items[0]['quantity'] ?? null) : null;
        }

        if (array_key_exists('trial_end', $data)) {
            $subscription->trial_ends_at = $data['trial_end']
                ? Carbon::createFromTimestamp($data['trial_end'])
                : null;
        }

        if ($deleted) {
            $subscription->stripe_status = StripeSubscription::STATUS_CANCELED;
            $subscription->ends_at = $subscription->ends_at ?? now();
        } else {
            $subscription->stripe_status = $data['status'] ?? $subscription->stripe_status;

            if ($data['cancel_at_period_end'] ?? false) {
                $subscription->ends_at = ($data['current_period_end'] ?? null) !== null
                    ? Carbon::createFromTimestamp($data['current_period_end'])
                    : $subscription->trial_ends_at;
            } elseif (isset($data['cancel_at']) || isset($data['canceled_at'])) {
                $subscription->ends_at = Carbon::createFromTimestamp($data['cancel_at'] ?? $data['canceled_at']);
            } else {
                $subscription->ends_at = null;
            }
        }

        $subscription->save();

        // GDPR-01, ADR-012 („Implementare") — bug de reactivare: portalul Stripe poate
        // readuce un abonament la `active`/`trialing`, FĂRĂ nicio anulare programată
        // (`ends_at` calculat mai sus rămâne `null`), dar `subscription_canceled_at` scris
        // la o tranziție ANTERIOARĂ spre `canceled` nu se golea niciodată — premisa lui
        // ADR-012 („reactivarea în fereastră anulează numărătoarea celor 30 de zile") n-avea
        // cod. Fără acest reset, ancora locală ar minți despre starea reală a abonamentului
        // (garda proprie a `App\Jobs\System\PurgeCanceledTenantsJob`, care citește STAREA
        // Cashier, nu doar coloana, tot ar fi oprit o purjare greșită — dar sursa a doua de
        // adevăr nu scutește prima de la a fi corectă). `$deleted` exclude explicit
        // `customer.subscription.deleted`: acela nu e niciodată o reactivare.
        if (! $deleted
            && in_array($subscription->stripe_status, [StripeSubscription::STATUS_ACTIVE, StripeSubscription::STATUS_TRIALING], true)
            && $subscription->ends_at === null
            && $tenant->subscription_canceled_at !== null) {
            $tenant->forceFill(['subscription_canceled_at' => null])->save();
        }

        // BR-BILL-05 — ancora ferestrei de retenție de 30 de zile pornește EXACT la
        // tranziția SPRE `canceled`, nu la fiecare rescriere ulterioară a unui abonament
        // deja `canceled` (idempotență — un al doilea `customer.subscription.updated` pe
        // un abonament deja anulat NU împinge din nou `subscription_canceled_at`). ACELAȘI
        // `if` declanșează și emailul „la tranziție" (specs.md §12.2, review-ul lotului,
        // pct. 1): o singură scriere, o singură verificare, un singur eveniment — nu două
        // gărzi separate care ar putea diverge.
        if ($subscription->stripe_status === StripeSubscription::STATUS_CANCELED
            && $previousStatus !== StripeSubscription::STATUS_CANCELED) {
            if ($tenant->subscription_canceled_at === null) {
                $tenant->forceFill(['subscription_canceled_at' => now()])->save();
            }

            event(new SubscriptionCanceled(tenantId: $this->tenantId));
        }

        // specs.md §12.2, tabelul de degradare, rândul `unpaid` — email O SINGURĂ DATĂ, la
        // tranziția `past_due → unpaid` (nu la fiecare webhook care confirmă un `unpaid`
        // deja cunoscut local — `$previousStatus` citit ÎNAINTE de scrierea de mai sus e
        // exact garanția de „o singură dată" cerută, aceeași disciplină ca la `canceled`).
        if ($subscription->stripe_status === StripeSubscription::STATUS_UNPAID
            && $previousStatus !== StripeSubscription::STATUS_UNPAID) {
            event(new SubscriptionBecameUnpaid(tenantId: $this->tenantId));
        }
    }

    /**
     * FR-BILL-04 — doar dispecerizează evenimentul de domeniu; trimiterea efectivă e
     * responsabilitatea `App\Listeners\Billing\SendPaymentFailedDunningEmail`
     * (`ShouldQueue`), înregistrat explicit în `AppServiceProvider::register()`.
     */
    private function dispatchDunningNotice(WebhookEvent $event): void
    {
        $data = $event->payload['data']['object'] ?? [];

        event(new StripeInvoicePaymentFailed(
            tenantId: $this->tenantId,
            attemptCount: (int) ($data['attempt_count'] ?? 1),
            webhookEventId: $event->getKey(),
        ));
    }
}
