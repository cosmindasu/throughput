<?php

namespace App\Support\Billing;

use App\Enums\SubscriptionAccessLevel;
use App\Models\Tenant;
use Stripe\Subscription as StripeSubscription;

/**
 * BR-BILL-04 — „nivelul de acces (complet / read-only / blocat) se calculează într-un
 * singur loc, citind `stripe_status` direct din abonament — niciodată din `subscribed()`
 * singur, care nu distinge `past_due` de `unpaid`."
 *
 * CONTRAZICERE față de formularea din plan-implementare.md §11 („un singur loc care
 * citește `stripe_status` direct — `pastDue()`/`unpaid()`/`canceled()`"): Cashier 16.8
 * NU are un `Subscription::unpaid()`, iar `Subscription::canceled()` există dar înseamnă
 * cu totul altceva — `! is_null($this->ends_at)` (anulare VOLUNTARĂ cu zile plătite
 * rămase, exact mecanismul pe care specs.md §12.2 cere explicit să NU se bazeze nimic de
 * aici). Singura metodă din pachet care chiar citește `stripe_status` e `pastDue()`. De
 * aceea acest Policy compară `$subscription->stripe_status` DIRECT cu constantele
 * `Stripe\Subscription::STATUS_*`, fără să apeleze `pastDue()`/`canceled()` — ca să nu
 * amestece o metodă corectă cu două care ar părea corecte după nume, dar nu sunt.
 *
 * Fără abonament local (`subscriptions` gol — tenant nou, încă în trial înainte de primul
 * webhook `customer.subscription.created`) → acces COMPLET: lipsa unui rând nu e un eșec
 * de plată, e absența oricărei facturări încă.
 */
final class SubscriptionAccessPolicy
{
    public static function levelFor(Tenant $tenant): SubscriptionAccessLevel
    {
        $subscription = $tenant->subscription();

        if ($subscription === null) {
            return SubscriptionAccessLevel::Full;
        }

        return match ($subscription->stripe_status) {
            StripeSubscription::STATUS_UNPAID => SubscriptionAccessLevel::ReadOnly,
            StripeSubscription::STATUS_CANCELED => SubscriptionAccessLevel::Blocked,
            // `active`, `past_due`, `trialing`, `incomplete`, orice altceva netratat încă —
            // acces complet (BR-BILL-03 pentru `past_due` explicit; restul nu fac parte din
            // modelul de degradare pe 3 trepte al specs.md §12.2).
            default => SubscriptionAccessLevel::Full,
        };
    }
}
